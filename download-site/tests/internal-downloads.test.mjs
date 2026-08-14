import assert from "node:assert/strict";
import { createHmac } from "node:crypto";
import { readdir, readFile } from "node:fs/promises";
import { test } from "node:test";

const routeRoot = new URL("../app/internal-downloads/", import.meta.url);
const page = await readFile(new URL("page.tsx", routeRoot), "utf8");
const catalog = await readFile(new URL("catalog.server.ts", routeRoot), "utf8");
const authorization = await readFile(new URL("authorization.server.ts", routeRoot), "utf8");
const signedLinks = await readFile(new URL("signed-links.server.ts", routeRoot), "utf8");
const downloadRoute = await readFile(new URL("download/route.ts", routeRoot), "utf8");
const chatGPTAuth = await readFile(new URL("../chatgpt-auth.ts", routeRoot), "utf8");
const styles = await readFile(new URL("styles.module.css", routeRoot), "utf8");
const currentReleaseMetadata = JSON.parse(
  await readFile(new URL("../../release-metadata.json", routeRoot), "utf8"),
);
const currentStableReleases = currentReleaseMetadata.releases;

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

async function readBrowserBuildText(directory) {
  const contents = [];
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const child = new URL(`${entry.name}${entry.isDirectory() ? "/" : ""}`, directory);
    if (entry.isDirectory()) {
      contents.push(await readBrowserBuildText(child));
    } else if (/\.(?:css|html|js|json|map|txt|webmanifest)$/i.test(entry.name)) {
      contents.push(await readFile(child, "utf8"));
    }
  }
  return contents.flat().join("\n");
}

test("internal route enforces authenticated maintainer allowlist and excludes indexing", () => {
  assert.match(page, /INTERNAL_DOWNLOAD_NOTICE/);
  assert.match(page, /requireChatGPTUser\("\/internal-downloads\/"\)/);
  assert.match(page, /isAuthorizedInternalDownloadUser/);
  assert.match(authorization, /YIYUNYING_INTERNAL_DOWNLOAD_EMAILS/);
  assert.match(authorization, /YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS/);
  assert.match(authorization, /allowedEmails\.size === 0 \|\| allowedUserIds\.size === 0/);
  assert.match(chatGPTAuth, /oai-authenticated-user-id/);
  assert.match(chatGPTAuth, /userId/);
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
    "projectCurrentStableManifest(current)",
    'stable.finalizationStatus === "pending"',
    'stable.finalizationStatus === "finalized"',
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
  assert.match(catalog, /expectedDebugReleaseIdentity/);
  assert.match(catalog, /expectedDebugArtifacts/);
  assert.match(catalog, /manifest\.schemaVersion !== 4/);
  assert.match(catalog, /currentReleaseManifest as RawManifest/);
  assert.match(catalog, /never spreads or returns the raw manifest or raw release rows/);
  assert.match(catalog, /size !== expectedSize/);
  assert.match(catalog, /\^\[0-9A-F\]\{64\}\$/);
  assert.match(catalog, /assertSafeApkIdentity/);
  assert.doesNotMatch(catalog, /YIYUNYING_INTERNAL_DOWNLOAD_BASE_URL|\/downloads\//);
  assert.doesNotMatch(catalog, /projectAssets|connectionIdentity|appKey|platformKey|authorizedPlatformKey/);
  assert.doesNotMatch(page, /release-metadata|projectAssets|connectionIdentity/);
  assert.doesNotMatch(`${page}\n${catalog}`, /\.zip|\.bundle|source-v|git-history|project-delivery/i);

  for (const release of currentStableReleases) {
    assert.doesNotMatch(catalog, new RegExp(escapeRegExp(release.sha256), "i"));
    assert.doesNotMatch(catalog, new RegExp(`\\b${release.sizeBytes}\\b`));
  }
});

test("browser build excludes raw Stable metadata and server-only release evidence", async () => {
  const browserBuild = await readBrowserBuildText(new URL("../dist/client/", import.meta.url));
  const forbiddenValues = [
    currentReleaseMetadata.buildSourceCommit,
    currentReleaseMetadata.releaseEvidenceCommit,
    currentReleaseMetadata.releaseIdentitySha256,
    currentReleaseMetadata.pendingManifestSha256,
    ...Object.values(currentReleaseMetadata.connectionIdentity ?? {}).filter((value) => value !== currentReleaseMetadata.connectionIdentity?.apiBaseUrl),
    ...(currentReleaseMetadata.projectAssets ?? []).map((asset) => asset.fileName),
    ...currentStableReleases.flatMap((release) => [release.packageName, release.signerSha256]),
  ].filter((value) => typeof value === "string" && value.length > 0);

  for (const forbidden of new Set(forbiddenValues)) {
    assert.doesNotMatch(browserBuild, new RegExp(escapeRegExp(forbidden), "i"));
  }
  assert.doesNotMatch(browserBuild, /connectionIdentity|projectAssets|pendingManifestSha256/);
});

test("all cards expose role, version, status, size, SHA, short download and install instructions", () => {
  for (const marker of [
    "item.roleLabel",
    "item.versionName",
    "item.versionCode",
    "item.status",
    "item.size",
    "item.sha256",
    "短时安全下载",
    "打开安装说明",
    "Get-FileHash",
  ]) assert.match(page, new RegExp(marker.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
  assert.match(page, /<details/);
  assert.match(page, /签名下载未配置，链接已关闭/);
  assert.match(page, /短时地址在过期前仍可能被转发，请勿分享/);
  assert.match(page, /INTERNAL_DOWNLOAD_LINK_TTL_SECONDS \/ 60/);
});

test("server signing contract is fixed HMAC-SHA256 and fails closed", () => {
  assert.match(signedLinks, /INTERNAL_DOWNLOAD_LINK_TTL_SECONDS = 5 \* 60/);
  assert.match(signedLinks, /\^\[0-9a-f\]\{64\}\$/);
  assert.match(signedLinks, /YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET/);
  assert.match(signedLinks, /YIYUNYING_INTERNAL_DOWNLOAD_ORIGIN/);
  assert.match(signedLinks, /createHmac\("sha256", Buffer\.from\(configuration\.secret, "hex"\)\)/);
  assert.match(signedLinks, /`\$\{expiresAt\}\\n\$\{pathname\}`/);
  assert.match(signedLinks, /digest\("base64url"\)/);
  assert.match(signedLinks, /\/__internal-apks/);
  assert.match(signedLinks, /if \(!hasInternalDownloadSigningConfiguration\(\)\) return groups/);
  assert.doesNotMatch(`${page}\n${signedLinks}\n${downloadRoute}`, /connectionIdentity|appKey|platformKey|authorizedPlatformKey/);
});

test("route owns responsive and print-safe styling without shared CSS edits", () => {
  assert.match(page, /styles\.module\.css/);
  assert.match(styles, /@media \(max-width: 800px\)/);
  assert.match(styles, /@media print/);
  assert.match(styles, /break-inside: avoid/);
  assert.match(page, /className=\{styles\.skipLink\}/);
});

test("built route redirects anonymous users, hides denied users and renders only for an allowlisted maintainer", async () => {
  const keys = [
    "YIYUNYING_INTERNAL_DOWNLOAD_EMAILS",
    "YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS",
    "YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET",
    "YIYUNYING_INTERNAL_DOWNLOAD_ORIGIN",
  ];
  const previous = Object.fromEntries(keys.map((key) => [key, process.env[key]]));
  const signingSecret = "01".repeat(32);
  process.env.YIYUNYING_INTERNAL_DOWNLOAD_EMAILS = "maintainer@example.com";
  process.env.YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS = "user_Maintainer_123";
  process.env.YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET = signingSecret;
  process.env.YIYUNYING_INTERNAL_DOWNLOAD_ORIGIN = "https://appht.jjmxg.xyz";
  try {
    const workerUrl = new URL("../dist/server/index.js", import.meta.url);
    workerUrl.searchParams.set("internal-auth", `${process.pid}-${Date.now()}-${Math.random()}`);
    const { default: worker } = await import(workerUrl.href);
    const env = {
      ASSETS: { fetch: async () => new Response("Not found", { status: 404 }) },
    };
    const ctx = { waitUntil() {}, passThroughOnException() {} };
    const request = (path = "/internal-downloads", headers = {}) => worker.fetch(
      new Request(`http://localhost${path}`, {
        headers: { accept: "text/html", ...headers },
      }),
      env,
      ctx,
    );

    const anonymous = await request();
    assert.equal(anonymous.status, 307);
    assert.match(anonymous.headers.get("location") ?? "", /^http:\/\/localhost\/signin-with-chatgpt\?return_to=/);

    const denied = await request("/internal-downloads", {
      "oai-authenticated-user-id": "user_Maintainer_123",
      "oai-authenticated-user-email": "other@example.com",
    });
    assert.equal(denied.status, 404);

    const wrongUserId = await request("/internal-downloads", {
      "oai-authenticated-user-id": "user_maintainer_123",
      "oai-authenticated-user-email": "maintainer@example.com",
    });
    assert.equal(wrongUserId.status, 404);

    const maintainerHeaders = {
      "oai-authenticated-user-id": "user_Maintainer_123",
      "oai-authenticated-user-email": "maintainer@example.com",
      "oai-authenticated-user-full-name": "Release%20Maintainer",
      "oai-authenticated-user-full-name-encoding": "percent-encoded-utf-8",
    };
    const allowed = await request("/internal-downloads", maintainerHeaders);
    assert.equal(allowed.status, 200);
    assert.match(allowed.headers.get("cache-control") ?? "", /no-store/);
    const html = await allowed.text();
    for (const expected of [
      "内部下载中心",
      "Release Maintainer",
      "Debug 测试包",
      "Stable Release 候选",
      "最终正式版",
      "短时安全下载",
      "短时地址在过期前仍可能被转发，请勿分享",
    ]) assert.ok(html.includes(expected), `allowlisted page missing: ${expected}`);
    assert.doesNotMatch(html, /href=["'][^"']*\/downloads\//i);
    assert.doesNotMatch(html, /https:\/\/appht\.jjmxg\.xyz\/__internal-apks/i);
    assert.doesNotMatch(html, new RegExp(signingSecret, "i"));
    assert.doesNotMatch(html, /source-v|git-history|project-delivery|projectAssets|connectionIdentity/i);

    for (const release of currentStableReleases) {
      for (const safeValue of [
        release.fileName,
        release.versionName,
        release.size,
        release.sha256,
      ]) assert.ok(html.includes(String(safeValue)), `current Stable safe field missing: ${release.id}`);
      assert.doesNotMatch(html, new RegExp(escapeRegExp(release.packageName), "i"));
      assert.doesNotMatch(html, new RegExp(escapeRegExp(release.signerSha256), "i"));
    }

    const actionLinks = html.match(/href="\/internal-downloads\/download\?channel=(?:debug|candidate)&amp;role=(?:user|admin|authorized|owner)"/g) ?? [];
    const expectedActionCount = currentReleaseMetadata.finalizationStatus === "pending" ? 8 : 4;
    assert.equal(actionLinks.length, expectedActionCount, "only Debug and pending Stable four-role actions should render");

    const before = Math.floor(Date.now() / 1000);
    const download = await request(
      "/internal-downloads/download?channel=debug&role=user",
      maintainerHeaders,
    );
    const after = Math.floor(Date.now() / 1000);
    assert.equal(download.status, 302);
    assert.match(download.headers.get("cache-control") ?? "", /no-store/);
    const location = new URL(download.headers.get("location") ?? "");
    assert.equal(location.origin, "https://appht.jjmxg.xyz");
    assert.equal(location.pathname, "/__internal-apks/debug/2.7.15/yiyunying-user-v2.7.15-debug.apk");
    const expires = Number(location.searchParams.get("expires"));
    assert.ok(expires >= before + 300 && expires <= after + 300);
    const expectedSignature = createHmac("sha256", Buffer.from(signingSecret, "hex"))
      .update(`${expires}\n${location.pathname}`, "utf8")
      .digest("base64url");
    assert.equal(location.searchParams.get("sig"), expectedSignature);
    assert.doesNotMatch(location.href, new RegExp(signingSecret, "i"));

    const currentOwner = currentStableReleases.find((release) => release.id === "owner");
    assert.ok(currentOwner, "current Stable owner release is required");
    const candidate = await request(
      "/internal-downloads/download?channel=candidate&role=owner",
      maintainerHeaders,
    );
    if (currentReleaseMetadata.finalizationStatus === "pending") {
      assert.equal(candidate.status, 302);
      assert.equal(
        new URL(candidate.headers.get("location") ?? "").pathname,
        `/__internal-apks/candidate/${currentReleaseMetadata.versionName}/${currentOwner.fileName}`,
      );
    } else {
      assert.equal(candidate.status, 404);
    }

    for (const invalidPath of [
      "/internal-downloads/download?channel=final&role=user",
      "/internal-downloads/download?channel=debug&role=unknown",
      "/internal-downloads/download?channel=debug&role=user&file=arbitrary.apk",
    ]) {
      assert.equal((await request(invalidPath, maintainerHeaders)).status, 404, invalidPath);
    }

    delete process.env.YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET;
    const unconfigured = await request("/internal-downloads", maintainerHeaders);
    assert.equal(unconfigured.status, 200);
    const unconfiguredHtml = await unconfigured.text();
    assert.doesNotMatch(unconfiguredHtml, /href="\/internal-downloads\/download\?/);
    assert.equal(
      (await request("/internal-downloads/download?channel=debug&role=user", maintainerHeaders)).status,
      404,
    );

    process.env.YIYUNYING_INTERNAL_DOWNLOAD_SIGNING_SECRET = signingSecret;
    delete process.env.YIYUNYING_INTERNAL_DOWNLOAD_USER_IDS;
    assert.equal((await request("/internal-downloads", maintainerHeaders)).status, 404);
  } finally {
    for (const key of keys) {
      if (previous[key] === undefined) delete process.env[key];
      else process.env[key] = previous[key];
    }
  }
});
