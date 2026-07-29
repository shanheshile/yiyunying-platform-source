import { cp, mkdir, readFile, rm, writeFile } from "node:fs/promises";

const BASE_PATH = "/download-center/";
const OUTPUT_DIR = new URL("../static-dist/", import.meta.url);
const CLIENT_DIR = new URL("../dist/client/", import.meta.url);
const releaseMetadata = JSON.parse(
  await readFile(new URL("../release-metadata.json", import.meta.url), "utf8"),
);
const releases = releaseMetadata.releases;

const browserScript = `(() => {
  const releases = ${JSON.stringify(releases)};
  const version = ${JSON.stringify(releaseMetadata.versionName)};
  const downloadRoot = ${JSON.stringify(releaseMetadata.downloadRootBase)} + "/" + version;
  const roleButtons = Array.from(document.querySelectorAll(".role-selector button"));
  const releaseIcon = document.querySelector(".selected-release .release-icon");
  const releaseName = document.querySelector(".release-title strong");
  const releaseDescription = document.querySelector(".release-title > span");
  const fileSize = document.querySelector(".file-size");
  const downloadButton = document.querySelector(".primary-download");
  const verificationCodes = document.querySelectorAll(".verification code");
  const verificationButtons = document.querySelectorAll(".verification button");
  const copyLinkButton = document.querySelector(".copy-link");
  const visualName = document.querySelector(".visual-body > p");
  const deviceHint = document.querySelector(".device-hint");
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
        document.execCommand("copy");
        input.remove();
      }
      showToast("已复制到剪贴板");
    } catch {
      showToast("复制失败，请长按内容复制");
    }
  }

  function selectRelease(index) {
    current = releases[index] || releases[0];
    roleButtons.forEach((button, buttonIndex) => {
      const selected = buttonIndex === index;
      button.classList.toggle("is-active", selected);
      button.setAttribute("aria-pressed", String(selected));
    });
    if (releaseIcon) releaseIcon.className = "release-icon " + current.accent;
    if (releaseName) releaseName.textContent = current.name;
    if (releaseDescription) releaseDescription.textContent = current.description;
    if (fileSize) fileSize.textContent = current.size;
    if (downloadButton) {
      downloadButton.href = downloadRoot + "/" + current.fileName
        + "?sha256=" + current.sha256.slice(0, 16).toLowerCase();
      downloadButton.download = current.fileName;
    }
    if (verificationCodes[0]) verificationCodes[0].textContent = current.fileName;
    if (verificationCodes[1]) {
      verificationCodes[1].textContent = current.sha256;
      verificationCodes[1].title = current.sha256;
    }
    if (visualName) visualName.textContent = current.shortName;
  }

  roleButtons.forEach((button, index) => {
    button.addEventListener("click", () => selectRelease(index));
  });
  verificationButtons[0]?.addEventListener("click", () => copyText(current.fileName));
  verificationButtons[1]?.addEventListener("click", () => copyText(current.sha256));
  copyLinkButton?.addEventListener("click", () => {
    copyText(downloadRoot + "/" + current.fileName
      + "?sha256=" + current.sha256.slice(0, 16).toLowerCase());
  });
  downloadButton?.addEventListener("click", () => {
    showToast(current.name + "已开始下载");
  });
  if (deviceHint) {
    deviceHint.lastChild.textContent = /Android/i.test(navigator.userAgent)
      ? "已识别为 Android 设备，可直接下载并安装"
      : "请使用 Android 设备安装，也可先下载后传到手机";
  }
  selectRelease(0);
})();`;

async function renderPage() {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("export", `${process.pid}-${Date.now()}`);
  const { default: worker } = await import(workerUrl.href);
  const response = await worker.fetch(
    new Request("http://localhost/", {
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
    throw new Error(`Static render failed with HTTP ${response.status}`);
  }
  return response.text();
}

await rm(OUTPUT_DIR, { recursive: true, force: true });
await mkdir(OUTPUT_DIR, { recursive: true });
await cp(CLIENT_DIR, OUTPUT_DIR, {
  recursive: true,
  filter: (source) => !source.includes(`${String.raw`\.vite`}`),
});

let html = await renderPage();
html = html
  .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, "")
  .replace(/<link\b(?=[^>]*\brel=["']modulepreload["'])[^>]*>/gi, "")
  .replace(/(["'])\/assets\//g, `$1${BASE_PATH}assets/`)
  .replace(/(["'])\/logo\.svg/g, `$1${BASE_PATH}logo.svg`)
  .replace(/(["'])\/site\.webmanifest/g, `$1${BASE_PATH}site.webmanifest`)
  .replace(
    "</body>",
    `<script src="${BASE_PATH}site.js" defer></script></body>`,
  );

await writeFile(new URL("index.html", OUTPUT_DIR), html, "utf8");
await writeFile(new URL("site.js", OUTPUT_DIR), browserScript, "utf8");

console.log(`Static download center exported to ${OUTPUT_DIR.pathname}`);
