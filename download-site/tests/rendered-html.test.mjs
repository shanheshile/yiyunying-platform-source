import assert from "node:assert/strict";
import { mkdtemp, readFile, readdir, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import test from "node:test";
import { runInNewContext } from "node:vm";
import { isFormalPublicRelease } from "../app/release-state.mjs";
import { createPublicReleaseProjection } from "../scripts/public-release-projection.mjs";

const releaseMetadata = JSON.parse(
  await readFile(new URL("../release-metadata.json", import.meta.url), "utf8"),
);
const packageMetadata = JSON.parse(
  await readFile(new URL("../package.json", import.meta.url), "utf8"),
);

const DEVICE_PENDING_NOTICE = "真机验证待用户完成（不得声明真机通过）";

function finalizedManifestFixture(pendingMetadata) {
  const finalManifest = structuredClone(pendingMetadata);
  delete finalManifest.pendingManifestSha256;
  finalManifest.finalizationStatus = "finalized";
  finalManifest.releaseEvidenceCommit = "b".repeat(40);
  if (finalManifest.deviceValidationPlan === "risk-waiver") {
    finalManifest.deviceValidation = {
      plan: "risk-waiver",
      status: "pending-user-validation",
      evidenceFileName: "release-risk-waiver.json",
      evidenceSha256: "e".repeat(64),
      publicNotice: DEVICE_PENDING_NOTICE,
    };
  }
  return finalManifest;
}

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
  assert.match(html, /data-service-health/);
  assert.match(html, /data-action="refresh-health"/);
  assert.match(html, /同源公开健康检查/);
  assert.match(html, /公开服务状态/);
  assert.match(html, /平台总控客户端与源码买断自建部署有什么区别/);
  assert.match(html, /自建多线路只会为 GET\/HEAD 读取在连接类异常或 502\/503\/504 时按顺序切换/);
  assert.match(html, /写请求、上传和 Token 刷新均不会跨线路重放/);
  assert.doesNotMatch(html, /自动多线路切换仍需完成实现与验收后才能声明可用/);
  assert.doesNotMatch(html, /1,286|3,492|99\.98%|实时在线 238|待审核 12/);
});

test("publishes the release clients selected by the current public policy", async () => {
  const html = await renderedHtml("/");
  const publicReleaseIds = new Set(["user", "admin", "authorized", "owner"]);
  const publicReleases = releaseMetadata.releases.filter(
    ({ id }) => publicReleaseIds.has(id),
  );

  assert.equal(publicReleases.length, 4);
  assert.deepEqual(new Set(publicReleases.map(({ id }) => id)), publicReleaseIds);
  const isFormal = isFormalPublicRelease(releaseMetadata);
  for (const release of publicReleases) {
    assert.match(release.sha256, /^[A-F0-9]{64}$/);
    assert.ok(release.sizeBytes > 1024 * 1024);
    if (isFormal) {
      assert.match(html, new RegExp(release.name));
      assert.ok(html.includes(release.fileName), `${release.id} file missing from HTML`);
    } else {
      for (const forbidden of [
        release.fileName,
        release.sha256,
        release.packageName,
        release.versionName,
      ]) {
        assert.ok(!html.includes(String(forbidden)), `${release.id} candidate metadata leaked in HTML`);
      }
    }
  }
  for (const asset of releaseMetadata.projectAssets) {
    assert.ok(!html.includes(asset.fileName), `${asset.id} project asset leaked in HTML`);
  }

  assert.doesNotMatch(html, /完整项目|源码快照|Git 历史|项目交接|校验清单/);
  assert.doesNotMatch(html, /PROJECT_ASSETS|projectAssets/);
  if (!isFormal) {
    assert.ok(!html.includes(releaseMetadata.versionName), "candidate version leaked in HTML");
    assert.doesNotMatch(html, /href=["'][^"']*\/downloads\//i);
  }
});

test("requires finalized Stable evidence without Debug markers for the formal UI state", async () => {
  const html = await renderedHtml("/");
  const isFormal = isFormalPublicRelease(releaseMetadata);

  if (isFormal) {
    assert.match(html, /下载易云盈正式版/);
    assert.match(html, /已正式发布/);
    assert.match(html, /Finalized · 四角色一致/);
    assert.match(script, /fetch\("\/api\/health"/);
  } else {
    assert.match(html, /接入资料已开放，客户端仍在发布验收/);
    assert.match(html, /下载区在完成正式发布验收前保持关闭/);
    assert.match(html, /客户接口文档/);
    assert.match(html, /60 条已核验白名单路由/);
    assert.doesNotMatch(html, /正式版尚未开放/);
    assert.doesNotMatch(html, /当前页面不会公开候选版本/);
    assert.doesNotMatch(html, /发布候选|候选包|仅供闭环测试/);
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

test("exports four formal clients only from a finalized manifest bound to pending metadata", async () => {
  const temporary = await mkdtemp(join(tmpdir(), "yiyunying-public-export-"));
  try {
    const metadataPath = join(temporary, "release-metadata.json");
    const manifestPath = join(temporary, "release-manifest.json");
    const outputPath = join(temporary, "public");
    const finalManifest = finalizedManifestFixture(releaseMetadata);
    await writeFile(metadataPath, JSON.stringify(releaseMetadata), "utf8");
    await writeFile(manifestPath, JSON.stringify(finalManifest), "utf8");

    const result = spawnSync(
      process.execPath,
      [
        fileURLToPath(new URL("../scripts/export-static.mjs", import.meta.url)),
        `--metadata=${metadataPath}`,
        `--final-manifest=${manifestPath}`,
        `--output-dir=${outputPath}`,
      ],
      { encoding: "utf8", windowsHide: true },
    );
    assert.equal(result.status, 0, result.stderr || result.stdout);
    const [html, script] = await Promise.all([
      readFile(join(outputPath, "index.html"), "utf8"),
      readFile(join(outputPath, "site.js"), "utf8"),
    ]);
    assert.match(html, /下载易云盈正式版/);
    assert.match(html, /已正式发布/);
    for (const [index, release] of releaseMetadata.releases.entries()) {
      assert.ok(html.includes(release.shortName), `${release.id} formal role tab missing`);
      assert.ok(script.includes(release.fileName), `${release.id} browser projection missing`);
      assert.ok(script.includes(release.sha256), `${release.id} browser hash missing`);
      if (index === 0) {
        assert.ok(html.includes(release.fileName), "default formal filename missing");
        assert.ok(html.includes(release.sha256), "default formal hash missing");
        assert.ok(
          html.includes(`/downloads/${releaseMetadata.versionName}/${release.fileName}`),
          "default formal download href missing",
        );
      }
    }
    for (const asset of releaseMetadata.projectAssets) {
      assert.ok(!html.includes(asset.fileName), `${asset.id} leaked to formal HTML`);
      assert.ok(!script.includes(asset.fileName), `${asset.id} leaked to formal script`);
    }
    const identityHashes = { ...releaseMetadata.connectionIdentity };
    delete identityHashes.apiBaseUrl;
    for (const value of Object.values(identityHashes)) {
      assert.ok(!html.includes(String(value)), "connection identity leaked to formal HTML");
      assert.ok(!script.includes(String(value)), "connection identity leaked to formal script");
    }
    const validator = spawnSync(
      "python",
      [
        "-c",
        [
          "import importlib.util,sys,types",
          "from pathlib import Path",
          "stub=types.ModuleType('paramiko')",
          "stub.SSHClient=type('SSHClient',(),{})",
          "stub.SFTPClient=type('SFTPClient',(),{})",
          "stub.RejectPolicy=type('RejectPolicy',(),{})",
          "sys.modules['paramiko']=stub",
          "path=Path('scripts/deploy-static.py').resolve()",
          "spec=importlib.util.spec_from_file_location('formal_export_validator',path)",
          "module=importlib.util.module_from_spec(spec)",
          "sys.modules[spec.name]=module",
          "spec.loader.exec_module(module)",
          "files=module.validate_site_tree(Path(sys.argv[1]),sys.argv[2],channel='Stable')",
          "assert {item.relative for item in files} >= {'index.html','site.js','docs.js'}",
        ].join(";"),
        outputPath,
        releaseMetadata.versionName,
      ],
      {
        cwd: fileURLToPath(new URL("..", import.meta.url)),
        encoding: "utf8",
        windowsHide: true,
      },
    );
    assert.equal(validator.status, 0, validator.stderr || validator.stdout);
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
});

test("formal public projection rejects immutable, identity and pending-evidence drift", () => {
  const finalManifest = finalizedManifestFixture(releaseMetadata);
  assert.equal(
    createPublicReleaseProjection(releaseMetadata, {
      ...finalManifest,
      finalizationStatus: "pending",
      releaseEvidenceCommit: null,
    }),
    null,
  );
  assert.doesNotThrow(() => createPublicReleaseProjection(releaseMetadata, finalManifest));

  const immutableDrift = structuredClone(finalManifest);
  immutableDrift.releaseNotes = ["unbound note"];
  assert.throws(
    () => createPublicReleaseProjection(releaseMetadata, immutableDrift),
    /immutable field mismatch/,
  );
  const roleDrift = structuredClone(finalManifest);
  roleDrift.releases[0].packageName = "example.wrong";
  const matchingPendingDrift = structuredClone(releaseMetadata);
  matchingPendingDrift.releases[0].packageName = "example.wrong";
  assert.throws(
    () => createPublicReleaseProjection(matchingPendingDrift, roleDrift),
    /APK identity mismatch/,
  );
  const missingPendingHash = structuredClone(releaseMetadata);
  delete missingPendingHash.pendingManifestSha256;
  assert.throws(
    () => createPublicReleaseProjection(missingPendingHash, finalManifest),
    /canonical pending evidence/,
  );
});

test("future Stable code >=66 cannot omit the device validation plan", () => {
  const pending = structuredClone(releaseMetadata);
  pending.versionName = "1.0.1";
  pending.versionCode = 67;
  pending.releaseTag = "v1.0.1";
  delete pending.deviceValidationPlan;
  const stems = {
    user: "user",
    admin: "admin",
    authorized: "authorized-platform",
    owner: "platform-owner",
  };
  pending.releases = pending.releases.map((release) => ({
    ...release,
    fileName: `yiyunying-${stems[release.id]}-v1.0.1.apk`,
    versionName: `1.0.1-${stems[release.id]}`,
    versionCode: 67,
  }));
  const finalManifest = structuredClone(pending);
  delete finalManifest.pendingManifestSha256;
  finalManifest.finalizationStatus = "finalized";
  finalManifest.releaseEvidenceCommit = "b".repeat(40);
  assert.throws(
    () => createPublicReleaseProjection(pending, finalManifest),
    /requires a device validation plan/,
  );
});

test("risk-waiver projection stays pending and publishes the user-validation notice", async () => {
  const notice = DEVICE_PENDING_NOTICE;
  const pending = structuredClone(releaseMetadata);
  pending.versionCode = 66;
  pending.deviceValidationPlan = "risk-waiver";
  pending.releaseNotes = [...pending.releaseNotes, notice];
  pending.releases = pending.releases.map((release) => ({ ...release, versionCode: 66 }));
  const finalManifest = structuredClone(pending);
  delete finalManifest.pendingManifestSha256;
  finalManifest.finalizationStatus = "finalized";
  finalManifest.releaseEvidenceCommit = "b".repeat(40);
  finalManifest.deviceValidation = {
    plan: "risk-waiver",
    status: "pending-user-validation",
    evidenceFileName: "release-risk-waiver.json",
    evidenceSha256: "e".repeat(64),
    publicNotice: notice,
  };
  const projection = createPublicReleaseProjection(pending, finalManifest);
  assert.ok(projection.releaseNotes.includes(notice));

  const forgedPass = structuredClone(finalManifest);
  forgedPass.deviceValidation.status = "passed";
  assert.throws(
    () => createPublicReleaseProjection(pending, forgedPass),
    /must remain pending user device validation/,
  );
  const forgedNotes = structuredClone(finalManifest);
  forgedNotes.releaseNotes.push("真机验证已通过");
  const matchingForgedPending = structuredClone(pending);
  matchingForgedPending.releaseNotes.push("真机验证已通过");
  assert.throws(
    () => createPublicReleaseProjection(matchingForgedPending, forgedNotes),
    /must remain pending user device validation/,
  );

  const temporary = await mkdtemp(join(tmpdir(), "yiyunying-waiver-export-"));
  try {
    const metadataPath = join(temporary, "release-metadata.json");
    const manifestPath = join(temporary, "release-manifest.json");
    const outputPath = join(temporary, "public");
    await writeFile(metadataPath, JSON.stringify(pending), "utf8");
    await writeFile(manifestPath, JSON.stringify(finalManifest), "utf8");
    const result = spawnSync(
      process.execPath,
      [
        fileURLToPath(new URL("../scripts/export-static.mjs", import.meta.url)),
        `--metadata=${metadataPath}`,
        `--final-manifest=${manifestPath}`,
        `--output-dir=${outputPath}`,
      ],
      { encoding: "utf8", windowsHide: true },
    );
    assert.equal(result.status, 0, result.stderr || result.stdout);
    const html = await readFile(join(outputPath, "index.html"), "utf8");
    assert.ok(html.includes(notice));
    assert.doesNotMatch(html, /真机验证已通过/);
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
});

test("renders audited four-role and per-system API guides", async () => {
  const html = await renderedHtml("/api-docs/");
  for (const text of [
    "用户端", "管理员端", "授权代理端（Level 2）", "平台总控客户端（官方托管，Level 1）",
    "APP_API_UNIQUE_ID", "PLATFORM_KEY_PLACEHOLDER", "OWNER_PLATFORM_KEY_PLACEHOLDER",
    "用户系统", "邮箱系统", "论坛系统", "文档系统", "好友系统", "群聊系统",
    "聊天系统", "安全系统", "卡密系统", "云仓库", "商城系统", "公告、更新与维护",
    "代表性最小闭环示例", "关键参数", "成功结果", "常见失败", "怎么用",
    "trace_id", "page", "limit", "上传安全", "先看清公开文档能证明什么",
    "60 条白名单路由", "四角色认证矩阵", "统一响应与状态回读", "最小功能闭环验收",
    "路由存在不等同部署环境已开通或生产验收通过", "写入成功提示不是最终业务状态",
    "官方托管与源码买断自建是两条独立轨道", "https://appht.jjmxg.xyz/",
    "https://api.your-company.example/", "http://dev-api.your-company.example/",
    "均为不可执行的域名格式示例", "自动切线仅适用于 GET/HEAD 的连接类异常或 502/503/504",
    "写请求、上传和 Token 刷新不跨线路重放", "全部失败也不回退官方服务",
    "搜索接口路径、用途、方法或系统…", "已索引 60 条白名单接口",
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
    ["GET", "/api/user/uploads", "keyword, scene, category, date_from, date_to"],
    ["POST", "/api/user/uploads", "multipart/form-data"],
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
  assert.equal(publicApiBaseUrl, "https://appht.jjmxg.xyz/", "official API base URL must not drift");
  assert.doesNotMatch(html, /http:\/\/appht\.jjmxg\.xyz\/?/i, "official API must never advertise HTTP downgrade");
  for (const value of Object.values(sensitiveIdentityEvidence)) {
    assert.ok(!html.includes(String(value)), "connection identity hash leaked into API guide");
  }
});

test("renders customer operation closures with safe no-script fallbacks", async () => {
  const [home, docs] = await Promise.all([
    renderedHtml("/"),
    renderedHtml("/api-docs/"),
  ]);

  assert.ok(home.includes("分享官网"), "homepage operation guide missing: 分享官网");
  if (isFormalPublicRelease(releaseMetadata)) {
    for (const text of [
      "APK 打开、安装与校验", "Get-FileHash", "shasum -a 256",
      "安装未知应用", "不要转换成 EXE、ZIP", "下载失败",
    ]) assert.ok(home.includes(text), `homepage operation guide missing: ${text}`);
  } else {
    for (const forbidden of ["APK 打开、安装与校验", "data-release-file", 'class="file-verification"']) {
      assert.ok(!home.includes(forbidden), `pending homepage leaked download guide: ${forbidden}`);
    }
  }

  for (const text of [
    "分享当前接口", "打印全部文档", "公开文档链接可访问，无需登录",
    "分享本系统", "打印当前系统", "新窗口打开示例", "一键复制",
    "复制代码", "cURL", "Java", "JavaScript",
    "当前浏览器已禁用 JavaScript", "https://appht.jjmxg.xyz/",
  ]) assert.ok(docs.includes(text), `API operation closure missing: ${text}`);

  assert.match(docs, /<noscript>/i);
  assert.match(docs, /role="tablist"/i);
  assert.match(docs, /role="tabpanel"/i);
  assert.match(docs, /aria-live="polite"/i);
  assert.match(docs, /data-endpoint-catalog=/i);
  assert.match(docs, /href="#user-system"[^>]+target="_blank"/i);
  assert.ok((docs.match(/复制代码/g) ?? []).length >= 17, "every role/system code example must be copyable");
  const uploadSection = docs.match(/<section class="upload-section" id="uploads">([\s\S]*?)<\/section>/i)?.[1] ?? "";
  assert.match(uploadSection, /multipart\/form-data/);
  assert.match(uploadSection, /--form/);
  assert.match(uploadSection, /GET \/api\/user\/uploads/);
  assert.doesNotMatch(uploadSection, /--header (?:&#x27;|')Content-Type: application\/json/i);
  assert.ok((docs.match(/打印当前系统/g) ?? []).length >= 13, "every system must expose print operation");
});

test("operation source contract keeps sharing, copying, formatting and print safe", async () => {
  const [actions, home, styles, docsSource, staticExporter, publicApiConfig] = await Promise.all([
    readFile(new URL("../app/api-docs/docs-actions.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/home-client.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/globals.css", import.meta.url), "utf8"),
    readFile(new URL("../app/api-docs/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
    readFile(new URL("../app/public-api.mjs", import.meta.url), "utf8"),
  ]);

  for (const contract of [
    "navigator.share", "navigator.clipboard", "document.execCommand(\"copy\")",
    "url.search = \"\"", "window.print()", "afterprint", "CodeFormat",
    '"curl"', '"java"', '"javascript"', "officialApiUrl", "EndpointSearch",
    "catalog.filter", "slice(0, 12)", "清空接口搜索",
  ]) assert.ok(actions.includes(contract), `client operation contract missing: ${contract}`);
  assert.match(publicApiConfig, /OFFICIAL_API_BASE_URL = "https:\/\/appht\.jjmxg\.xyz\/"/);
  assert.match(publicApiConfig, /SELF_HOSTED_API_BASE_URL_EXAMPLE = "https:\/\/api\.your-company\.example\/"/);
  assert.match(publicApiConfig, /SELF_HOSTED_HTTP_API_BASE_URL_EXAMPLE = "http:\/\/dev-api\.your-company\.example\/"/);
  assert.doesNotMatch(publicApiConfig, /http:\/\/appht\.jjmxg\.xyz/i);
  assert.ok(staticExporter.includes("OFFICIAL_API_BASE_URL"), "static docs converter must use the official base URL contract");
  assert.ok(staticExporter.includes("renderEndpointSearch"), "static docs export must preserve endpoint search interaction");
  assert.ok(staticExporter.includes('endpointSearchInput?.addEventListener("input"'), "static endpoint search must react to user input");
  assert.ok(staticExporter.includes("endpointSearchResults.replaceChildren()"), "static endpoint search must replace stale results");
  assert.ok(home.includes("navigator.share"), "homepage share must prefer Web Share");
  assert.ok(home.includes('fetch("/api/health"'), "homepage must read only the same-origin public health endpoint");
  assert.ok(home.includes('cache: "no-store"'), "live public health must bypass stale browser caches");
  assert.ok(home.includes('credentials: "same-origin"'), "live public health must stay on the current origin");
  assert.ok(staticExporter.includes('typeof element.closest !== "function"'), "static status feedback must tolerate a missing async event target");
  assert.ok(staticExporter.includes("const button = event.currentTarget;"), "static copy action must retain its button before awaiting clipboard access");
  assert.ok(staticExporter.includes('if (!copied) throw new Error("copy_failed")'), "static clipboard fallback must report execCommand failure");
  assert.ok(
    staticExporter.includes("if (downloadButtonSize) downloadButtonSize.textContent = current.size"),
    "static role switching must update the selected APK size inside the download button",
  );
  assert.match(styles, /@media print[\s\S]*\.docs-header[\s\S]*display:\s*none !important/);
  assert.match(styles, /\.print-current-system \.docs-content > \.is-print-target/);
  assert.match(styles, /white-space:\s*pre-wrap !important/);
  assert.match(styles, /\.reveal-ready \[data-reveal\]/);
  assert.match(styles, /@keyframes health-pulse/);
  assert.match(styles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*\[data-reveal\][\s\S]*opacity:\s*1 !important/);

  const sharedStatusScript = staticExporter.slice(
    staticExporter.indexOf("const sharedBrowserScript"),
    staticExporter.indexOf("const formalBrowserScript"),
  );
  for (const contract of [
    'fetch("/api/health"',
    'cache: "no-store"',
    'credentials: "same-origin"',
    'value.code === 1',
    'value.data.status === "ok"',
    'value.data.database === "connected"',
    'IntersectionObserver',
    'prefers-reduced-motion: reduce',
  ]) assert.ok(sharedStatusScript.includes(contract), `static live-status contract missing: ${contract}`);
  for (const forbidden of [
    "platform_key", "app_key", "Authorization", "releaseMetadata",
    "pendingManifestSha256", "/api/public/lifecycle", "/downloads/",
  ]) assert.ok(!sharedStatusScript.includes(forbidden), `static live-status script leaked or requested forbidden data: ${forbidden}`);

  const catalog = [...docsSource.matchAll(/ep\("([A-Z]+)",\s*"([^"]+)"/g)];
  assert.equal(catalog.length, 60, "public API catalog must retain all 60 audited routes");
  const examples = docsSource.slice(docsSource.indexOf("const SYSTEM_EXAMPLES"), docsSource.indexOf("const ERROR_CODES"));
  assert.doesNotMatch(examples, /api\.internal|https?:\/\/10\.\d+\.\d+\.\d+/i);
  for (const source of [actions, docsSource, staticExporter]) {
    assert.ok(!source.includes("https://api.example.com"), "obsolete placeholder API base must not remain executable");
  }
  for (const value of Object.values(releaseMetadata.connectionIdentity ?? {})) {
    assert.ok(!examples.includes(String(value)), "real connection identity leaked into format source");
  }
});

test("static homepage health interaction updates only from the public same-origin response", async () => {
  const exporter = await readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8");
  const source = exporter.match(/const sharedBrowserScript = `([\s\S]*?)`;\s*\n\s*const formalBrowserScript/)?.[1];
  assert.ok(source, "shared static homepage interaction script missing");

  const listeners = new Map();
  const windowListeners = new Map();
  const classNames = new Set();
  const container = { dataset: {} };
  const label = { textContent: "" };
  const database = { textContent: "" };
  const checkedAt = { textContent: "" };
  const button = {
    disabled: false,
    attributes: new Map(),
    addEventListener(type, listener) { listeners.set(type, listener); },
    setAttribute(name, value) { this.attributes.set(name, value); },
  };
  const reveal = {
    classList: { add(name) { classNames.add(name); } },
  };
  let responseMode = "healthy";
  const navigator = { onLine: true };
  const document = {
    hidden: false,
    documentElement: { classList: { add() {} } },
    querySelectorAll(selector) {
      return {
        "[data-service-health]": [container],
        "[data-health-label]": [label],
        "[data-health-database]": [database],
        "[data-health-time]": [checkedAt],
        '[data-action="refresh-health"]': [button],
        "[data-reveal]": [reveal],
      }[selector] ?? [];
    },
    addEventListener(type, listener) { listeners.set(type, listener); },
  };
  const window = {
    setTimeout,
    clearTimeout,
    setInterval() { return 1; },
    addEventListener(type, listener) { windowListeners.set(type, listener); },
    matchMedia() { return { matches: false }; },
  };
  class IntersectionObserver {
    constructor(callback) { this.callback = callback; }
    observe(target) { this.callback([{ isIntersecting: true, target }]); }
    unobserve() {}
  }
  const context = {
    AbortController,
    Date,
    Error,
    Intl,
    IntersectionObserver,
    document,
    navigator,
    window,
    fetch: async () => ({
      ok: true,
      json: async () => responseMode === "healthy"
        ? { code: 1, data: { status: "ok", database: "connected" } }
        : { code: 1, data: { status: "maintenance", database: "connected" } },
    }),
  };

  runInNewContext(source, context);
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(container.dataset.healthState, "operational");
  assert.equal(label.textContent, "服务正常");
  assert.equal(database.textContent, "已连接");
  assert.equal(button.disabled, false);
  assert.equal(button.attributes.get("aria-busy"), "false");
  assert.ok(classNames.has("is-visible"), "reveal interaction did not expose its target");

  responseMode = "unhealthy";
  await listeners.get("click")();
  assert.equal(container.dataset.healthState, "degraded");
  assert.equal(label.textContent, "暂时无法确认");
  assert.equal(database.textContent, "无法确认");

  navigator.onLine = false;
  windowListeners.get("offline")();
  assert.equal(container.dataset.healthState, "offline");
  assert.equal(label.textContent, "网络已断开");
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
  assert.equal(catalog.length, 60, "public catalog must retain all audited system routes");
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
  const [page, client, exporter, projection, layout, manifest] = await Promise.all([
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/home-client.tsx", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
    readFile(new URL("../scripts/public-release-projection.mjs", import.meta.url), "utf8"),
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
  assert.match(projection, /ROLE_ORDER = \["user", "admin", "authorized", "owner"\]/);
  assert.match(exporter, /loadPublicReleaseProjection/);
  assert.match(exporter, /"\/api-docs\/", "\/privacy\/", "\/terms\/"/);
  assert.match(layout, /易云盈｜软件授权与运营平台/);
  assert.match(layout, /\/download-center\/logo\.svg/);
  assert.match(layout, /\/download-center\/site\.webmanifest/);
  assert.match(manifest, /\/download-center\/logo\.svg/);
  assert.match(manifest, /易云盈应用运营平台/);

  for (const source of [page, client, exporter, projection, layout, manifest]) {
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
