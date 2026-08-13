import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const routeRoot = new URL("../app/internal-downloads/", import.meta.url);
const page = await readFile(new URL("page.tsx", routeRoot), "utf8");
const catalog = await readFile(new URL("catalog.server.ts", routeRoot), "utf8");
const styles = await readFile(new URL("styles.module.css", routeRoot), "utf8");

test("internal route enforces authenticated maintainer allowlist and excludes indexing", () => {
  assert.match(page, /INTERNAL_DOWNLOAD_NOTICE/);
  assert.match(page, /requireChatGPTUser\("\/internal-downloads\/"\)/);
  assert.match(page, /YIYUNYING_INTERNAL_DOWNLOAD_EMAILS/);
  assert.match(page, /allowedEmails\.size === 0/);
  assert.match(page, /notFound\(\)/);
  assert.match(page, /chatGPTSignOutPath/);
  assert.match(page, /剥离伪造 oai-authenticated-user-\*/);
  assert.match(page, /robots:\s*\{\s*index:\s*false,\s*follow:\s*false,\s*nocache:\s*true\s*\}/);
  assert.match(catalog, /仅内部使用 · 仅限本地或受保护网络访问/);
});

test("catalog separates Debug, pending Stable candidate and finalized Stable", () => {
  for (const marker of [
    'title: "Debug 测试包"',
    'title: "Stable Release 候选"',
    'title: "最终正式版"',
    'sanitizeManifest(current, "Stable", "pending", "Release 候选")',
    'sanitizeManifest(current, "Stable", "finalized", "正式发布")',
  ]) assert.match(catalog, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
  assert.match(page, /group\.packages\.length > 0/);
  assert.match(page, /group\.emptyMessage/);
});

test("only four APK roles and safe delivery fields enter the browser model", () => {
  for (const role of ["user", "admin", "authorized", "owner"]) {
    assert.match(catalog, new RegExp(`${role}: \\"`));
  }
  assert.match(catalog, /manifest\.releases\.length !== roleOrder\.length/);
  assert.match(catalog, /expectedStablePackageName/);
  assert.match(catalog, /\^\[0-9A-F\]\{64\}\$/);
  assert.match(catalog, /assertSafeApkIdentity/);
  assert.doesNotMatch(catalog, /downloadHref|YIYUNYING_INTERNAL_DOWNLOAD_BASE_URL|\/downloads\//);
  assert.doesNotMatch(catalog, /projectAssets|connectionIdentity|appKey|platformKey|authorizedPlatformKey/);
  assert.doesNotMatch(page, /release-metadata|projectAssets|connectionIdentity/);
  assert.doesNotMatch(`${page}\n${catalog}`, /\.zip|\.bundle|source-v|git-history|project-delivery/i);
});

test("all cards expose role, version, status, size, SHA, download and install instructions", () => {
  for (const marker of [
    "item.roleLabel",
    "item.versionName",
    "item.versionCode",
    "item.status",
    "item.size",
    "item.sha256",
    "打开安装说明",
    "Get-FileHash",
  ]) assert.match(page, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
  assert.match(page, /<details/);
  assert.match(page, /请启动本机内部下载服务后下载/);
  assert.match(page, /serve-internal-downloads\.py/);
});

test("route owns responsive and print-safe styling without shared CSS edits", () => {
  assert.match(page, /styles\.module\.css/);
  assert.match(styles, /@media \(max-width: 800px\)/);
  assert.match(styles, /@media print/);
  assert.match(styles, /break-inside: avoid/);
  assert.match(page, /className=\{styles\.skipLink\}/);
});

test("built route redirects anonymous users, hides denied users and renders only for an allowlisted maintainer", async () => {
  const previousEmails = process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS;
  process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS = "maintainer@example.com";
  try {
    const workerUrl = new URL("../dist/server/index.js", import.meta.url);
    workerUrl.searchParams.set("internal-auth", `${process.pid}-${Date.now()}-${Math.random()}`);
    const { default: worker } = await import(workerUrl.href);
    const env = {
      ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) },
    };
    const ctx = { waitUntil() {}, passThroughOnException() {} };
    const request = (headers = {}) => worker.fetch(
      new Request("http://localhost/internal-downloads", {
        headers: { accept: "text/html", ...headers },
      }),
      env,
      ctx,
    );

    const anonymous = await request();
    assert.equal(anonymous.status, 307);
    assert.match(anonymous.headers.get("location") ?? "", /^http:\/\/localhost\/signin-with-chatgpt\?return_to=/);

    const denied = await request({ "oai-authenticated-user-email": "other@example.com" });
    assert.equal(denied.status, 404);

    const allowed = await request({
      "oai-authenticated-user-email": "maintainer@example.com",
      "oai-authenticated-user-full-name": "Release%20Maintainer",
      "oai-authenticated-user-full-name-encoding": "percent-encoded-utf-8",
    });
    assert.equal(allowed.status, 200);
    const html = await allowed.text();
    for (const expected of [
      "内部下载中心",
      "Release Maintainer",
      "Debug 测试包",
      "Stable Release 候选",
      "最终正式版",
      "请启动本机内部下载服务后下载",
    ]) assert.ok(html.includes(expected), `allowlisted page missing: ${expected}`);
    assert.doesNotMatch(html, /href=["'][^"']*\/downloads\//i);
    assert.doesNotMatch(html, /source-v|git-history|project-delivery|projectAssets|connectionIdentity/i);
  } finally {
    if (previousEmails === undefined) delete process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS;
    else process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS = previousEmails;
  }
});
