import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const releaseMetadata = JSON.parse(
  await readFile(new URL("../release-metadata.json", import.meta.url), "utf8"),
);
const packageMetadata = JSON.parse(
  await readFile(new URL("../package.json", import.meta.url), "utf8"),
);

function escapedVersion(value) {
  return value.replaceAll(".", "\\.");
}

function normalizedReleaseNotes(value) {
  return Array.isArray(value) ? value : [value];
}

function assertProductionDownloadRoot(value) {
  assert.equal(typeof value, "string");
  assert.ok(value.length > 0, "downloadRootBase must not be empty");
  if (value === "/downloads") return;

  const parsed = new URL(value);
  assert.match(parsed.protocol, /^https?:$/);
  assert.ok(
    !["localhost", "127.0.0.1", "0.0.0.0", "::1"].includes(parsed.hostname),
    "downloadRootBase must not point to a local machine",
  );
}

async function render() {
  const workerUrl = new URL("../dist/server/index.js", import.meta.url);
  workerUrl.searchParams.set("test", `${process.pid}-${Date.now()}`);
  const { default: worker } = await import(workerUrl.href);

  return worker.fetch(
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
}

test("server-renders the download center", async () => {
  const response = await render();
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") ?? "", /^text\/html\b/i);

  const html = await response.text();
  assert.match(html, /<title>易运盈官方下载中心<\/title>/i);
  assert.match(html, /四个版本均已发布/);
  assert.match(html, /易运盈用户端/);
  assert.match(html, />管理员</);
  assert.match(html, />授权平台</);
  assert.match(html, />平台总控</);
  assert.match(html, new RegExp(escapedVersion(releaseMetadata.versionName)));
  assert.doesNotMatch(html, /2\.6\.36/);
  assert.doesNotMatch(html, /2\.6\.35/);
  assert.doesNotMatch(html, /2\.6\.34/);
  assert.doesNotMatch(html, /codex-preview|Your site is taking shape/i);
});

test("keeps release metadata and download links consistent", async () => {
  const [page, layout, exporter, nginx] = await Promise.all([
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/layout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
    readFile(new URL("../deploy/nginx-download-center.conf", import.meta.url), "utf8"),
  ]);

  assert.equal(packageMetadata.version, releaseMetadata.versionName);
  assertProductionDownloadRoot(releaseMetadata.downloadRootBase);

  const notes = normalizedReleaseNotes(releaseMetadata.releaseNotes);
  assert.ok(notes.length > 0, "release notes must not be empty");
  for (const note of notes) {
    assert.equal(typeof note, "string");
    assert.ok(note.trim().length > 0, "release note must not be blank");
    assert.ok(!note.includes("\uFFFD"), "release note contains a replacement character");
  }

  assert.match(page, /import releaseMetadata from "\.\.\/release-metadata\.json"/);
  assert.match(exporter, /release-metadata\.json/);
  assert.doesNotMatch(page, /const VERSION = "\d+\.\d+\.\d+"/);

  assert.equal(releaseMetadata.releases.length, 4);
  assert.equal(new Set(releaseMetadata.releases.map(({ id }) => id)).size, 4);
  assert.equal(new Set(releaseMetadata.releases.map(({ fileName }) => fileName)).size, 4);

  for (const release of releaseMetadata.releases) {
    assert.match(page, /releaseMetadata\.releases/);
    assert.match(exporter, /releaseMetadata\.releases/);
    assert.match(
      release.fileName,
      new RegExp(`v${escapedVersion(releaseMetadata.versionName)}.*\\.apk$`, "i"),
    );
    assert.equal(release.versionCode, releaseMetadata.versionCode);
    assert.match(release.versionName, new RegExp(`^${escapedVersion(releaseMetadata.versionName)}-`));
    assert.match(release.packageName, /^xyz\.jjmxg\.yiyunying\./);
    assert.match(release.sha256, /^[A-F0-9]{64}$/);
    assert.ok(release.sizeBytes > 1024 * 1024, "APK must be larger than 1 MiB");
  }

  const projectAssets = Array.isArray(releaseMetadata.projectAssets)
    ? releaseMetadata.projectAssets
    : [];
  if (releaseMetadata.schemaVersion >= 3) {
    assert.equal(projectAssets.length, 4);
    assert.deepEqual(
      new Set(projectAssets.map(({ id }) => id)),
      new Set(["source", "history", "delivery", "manifest"]),
    );
    assert.equal(new Set(projectAssets.map(({ fileName }) => fileName)).size, 4);
  } else {
    assert.equal(projectAssets.length, 0);
  }
  for (const asset of projectAssets) {
    assert.match(page, /releaseMetadata as \{ projectAssets\?: ProjectAsset\[\] \}/);
    assert.ok(asset.label.trim().length > 0);
    assert.ok(asset.description.trim().length > 0);
  }

  assert.match(layout, /title:\s*"易运盈官方下载中心"/);
  assert.match(packageMetadata.dependencies["lucide-react"], /.+/);
  assert.match(page, /\?sha256=/);
  assert.match(page, /download=\{selected\.fileName\}/);
  assert.match(page, /id="project-files"/);
  assert.match(page, /PROJECT_ASSETS\.map/);
  assert.match(exporter, /downloadButton\.download = current\.fileName/);
  assert.match(nginx, /no-store, no-cache, must-revalidate/);
  assert.match(nginx, /application\/vnd\.android\.package-archive/);
  assert.match(nginx, /immutable, no-transform/);
});
