import { cp, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { resolve, sep } from "node:path";
import { pathToFileURL } from "node:url";
import {
  loadPublicReleaseProjection,
  PUBLIC_RELEASE_PROJECTION_KEY,
} from "./public-release-projection.mjs";

const BASE_PATH = "/download-center/";
const DEFAULT_OUTPUT_DIR = new URL("../static-dist/", import.meta.url);
const CLIENT_DIR = new URL("../dist/client/", import.meta.url);

function argument(name) {
  const prefix = `--${name}=`;
  return process.argv.slice(2).find((value) => value.startsWith(prefix))?.slice(prefix.length);
}

function directoryUrl(value) {
  return pathToFileURL(resolve(value) + sep);
}

const metadataPath = argument("metadata") ??
  new URL("../release-metadata.json", import.meta.url);
const pendingMetadata = JSON.parse(await readFile(metadataPath, "utf8"));
const finalManifestPath = argument("final-manifest") ??
  new URL(`../../releases/${pendingMetadata.versionName}/release-manifest.json`, import.meta.url);
const OUTPUT_DIR = argument("output-dir")
  ? directoryUrl(argument("output-dir"))
  : DEFAULT_OUTPUT_DIR;
const { publicRelease } = await loadPublicReleaseProjection(
  metadataPath,
  finalManifestPath,
);
const isFormalRelease = publicRelease !== null;

const sharedBrowserScript = `(() => {
  const healthContainers = Array.from(document.querySelectorAll("[data-service-health]"));
  const healthLabels = Array.from(document.querySelectorAll("[data-health-label]"));
  const healthDatabases = Array.from(document.querySelectorAll("[data-health-database]"));
  const healthTimes = Array.from(document.querySelectorAll("[data-health-time]"));
  const healthButtons = Array.from(document.querySelectorAll('[data-action="refresh-health"]'));
  let healthController = null;
  let healthSequence = 0;

  function checkTime() {
    return new Intl.DateTimeFormat("zh-CN", {
      hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
    }).format(new Date());
  }

  function updateHealth(state, label, database, checkedAt) {
    healthContainers.forEach((container) => container.dataset.healthState = state);
    healthLabels.forEach((element) => element.textContent = label);
    healthDatabases.forEach((element) => element.textContent = database);
    healthTimes.forEach((element) => element.textContent = checkedAt);
    healthButtons.forEach((button) => {
      button.disabled = state === "checking";
      button.setAttribute("aria-busy", String(state === "checking"));
    });
  }

  function healthyPayload(value) {
    return value && typeof value === "object" && value.code === 1
      && value.data && value.data.status === "ok" && value.data.database === "connected";
  }

  async function refreshHealth() {
    const sequence = ++healthSequence;
    if (healthController) healthController.abort();
    healthController = new AbortController();
    const controller = healthController;
    updateHealth("checking", "正在检测", "等待检测", "正在连接同源公开接口");
    const timeout = window.setTimeout(() => controller.abort(), 5000);
    try {
      const response = await fetch("/api/health", {
        method: "GET",
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
        signal: controller.signal,
      });
      const payload = await response.json();
      if (!response.ok || !healthyPayload(payload)) throw new Error("public_health_not_ready");
      if (sequence !== healthSequence) return;
      updateHealth("operational", "服务正常", "已连接", checkTime() + " 更新");
    } catch {
      if (sequence !== healthSequence) return;
      const offline = !navigator.onLine;
      updateHealth(
        offline ? "offline" : "degraded",
        offline ? "网络已断开" : "暂时无法确认",
        "无法确认",
        checkTime() + " 检测",
      );
    } finally {
      window.clearTimeout(timeout);
      if (sequence === healthSequence) healthController = null;
    }
  }

  function enableReveals() {
    const targets = Array.from(document.querySelectorAll("[data-reveal]"));
    if (!targets.length) return;
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches || !("IntersectionObserver" in window)) {
      targets.forEach((target) => target.classList.add("is-visible"));
      return;
    }
    document.documentElement.classList.add("reveal-ready");
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("is-visible");
        observer.unobserve(entry.target);
      });
    }, { rootMargin: "0px 0px -10%", threshold: 0.08 });
    targets.forEach((target) => observer.observe(target));
  }

  healthButtons.forEach((button) => button.addEventListener("click", refreshHealth));
  window.addEventListener("online", refreshHealth);
  window.addEventListener("offline", () => {
    healthSequence += 1;
    if (healthController) healthController.abort();
    healthController = null;
    updateHealth("offline", "网络已断开", "无法确认", checkTime() + " 检测");
  });
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden) refreshHealth();
  });
  window.setInterval(() => {
    if (!document.hidden) refreshHealth();
  }, 60000);
  enableReveals();
  refreshHealth();
})();`;

const formalBrowserScript = `(() => {
  const publicRelease = ${JSON.stringify(publicRelease)};
  const releases = publicRelease?.releases || [];
  const downloadRoot = publicRelease
    ? publicRelease.downloadRootBase + "/" + publicRelease.versionName
    : "";
  const roleButtons = Array.from(document.querySelectorAll(".public-role-tabs button"));
  const releaseName = document.querySelector(".selected-product strong");
  const releaseDescription = document.querySelector(".selected-product p");
  const fileSize = document.querySelector(".selected-product > b");
  const downloadButton = document.querySelector(".download-button");
  const downloadButtonSize = document.querySelector(".download-button span");
  const selectedDownload = document.querySelector(".selected-download");
  const verificationCodes = document.querySelectorAll(".file-verification code");
  const verificationButtons = document.querySelectorAll(".file-verification button");
  const releaseFileReferences = document.querySelectorAll("[data-release-file]");
  const deviceNote = document.querySelector(".device-note");
  const shareHomeButton = document.querySelector('[data-action="share-home"]');
  const toast = document.querySelector(".toast");
  let current = releases[0];
  let toastTimer = 0;

  function showToast(message) {
    if (!toast) return;
    window.clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add("is-visible");
    toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 2400);
  }

  async function copyText(value) {
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(value);
      } else {
        const input = document.createElement("textarea");
        input.value = value;
        input.style.position = "fixed";
        input.style.opacity = "0";
        document.body.appendChild(input);
        input.select();
        const copied = document.execCommand("copy");
        input.remove();
        if (!copied) throw new Error("copy_failed");
      }
      showToast("已复制到剪贴板");
      return true;
    } catch {
      showToast("复制失败，请长按内容复制");
      return false;
    }
  }

  async function shareHome() {
    const url = new URL(window.location.href);
    url.search = "";
    url.hash = "";
    try {
      if (navigator.share) {
        try {
          await navigator.share({
            title: "易云盈｜软件授权与运营平台",
            text: "易云盈正式官网、接口文档与四角色客户端下载",
            url: url.toString(),
          });
          showToast("系统分享面板已打开，官网链接可直接访问");
          return;
        } catch (error) {
          if (error instanceof DOMException && error.name === "AbortError") {
            showToast("已取消分享，页面内容未更改");
            return;
          }
        }
      }
      const copied = await copyText(url.toString());
      if (copied) showToast("规范官网链接已复制，可直接粘贴分享");
      else showToast("分享失败，请从地址栏手动复制官网链接");
    } catch {
      showToast("分享失败，请从地址栏手动复制官网链接");
    }
  }

  function currentDownloadUrl() {
    if (!current) return "";
    return downloadRoot + "/" + current.fileName
      + "?sha256=" + current.sha256.slice(0, 16).toLowerCase();
  }

  function selectRelease(index) {
    current = releases[index] || releases[0];
    if (!current) return;
    roleButtons.forEach((button, buttonIndex) => {
      const selected = buttonIndex === index;
      button.classList.toggle("is-active", selected);
      button.setAttribute("aria-selected", String(selected));
    });
    if (releaseName) releaseName.textContent = current.name;
    if (releaseDescription) releaseDescription.textContent = current.description;
    if (fileSize) fileSize.textContent = current.size;
    if (downloadButtonSize) downloadButtonSize.textContent = current.size;
    if (downloadButton) {
      downloadButton.href = currentDownloadUrl();
      downloadButton.download = current.fileName;
    }
    if (verificationCodes[0]) verificationCodes[0].textContent = current.fileName;
    if (verificationCodes[1]) {
      verificationCodes[1].textContent = current.sha256;
      verificationCodes[1].title = current.sha256;
    }
    releaseFileReferences.forEach((reference) => {
      reference.textContent = current.fileName;
    });
    if (selectedDownload) {
      selectedDownload.classList.remove("is-switching");
      void selectedDownload.offsetWidth;
      selectedDownload.classList.add("is-switching");
    }
  }

  roleButtons.forEach((button, index) => {
    button.addEventListener("click", () => selectRelease(index));
  });
  verificationButtons[0]?.addEventListener("click", () => current && copyText(current.fileName));
  verificationButtons[1]?.addEventListener("click", () => current && copyText(current.sha256));
  downloadButton?.addEventListener("click", () => {
    if (current) showToast(current.name + "已开始下载");
  });
  shareHomeButton?.addEventListener("click", shareHome);
  if (deviceNote) {
    deviceNote.lastChild.textContent = /Android/i.test(navigator.userAgent)
      ? "已识别为 Android 设备，可直接下载后安装。"
      : "请在 Android 设备安装，也可先下载后传送到手机。";
  }
  selectRelease(0);
})();`;

const pendingBrowserScript = `(() => {
  const shareHomeButton = document.querySelector('[data-action="share-home"]');
  const toast = document.querySelector(".toast");
  let toastTimer = 0;

  function showToast(message) {
    if (!toast) return;
    window.clearTimeout(toastTimer);
    toast.textContent = message;
    toast.classList.add("is-visible");
    toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 2400);
  }

  async function copyWebsite(value) {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const input = document.createElement("textarea");
    input.value = value;
    input.setAttribute("readonly", "");
    input.style.position = "fixed";
    input.style.opacity = "0";
    document.body.appendChild(input);
    input.select();
    const copied = document.execCommand("copy");
    input.remove();
    if (!copied) throw new Error("copy_failed");
  }

  shareHomeButton?.addEventListener("click", async () => {
    const url = new URL(window.location.href);
    url.search = "";
    url.hash = "";
    try {
      if (navigator.share) {
        try {
          await navigator.share({
            title: "易云盈｜软件授权与运营平台",
            text: "易云盈正式官网与接口文档",
            url: url.toString(),
          });
          showToast("系统分享面板已打开，官网链接可直接访问");
          return;
        } catch (error) {
          if (error instanceof DOMException && error.name === "AbortError") {
            showToast("已取消分享，页面内容未更改");
            return;
          }
        }
      }
      await copyWebsite(url.toString());
      showToast("规范官网链接已复制，可直接粘贴分享");
    } catch {
      showToast("分享失败，请从地址栏手动复制官网链接");
    }
  });
})();`;

const browserScript = sharedBrowserScript + "\n" + (isFormalRelease
  ? formalBrowserScript
  : pendingBrowserScript);

const docsBrowserScript = `(() => {
  const SAFE_BASE_URL = "https://api.example.com";

  function statusFor(element) {
    if (!element || typeof element.closest !== "function") return null;
    return element.closest(".section-actions, .inline-copy-action, .interactive-code-example, .docs-actions-bar")
      ?.querySelector(".action-status");
  }

  function showStatus(element, message) {
    const status = statusFor(element);
    if (!status) return;
    status.textContent = message;
    window.setTimeout(() => {
      if (status.textContent === message) status.textContent = "";
    }, 2600);
  }

  async function copyText(value) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const input = document.createElement("textarea");
    input.value = value;
    input.setAttribute("readonly", "");
    input.style.position = "fixed";
    input.style.opacity = "0";
    document.body.appendChild(input);
    input.select();
    const copied = document.execCommand("copy");
    input.remove();
    if (!copied) throw new Error("copy_failed");
  }

  function canonicalUrl(targetId) {
    const url = new URL(window.location.href);
    url.search = "";
    if (targetId !== undefined) url.hash = targetId ? "#" + targetId : "";
    return url.toString();
  }

  async function shareOrCopy(title, targetId) {
    const url = canonicalUrl(targetId);
    if (navigator.share) {
      try {
        await navigator.share({ title, text: title + "｜易云盈接口文档", url });
        return "系统分享面板已打开，链接可直接访问";
      } catch (error) {
        if (error instanceof DOMException && error.name === "AbortError") return "已取消分享，页面内容未更改";
      }
    }
    await copyText(url);
    return "规范链接已复制，可直接粘贴分享";
  }

  function parseRequest(raw) {
    const curlMatch = raw.match(/curl\\s+--request\\s+(GET|POST|PUT|DELETE)\\s+['\"]https:\\/\\/api\\.example\\.com([^'\"]+)['\"]/i);
    const lineMatch = raw.match(/(?:^|\\n)(GET|POST|PUT|DELETE)\\s+(\\/api\\/[^\\s；]+)/i);
    const method = (curlMatch?.[1] || lineMatch?.[1] || "GET").toUpperCase();
    const path = curlMatch?.[2] || lineMatch?.[2] || "/api/user/me";
    const curlBody = raw.match(/--data\\s+'([^']+)'/s)?.[1];
    const lineBody = raw.match(/(?:^|\\n)(\\{[^\\n]+\\})(?:\\n|$)/)?.[1];
    return {
      method, path, body: curlBody || lineBody || "",
      needsBearer: /Bearer|登录后授权/i.test(raw),
      needsAppKey: /X-App-Key|app_key/i.test(raw),
    };
  }

  function makeSnippet(raw, format) {
    const request = parseRequest(raw);
    const url = SAFE_BASE_URL + request.path;
    const headers = [];
    if (request.body) headers.push(["Content-Type", "application/json"]);
    if (request.needsAppKey) headers.push(["X-App-Key", "APP_API_UNIQUE_ID"]);
    if (request.needsBearer) headers.push(["Authorization", "Bearer ACCESS_TOKEN_PLACEHOLDER"]);
    if (format === "java") {
      const publisher = request.body
        ? "HttpRequest.BodyPublishers.ofString(" + JSON.stringify(request.body) + ")"
        : "HttpRequest.BodyPublishers.noBody()";
      const lines = [
        "HttpRequest.Builder requestBuilder = HttpRequest.newBuilder()",
        "    .uri(URI.create(" + JSON.stringify(url) + "))",
        "    .method(" + JSON.stringify(request.method) + ", " + publisher + ");",
      ];
      headers.forEach(([name, value]) => lines.push("requestBuilder.header(" + JSON.stringify(name) + ", " + JSON.stringify(value) + ");"));
      lines.push("HttpResponse<String> response = HttpClient.newHttpClient().send(", "    requestBuilder.build(), HttpResponse.BodyHandlers.ofString());");
      return lines.join("\\n");
    }
    if (format === "javascript") {
      let parsedBody;
      if (request.body) {
        try { parsedBody = JSON.parse(request.body); } catch { parsedBody = request.body; }
      }
      const options = ["  method: " + JSON.stringify(request.method) + ",", "  headers: " + JSON.stringify(Object.fromEntries(headers), null, 2).replace(/\\n/g, "\\n  ") + ","];
      if (request.body) options.push("  body: JSON.stringify(" + JSON.stringify(parsedBody, null, 2).replace(/\\n/g, "\\n  ") + "),");
      return ["const response = await fetch(" + JSON.stringify(url) + ", {", ...options, "});", "const result = await response.json();", "if (!response.ok) throw new Error(result.msg || ('HTTP ' + response.status));"].join("\\n");
    }
    const lines = ["curl --request " + request.method + " '" + url + "'"];
    headers.forEach(([name, value]) => lines.push("  --header '" + name + ": " + value + "'"));
    if (request.body) lines.push("  --data '" + request.body + "'");
    return lines.join(" \\\\\\n");
  }

  document.querySelectorAll('[data-action="share-docs"]').forEach((button) => button.addEventListener("click", async () => {
    try { showStatus(button, await shareOrCopy("易云盈接口文档")); }
    catch { showStatus(button, "分享失败，请从地址栏手动复制当前页面链接"); }
  }));
  document.querySelectorAll('[data-action="print-docs"]').forEach((button) => button.addEventListener("click", () => {
    document.documentElement.classList.remove("print-current-system");
    document.querySelectorAll(".is-print-target").forEach((target) => target.classList.remove("is-print-target"));
    showStatus(button, "已打开全部接口文档的打印预览");
    window.print();
  }));
  document.querySelectorAll('[data-action="share-section"]').forEach((button) => button.addEventListener("click", async () => {
    try { showStatus(button, await shareOrCopy(button.dataset.targetTitle || "接口系统", button.dataset.targetId || "")); }
    catch { showStatus(button, "复制失败，请从地址栏手动复制当前锚点链接"); }
  }));
  document.querySelectorAll('[data-action="print-section"]').forEach((button) => button.addEventListener("click", () => {
    const target = document.getElementById(button.dataset.targetId || "");
    if (!target) return showStatus(button, "未找到当前系统，请刷新页面后重试");
    document.querySelectorAll(".is-print-target").forEach((item) => item.classList.remove("is-print-target"));
    target.classList.add("is-print-target");
    document.documentElement.classList.add("print-current-system");
    const cleanup = () => {
      target.classList.remove("is-print-target");
      document.documentElement.classList.remove("print-current-system");
    };
    window.addEventListener("afterprint", cleanup, { once: true });
    window.setTimeout(cleanup, 5000);
    showStatus(button, "已打开当前系统打印预览");
    window.print();
  }));
  document.querySelectorAll('[data-action="copy-text"]').forEach((button) => button.addEventListener("click", async () => {
    try { await copyText(button.dataset.copyValue || ""); showStatus(button, "已复制，可粘贴到开发工具中"); }
    catch { showStatus(button, "复制失败，请长按或选中文本手动复制"); }
  }));
  document.querySelectorAll(".interactive-code-example").forEach((example) => {
    const raw = example.dataset.rawExample || "";
    const panel = example.querySelector('[role="tabpanel"] code');
    const tabs = Array.from(example.querySelectorAll("[data-code-format]"));
    let format = "curl";
    function select(next) {
      format = next;
      tabs.forEach((tab) => {
        const selected = tab.dataset.codeFormat === format;
        tab.classList.toggle("is-active", selected);
        tab.setAttribute("aria-selected", String(selected));
      });
      if (panel) panel.textContent = makeSnippet(raw, format);
    }
    tabs.forEach((tab) => tab.addEventListener("click", () => select(tab.dataset.codeFormat || "curl")));
    example.querySelector('[data-action="copy-code"]')?.addEventListener("click", async (event) => {
      const button = event.currentTarget;
      try { await copyText(panel?.textContent || ""); showStatus(button, "代码示例已复制"); }
      catch { showStatus(button, "复制失败，请长按或选中代码手动复制"); }
    });
    select("curl");
  });
})();`;

async function renderPage(pathname) {
  const requestPath = pathname === "/" ? pathname : pathname.replace(/\/$/, "");
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set(
    "export",
    `${requestPath}-${process.pid}-${Date.now()}`,
  );
  const { default: worker } = await import(workerUrl.href);
  const response = await worker.fetch(
    new Request(`http://localhost${requestPath}`, {
      headers: { accept: "text/html" },
    }),
    {
      ASSETS: {
        fetch: async () => new Response("Not found", { status: 404 }),
      },
    },
    {
      waitUntil() {},
      passThroughOnException() {},
    },
  );

  if (!response.ok) {
    throw new Error(
      `Static render of ${requestPath} failed with HTTP ${response.status}`,
    );
  }
  return response.text();
}

function rewriteAssetPaths(html) {
  return html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, "")
    .replace(/<link\b(?=[^>]*\brel=["']modulepreload["'])[^>]*>/gi, "")
    .replace(/(["'])\/assets\//g, `$1${BASE_PATH}assets/`)
    .replace(/(["'])\/logo\.svg/g, `$1${BASE_PATH}logo.svg`)
    .replace(/(["'])\/site\.webmanifest/g, `$1${BASE_PATH}site.webmanifest`)
    .replace(/(["'])\/api-docs\//g, `$1${BASE_PATH}api-docs/`)
    .replace(/(["'])\/privacy\//g, `$1${BASE_PATH}privacy/`)
    .replace(/(["'])\/terms\//g, `$1${BASE_PATH}terms/`)
    .replace(/(href=["'])\/(?=["'])/g, `$1${BASE_PATH}`);
}

await rm(OUTPUT_DIR, { recursive: true, force: true });
await mkdir(OUTPUT_DIR, { recursive: true });
await cp(CLIENT_DIR, OUTPUT_DIR, {
  recursive: true,
  filter: (source) => !source.includes(`${String.raw`\.vite`}`),
});

if (publicRelease) {
  globalThis[PUBLIC_RELEASE_PROJECTION_KEY] = publicRelease;
}
try {
  let homeHtml = rewriteAssetPaths(await renderPage("/"));
  homeHtml = homeHtml.replace(
    "</body>",
    `<script src="${BASE_PATH}site.js" defer></script></body>`,
  );
  await writeFile(new URL("index.html", OUTPUT_DIR), homeHtml, "utf8");
  await writeFile(new URL("site.js", OUTPUT_DIR), browserScript, "utf8");

  for (const pathname of ["/api-docs/", "/privacy/", "/terms/"]) {
    const pageDirectory = new URL(pathname.slice(1), OUTPUT_DIR);
    await mkdir(pageDirectory, { recursive: true });
    let pageHtml = rewriteAssetPaths(await renderPage(pathname));
    if (pathname === "/api-docs/") {
      pageHtml = pageHtml.replace(
        "</body>",
        `<script src="${BASE_PATH}docs.js" defer></script></body>`,
      );
    }
    await writeFile(new URL("index.html", pageDirectory), pageHtml, "utf8");
  }
  await writeFile(new URL("docs.js", OUTPUT_DIR), docsBrowserScript, "utf8");
} finally {
  delete globalThis[PUBLIC_RELEASE_PROJECTION_KEY];
}

console.log(`Static official site exported to ${OUTPUT_DIR.pathname}`);
