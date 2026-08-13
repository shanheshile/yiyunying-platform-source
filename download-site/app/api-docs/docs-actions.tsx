"use client";

import { Check, Clipboard, ExternalLink, Printer, Share2 } from "lucide-react";
import { useEffect, useId, useMemo, useState } from "react";

type CodeFormat = "curl" | "java" | "javascript";

const CODE_FORMATS: ReadonlyArray<{ id: CodeFormat; label: string }> = [
  { id: "curl", label: "cURL" },
  { id: "java", label: "Java" },
  { id: "javascript", label: "JavaScript" },
];

const SAFE_BASE_URL = "https://api.example.com";

async function copyToClipboard(value: string) {
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

function canonicalUrl(targetId?: string) {
  const url = new URL(window.location.href);
  url.search = "";
  if (targetId !== undefined) url.hash = targetId ? `#${targetId}` : "";
  return url.toString();
}

async function shareOrCopy(title: string, targetId?: string) {
  const url = canonicalUrl(targetId);
  if (navigator.share) {
    try {
      await navigator.share({ title, text: `${title}｜易云盈接口文档`, url });
      return "系统分享面板已打开，链接可直接访问";
    } catch (error) {
      if (error instanceof DOMException && error.name === "AbortError") {
        return "已取消分享，页面内容未更改";
      }
    }
  }

  await copyToClipboard(url);
  return "规范链接已复制，可直接粘贴分享";
}

function useTemporaryStatus(timeout = 2600) {
  const [status, setStatus] = useState("");
  useEffect(() => {
    if (!status) return;
    const timer = window.setTimeout(() => setStatus(""), timeout);
    return () => window.clearTimeout(timer);
  }, [status, timeout]);
  return [status, setStatus] as const;
}

function parseRequest(raw: string) {
  const curlMatch = raw.match(/curl\s+--request\s+(GET|POST|PUT|DELETE)\s+['"]https:\/\/api\.example\.com([^'"]+)['"]/i);
  const lineMatch = raw.match(/(?:^|\n)(GET|POST|PUT|DELETE)\s+(\/api\/[^\s；]+)/i);
  const method = (curlMatch?.[1] ?? lineMatch?.[1] ?? "GET").toUpperCase();
  const path = curlMatch?.[2] ?? lineMatch?.[2] ?? "/api/user/me";
  const curlBody = raw.match(/--data\s+'([^']+)'/s)?.[1];
  const lineBody = raw.match(/(?:^|\n)(\{[^\n]+\})(?:\n|$)/)?.[1];
  const body = curlBody ?? lineBody ?? "";
  const needsBearer = /Bearer|登录后授权/i.test(raw);
  const needsAppKey = /X-App-Key|app_key/i.test(raw);
  return { method, path, body, needsBearer, needsAppKey };
}

function closureNotes(raw: string) {
  return raw
    .split(/\r?\n/)
    .filter((line) => /^(?:→|GET |POST |PUT |DELETE )/.test(line.trim()))
    .slice(1)
    .join("\n");
}

function makeSnippet(raw: string, format: CodeFormat) {
  const request = parseRequest(raw);
  const url = `${SAFE_BASE_URL}${request.path}`;
  const notes = closureNotes(raw);
  const headers = [
    request.body ? ["Content-Type", "application/json"] : null,
    request.needsAppKey ? ["X-App-Key", "APP_API_UNIQUE_ID"] : null,
    request.needsBearer ? ["Authorization", "Bearer ACCESS_TOKEN_PLACEHOLDER"] : null,
  ].filter((entry): entry is string[] => Boolean(entry));

  if (format === "curl") {
    const lines = [`curl --request ${request.method} '${url}'`];
    for (const [name, value] of headers) lines.push(`  --header '${name}: ${value}'`);
    if (request.body) lines.push(`  --data '${request.body}'`);
    if (notes) lines.push("", "# 闭环回读：", ...notes.split("\n").map((line) => `# ${line}`));
    return lines.join(" \\\n").replace(/ \\\n\n#/, "\n\n#").replace(/ \\\n# /g, "\n# ");
  }

  if (format === "java") {
    const bodyPublisher = request.body
      ? `HttpRequest.BodyPublishers.ofString(${JSON.stringify(request.body)})`
      : "HttpRequest.BodyPublishers.noBody()";
    const lines = [
      `HttpRequest.Builder requestBuilder = HttpRequest.newBuilder()`,
      `    .uri(URI.create(${JSON.stringify(url)}))`,
      `    .method(${JSON.stringify(request.method)}, ${bodyPublisher});`,
    ];
    for (const [name, value] of headers) {
      lines.push(`requestBuilder.header(${JSON.stringify(name)}, ${JSON.stringify(value)});`);
    }
    lines.push(
      "HttpResponse<String> response = HttpClient.newHttpClient().send(",
      "    requestBuilder.build(), HttpResponse.BodyHandlers.ofString());",
    );
    if (notes) lines.push("", "/* 闭环回读：", notes, "*/");
    return lines.join("\n");
  }

  let parsedBody: unknown = undefined;
  if (request.body) {
    try {
      parsedBody = JSON.parse(request.body);
    } catch {
      parsedBody = request.body;
    }
  }
  const headerObject = Object.fromEntries(headers);
  const options = [
    `  method: ${JSON.stringify(request.method)},`,
    `  headers: ${JSON.stringify(headerObject, null, 2).replace(/\n/g, "\n  ")},`,
  ];
  if (request.body) options.push(`  body: JSON.stringify(${JSON.stringify(parsedBody, null, 2).replace(/\n/g, "\n  ")}),`);
  const lines = [
    `const response = await fetch(${JSON.stringify(url)}, {`,
    ...options,
    "});",
    "const result = await response.json();",
    "if (!response.ok) throw new Error(result.msg ?? `HTTP ${response.status}`);",
  ];
  if (notes) lines.push("", "/* 闭环回读：", notes, "*/");
  return lines.join("\n");
}

export function DocsPageActions() {
  const [status, setStatus] = useTemporaryStatus();

  async function handleShare() {
    try {
      setStatus(await shareOrCopy("易云盈接口文档"));
    } catch {
      setStatus("分享失败，请从地址栏手动复制当前页面链接");
    }
  }

  function handlePrint() {
    document.documentElement.classList.remove("print-current-system");
    document.querySelectorAll(".is-print-target").forEach((element) => element.classList.remove("is-print-target"));
    setStatus("已打开全部接口文档的打印预览");
    window.print();
  }

  return (
    <div className="docs-actions-bar" aria-label="接口文档操作">
      <span className="public-link-status"><Check aria-hidden="true" />公开文档链接可访问，无需登录</span>
      <div className="docs-actions-buttons">
        <button type="button" data-action="share-docs" onClick={handleShare} aria-label="分享当前接口文档">
          <Share2 aria-hidden="true" />分享当前接口
        </button>
        <button type="button" data-action="print-docs" onClick={handlePrint} aria-label="打印全部接口文档">
          <Printer aria-hidden="true" />打印全部文档
        </button>
      </div>
      <span className="action-status" role="status" aria-live="polite" aria-atomic="true">{status}</span>
    </div>
  );
}

export function SectionActions({ targetId, title }: { targetId: string; title: string }) {
  const [status, setStatus] = useTemporaryStatus();

  async function handleShare() {
    try {
      setStatus(await shareOrCopy(title, targetId));
    } catch {
      setStatus("复制失败，请从地址栏手动复制当前锚点链接");
    }
  }

  function handlePrint() {
    const target = document.getElementById(targetId);
    if (!target) {
      setStatus("未找到当前系统，请刷新页面后重试");
      return;
    }
    document.querySelectorAll(".is-print-target").forEach((element) => element.classList.remove("is-print-target"));
    target.classList.add("is-print-target");
    document.documentElement.classList.add("print-current-system");
    const cleanup = () => {
      target.classList.remove("is-print-target");
      document.documentElement.classList.remove("print-current-system");
    };
    window.addEventListener("afterprint", cleanup, { once: true });
    window.setTimeout(cleanup, 5000);
    setStatus(`已打开“${title}”打印预览`);
    window.print();
  }

  return (
    <div className="section-actions" aria-label={`${title}操作`}>
      <button type="button" data-action="share-section" data-target-id={targetId} data-target-title={title} onClick={handleShare} aria-label={`分享${title}锚点链接`}>
        <Share2 aria-hidden="true" />分享本系统
      </button>
      <button type="button" data-action="print-section" data-target-id={targetId} data-target-title={title} onClick={handlePrint} aria-label={`打印${title}`}>
        <Printer aria-hidden="true" />打印当前系统
      </button>
      <a href={`#${targetId}`} target="_blank" rel="noreferrer" aria-label={`在新窗口打开${title}示例锚点`}>
        <ExternalLink aria-hidden="true" />新窗口打开示例
      </a>
      <span className="action-status" role="status" aria-live="polite" aria-atomic="true">{status}</span>
    </div>
  );
}

export function CopyTextButton({ value, label }: { value: string; label: string }) {
  const [status, setStatus] = useTemporaryStatus();
  async function handleCopy() {
    try {
      await copyToClipboard(value);
      setStatus("已复制，可粘贴到开发工具中");
    } catch {
      setStatus("复制失败，请长按或选中文本手动复制");
    }
  }
  return (
    <span className="inline-copy-action">
      <button type="button" data-action="copy-text" data-copy-value={value} onClick={handleCopy} aria-label={label}><Clipboard aria-hidden="true" />一键复制</button>
      <span className="action-status" role="status" aria-live="polite" aria-atomic="true">{status}</span>
    </span>
  );
}

export function CodeExample({ raw, label }: { raw: string; label: string }) {
  const [format, setFormat] = useState<CodeFormat>("curl");
  const [status, setStatus] = useTemporaryStatus();
  const reactId = useId().replace(/:/g, "");
  const snippet = useMemo(() => makeSnippet(raw, format), [raw, format]);
  const panelId = `${reactId}-panel`;

  async function handleCopy() {
    try {
      await copyToClipboard(snippet);
      setStatus(`${CODE_FORMATS.find((item) => item.id === format)?.label} 示例已复制`);
    } catch {
      setStatus("复制失败，请长按或选中代码手动复制");
    }
  }

  return (
    <div className="interactive-code-example" data-raw-example={raw}>
      <div className="code-example-toolbar">
        <span>{label}</span>
        <div className="code-format-tabs" role="tablist" aria-label={`${label}格式`}>
          {CODE_FORMATS.map((item) => (
            <button
              type="button"
              role="tab"
              aria-selected={format === item.id}
              aria-controls={panelId}
              data-code-format={item.id}
              className={format === item.id ? "is-active" : ""}
              onClick={() => setFormat(item.id)}
              key={item.id}
            >
              {item.label}
            </button>
          ))}
        </div>
        <button type="button" data-action="copy-code" className="copy-code-button" onClick={handleCopy} aria-label={`复制${label}`}>
          <Clipboard aria-hidden="true" />复制代码
        </button>
      </div>
      <pre id={panelId} role="tabpanel" tabIndex={0} aria-label={`${label}，${format} 格式`}><code>{snippet}</code></pre>
      <span className="action-status code-status" role="status" aria-live="polite" aria-atomic="true">{status}</span>
    </div>
  );
}
