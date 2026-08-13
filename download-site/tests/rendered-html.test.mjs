import assert from "node:assert/strict";
import { readFile, readdir } from "node:fs/promises";
import test from "node:test";
import { isFormalPublicRelease } from "../app/release-state.mjs";

const releaseMetadata = JSON.parse(
  await readFile(new URL("../release-metadata.json", import.meta.url), "utf8"),
);
const packageMetadata = JSON.parse(
  await readFile(new URL("../package.json", import.meta.url), "utf8"),
);

async function render(pathname = "/") {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set(
    "test",
    `${pathname}-${process.pid}-${Date.now()}-${Math.random()}`,
  );
  const { default: worker } = await import(workerUrl.href);
  return worker.fetch(
    new Request(`http://localhost${pathname}`, {
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
}

async function renderedHtml(pathname) {
  let response = await render(pathname);
  if ([301, 302, 307, 308].includes(response.status)) {
    const location = response.headers.get("location");
    assert.ok(location, `${pathname} redirect must include a location`);
    response = await render(new URL(location, "http://localhost").pathname);
  }
  assert.equal(response.status, 200, `${pathname} must render with HTTP 200`);
  assert.match(response.headers.get("content-type") ?? "", /^text\/html\b/i);
  const html = await response.text();
  assert.ok(!html.includes("\uFFFD"), `${pathname} contains replacement characters`);
  return html;
}

async function allFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const target = new URL(entry.name + (entry.isDirectory() ? "/" : ""), directory);
    if (entry.isDirectory()) files.push(...(await allFiles(target)));
    else files.push(target);
  }
  return files;
}

test("renders an original customer-facing official website", async () => {
  const html = await renderedHtml("/");
  assert.match(html, /<title>易云盈｜软件授权与运营平台<\/title>/i);
  assert.match(html, /让每一个应用/);
  assert.match(html, /多应用工作台/);
  assert.match(html, /用户系统/);
  assert.match(html, /邮箱系统/);
  assert.match(html, /论坛系统/);
  assert.match(html, /文档系统/);
  assert.match(html, /好友系统/);
  assert.match(html, /群聊系统/);
  assert.match(html, /聊天系统/);
  assert.match(html, /安全系统/);
  assert.match(html, /卡密系统/);
  assert.match(html, /云仓库/);
  assert.match(html, /商城系统/);
  assert.match(html, /发布运营中心/);
  assert.match(html, /四步完成接入/);
  assert.match(html, /href="\/api-docs\/"/);
  assert.match(html, /href="\/privacy\/"/);
  assert.match(html, /href="\/terms\/"/);
});

test("publishes the release clients selected by the current public policy", async () => {
  const html = await renderedHtml("/");
  const publicReleaseIds = new Set(["user", "admin", "authorized", "owner"]);
  const publicReleases = releaseMetadata.releases.filter(
    ({ id }) => publicReleaseIds.has(id),
  );

  assert.equal(publicReleases.length, 4);
  assert.deepEqual(new Set(publicReleases.map(({ id }) => id)), publicReleaseIds);
  for (const release of publicReleases) {
    assert.match(html, new RegExp(release.name));
    assert.ok(html.includes(release.fileName), `${release.id} file missing from HTML`);
    assert.match(release.sha256, /^[A-F0-9]{64}$/);
    assert.ok(release.sizeBytes > 1024 * 1024);
  }
  for (const asset of releaseMetadata.projectAssets) {
    assert.ok(!html.includes(asset.fileName), `${asset.id} project asset leaked in HTML`);
  }

  assert.doesNotMatch(html, /完整项目|源码快照|Git 历史|项目交接|校验清单/);
  assert.doesNotMatch(html, /PROJECT_ASSETS|projectAssets/);
});

test("requires finalized Stable evidence without Debug markers for the formal UI state", async () => {
  const html = await renderedHtml("/");
  const isFormal = isFormalPublicRelease(releaseMetadata);

  if (isFormal) {
    assert.match(html, /下载易云盈正式版/);
    assert.match(html, /已正式发布/);
  } else {
    assert.match(html, /发布候选/);
    assert.match(html, /仅供闭环测试/);
    assert.doesNotMatch(html, /已正式发布/);
  }

  const stablePending = {
    channel: "Stable",
    finalizationStatus: "pending",
    releaseTag: "v9.8.7",
    releaseEvidenceCommit: null,
    releases: [{ fileName: "user.apk", packageName: "example.user", versionName: "9.8.7" }],
  };
  const stableFinalized = {
    ...stablePending,
    finalizationStatus: "finalized",
    releaseEvidenceCommit: "0123456789abcdef0123456789abcdef01234567",
  };
  const debugPending = {
    channel: "Debug",
    finalizationStatus: "pending",
    releaseTag: "v9.8.7-debug",
    releases: [{ fileName: "user-debug.apk", packageName: "example.user.debug", versionName: "9.8.7-debug" }],
  };
  assert.equal(isFormalPublicRelease(stablePending), false);
  assert.equal(isFormalPublicRelease(stableFinalized), true);
  assert.equal(isFormalPublicRelease(debugPending), false);
});

test("renders audited four-role and per-system API guides", async () => {
  const html = await renderedHtml("/api-docs/");
  for (const text of [
    "用户端", "管理员端", "授权代理端（Level 2）", "买断总控端（Level 1）",
    "APP_API_UNIQUE_ID", "PLATFORM_KEY_PLACEHOLDER", "OWNER_PLATFORM_KEY_PLACEHOLDER",
    "用户系统", "邮箱系统", "论坛系统", "文档系统", "好友系统", "群聊系统",
    "聊天系统", "安全系统", "卡密系统", "云仓库", "商城系统", "公告、更新与维护",
    "代表性最小闭环示例", "关键参数", "成功结果", "常见失败", "怎么用",
    "trace_id", "page", "limit", "上传安全",
  ]) assert.ok(html.includes(text), `API guide missing: ${text}`);

  const endpointContracts = [
    ["POST", "/api/user/login", "app_key, account, password"],
    ["POST", "/api/admin/login", "platform_key、app_key、account、password"],
    ["POST", "/api/platform/login", "platform_key、account、password"],
    ["POST", "/api/public/verification-code/email", "email, scene"],
    ["POST", "/api/user/forum-posts", "plate_id, title"],
    ["POST", "/api/user/friends/requests", "to_uid 或 to_user_id"],
    ["POST", "/api/user/chat-rooms/{room_id}/read", "message_id（必填）"],
    ["POST", "/api/user/messages/private", "to_uid/to_user_id, content"],
    ["POST", "/api/user/cards/redeem", "card_code；Bearer"],
    ["POST", "/api/user/cloud-sync/snapshots", "data_type=chat/stickers/favorites"],
    ["POST", "/api/user/shop-goods/{goods_id}/buy", "quantity"],
    ["GET", "/api/public/lifecycle", "edition_code（必填）"],
    ["PUT", "/api/admin/apps/{app_id}/versions", "version_name, update_content, version_code, apk_url, package_name, sha256, size_bytes"],
    ["POST", "/api/admin/apps/{app_id}/maintenances", "starts_at/ends_at"],
  ];
  for (const [method, path, fields] of endpointContracts) {
    assert.ok(html.includes(method), `${method} missing`);
    assert.ok(html.includes(path), `${path} missing`);
    assert.ok(html.includes(fields), `${path} fields missing: ${fields}`);
  }

  for (const id of ["user", "email", "forum", "document", "friend", "group", "chat", "security", "card", "cloud", "shop", "lifecycle", "embedded-governance"]) {
    assert.match(html, new RegExp(`id="${id}(?:-system)?"[\\s\\S]*?代表性最小闭环示例`));
  }
  for (const forbidden of ["X-App-Id", "Idempotency-Key", "page_size", "data_type\\\":\\\"notes", "app_key 也可由 X-App-Key 传入", "Try it", ">Execute<"]) {
    assert.doesNotMatch(html, new RegExp(forbidden, "i"));
  }
  assert.match(html, /&quot;code&quot;:1/);
  assert.doesNotMatch(html, /&quot;code&quot;:0/);
  assert.doesNotMatch(html, /api\.internal|10\.\d+\.\d+\.\d+/i);
  const { apiBaseUrl: publicApiBaseUrl, ...sensitiveIdentityEvidence } = releaseMetadata.connectionIdentity ?? {};
  assert.ok(!publicApiBaseUrl || /^https:\/\//i.test(String(publicApiBaseUrl)), "formal API base URL must remain public HTTPS metadata");
  for (const value of Object.values(sensitiveIdentityEvidence)) {
    assert.ok(!html.includes(String(value)), "connection identity hash leaked into API guide");
  }
});

test("renders customer operation closures with safe no-script fallbacks", async () => {
  const [home, docs] = await Promise.all([
    renderedHtml("/"),
    renderedHtml("/api-docs/"),
  ]);

  for (const text of [
    "分享官网", "APK 打开、安装与校验", "Get-FileHash", "shasum -a 256",
    "安装未知应用", "不要转换成 EXE、ZIP", "下载失败",
  ]) assert.ok(home.includes(text), `homepage operation guide missing: ${text}`);

  for (const text of [
    "分享当前接口", "打印全部文档", "公开文档链接可访问，无需登录",
    "分享本系统", "打印当前系统", "新窗口打开示例", "一键复制",
    "复制代码", "cURL", "Java", "JavaScript",
    "当前浏览器已禁用 JavaScript", "https://api.example.com",
  ]) assert.ok(docs.includes(text), `API operation closure missing: ${text}`);

  assert.match(docs, /<noscript>/i);
  assert.match(docs, /role="tablist"/i);
  assert.match(docs, /role="tabpanel"/i);
  assert.match(docs, /aria-live="polite"/i);
  assert.match(docs, /href="#user-system"[^>]+target="_blank"/i);
  assert.ok((docs.match(/复制代码/g) ?? []).length >= 17, "every role/system code example must be copyable");
  assert.ok((docs.match(/打印当前系统/g) ?? []).length >= 13, "every system must expose print operation");
});

test("operation source contract keeps sharing, copying, formatting and print safe", async () => {
  const [actions, home, styles, docsSource, staticExporter] = await Promise.all([
    readFile(new URL("../app/api-docs/docs-actions.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/home-client.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/globals.css", import.meta.url), "utf8"),
    readFile(new URL("../app/api-docs/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
  ]);

  for (const contract of [
    "navigator.share", "navigator.clipboard", "document.execCommand(\"copy\")",
    "url.search = \"\"", "window.print()", "afterprint", "CodeFormat",
    '"curl"', '"java"', '"javascript"', "SAFE_BASE_URL",
  ]) assert.ok(actions.includes(contract), `client operation contract missing: ${contract}`);
  assert.ok(home.includes("navigator.share"), "homepage share must prefer Web Share");
  assert.ok(staticExporter.includes('typeof element.closest !== "function"'), "static status feedback must tolerate a missing async event target");
  assert.ok(staticExporter.includes("const button = event.currentTarget;"), "static copy action must retain its button before awaiting clipboard access");
  assert.ok(staticExporter.includes('if (!copied) throw new Error("copy_failed")'), "static clipboard fallback must report execCommand failure");
  assert.match(styles, /@media print[\s\S]*\.docs-header[\s\S]*display:\s*none !important/);
  assert.match(styles, /\.print-current-system \.docs-content > \.is-print-target/);
  assert.match(styles, /white-space:\s*pre-wrap !important/);

  const catalog = [...docsSource.matchAll(/ep\("([A-Z]+)",\s*"([^"]+)"/g)];
  assert.equal(catalog.length, 58, "public API catalog must retain all 58 audited routes");
  const examples = docsSource.slice(docsSource.indexOf("const SYSTEM_EXAMPLES"), docsSource.indexOf("const ERROR_CODES"));
  assert.doesNotMatch(examples, /appht\.jjmxg\.xyz|api\.internal|https?:\/\/10\.\d+\.\d+\.\d+/i);
  for (const value of Object.values(releaseMetadata.connectionIdentity ?? {})) {
    assert.ok(!examples.includes(String(value)), "real connection identity leaked into format source");
  }
});

test("published API endpoint catalog exists in the generated route directory", async () => {
  const [docsSource, routesSource] = await Promise.all([
    readFile(new URL("../app/api-docs/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../../backend/docs/ROUTES.md", import.meta.url), "utf8"),
  ]);
  const actualRoutes = new Set(
    [...routesSource.matchAll(/\| `([A-Z]+)` \| `([^`]+)` \|/g)].map(
      ([, method, path]) => `${method} ${path}`,
    ),
  );
  const catalog = [...docsSource.matchAll(/ep\("([A-Z]+)",\s*"([^"]+)"/g)].map(
    ([, method, path]) => `${method} ${path}`,
  );
  assert.equal(catalog.length, 58, "public catalog must retain all audited system routes");
  for (const route of catalog) assert.ok(actualRoutes.has(route), `catalog route does not exist: ${route}`);

  const examples = docsSource.slice(
    docsSource.indexOf("const SYSTEM_EXAMPLES"),
    docsSource.indexOf("const ERROR_CODES"),
  );
  const placeholders = new Map([
    ["APP_ID", "{app_id}"], ["POST_ID", "{post_id}"],
    ["DOCUMENT_ID", "{document_id}"], ["REQUEST_ID", "{request_id}"],
    ["ROOM_ID", "{room_id}"], ["CONVERSATION_ID", "{conversation_id}"],
    ["SNAPSHOT_ID", "{snapshot_id}"], ["GOODS_ID", "{goods_id}"],
  ]);
  const exampleRoutes = [...examples.matchAll(/(?:^|\n)(GET|POST|PUT|DELETE) (\/api\/[^\s?；]+)/g)].map(
    ([, method, rawPath]) => {
      let path = rawPath;
      for (const [token, parameter] of placeholders) path = path.replaceAll(token, parameter);
      if (path === "/api/user/orders/shop/ORDER_ID") path = "/api/user/orders/{order_source}/{order_id}";
      return `${method} ${path}`;
    },
  );
  assert.ok(exampleRoutes.length >= 20, "system examples must include requests and read-backs");
  for (const route of exampleRoutes) assert.ok(actualRoutes.has(route), `example route does not exist: ${route}`);
});
test("renders privacy and terms pages without invented contact details", async () => {
  const privacy = await renderedHtml("/privacy/");
  const terms = await renderedHtml("/terms/");

  assert.match(privacy, /隐私政策/);
  assert.match(privacy, /我们处理的数据/);
  assert.match(privacy, /保存与删除/);
  assert.match(privacy, /账号注销/);
  assert.match(privacy, /未成年人保护/);
  assert.match(privacy, /应用内反馈/);

  assert.match(terms, /服务条款/);
  assert.match(terms, /禁止行为/);
  assert.match(terms, /版本更新与维护/);
  assert.match(terms, /责任边界/);
  assert.match(terms, /应用内反馈/);

  for (const html of [privacy, terms]) {
    assert.doesNotMatch(html, /ICP备|统一社会信用代码|@(?:qq|gmail|outlook|163)\./i);
  }
});

test("keeps full release metadata on the server and exports four public pages", async () => {
  const [page, client, exporter, layout, manifest] = await Promise.all([
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/home-client.tsx", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
    readFile(new URL("../app/layout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../public/site.webmanifest", import.meta.url), "utf8"),
  ]);

  if (packageMetadata.version !== releaseMetadata.versionName) {
    assert.equal(
      releaseMetadata.finalizationStatus,
      "pending",
      "a temporary version mismatch is only allowed before release metadata finalization",
    );
    assert.equal(
      isFormalPublicRelease(releaseMetadata),
      false,
      "a superseded pending manifest must never be rendered as the formal release",
    );
  }
  assert.match(page, /import releaseMetadata from "\.\.\/release-metadata\.json"/);
  assert.match(page, /PUBLIC_RELEASE_IDS = \["user", "admin", "authorized", "owner"\]/);
  assert.doesNotMatch(client, /release-metadata\.json/);
  assert.doesNotMatch(client, /PROJECT_ASSETS|projectAssets/);
  assert.match(exporter, /PUBLIC_RELEASE_IDS = \["user", "admin", "authorized", "owner"\]/);
  assert.match(exporter, /"\/api-docs\/", "\/privacy\/", "\/terms\/"/);
  assert.match(layout, /易云盈｜软件授权与运营平台/);
  assert.match(layout, /\/download-center\/logo\.svg/);
  assert.match(layout, /\/download-center\/site\.webmanifest/);
  assert.match(manifest, /\/download-center\/logo\.svg/);
  assert.match(manifest, /易云盈应用运营平台/);

  for (const source of [page, client, exporter, layout, manifest]) {
    assert.ok(!source.includes("\uFFFD"), "source contains replacement characters");
  }
});

test("does not bundle private project asset records into browser assets", async () => {
  const clientRoot = new URL("../dist/client/", import.meta.url);
  const files = (await allFiles(clientRoot)).filter(
    (url) => /\.(?:js|html|json)$/i.test(url.pathname),
  );
  const content = (
    await Promise.all(files.map((url) => readFile(url, "utf8")))
  ).join("\n");

  for (const asset of releaseMetadata.projectAssets) {
    assert.ok(!content.includes(asset.fileName), `${asset.id} project asset leaked to client bundle`);
  }
  assert.ok(!content.includes("project-assets-manifest.json"), "project manifest leaked to client bundle");
});
