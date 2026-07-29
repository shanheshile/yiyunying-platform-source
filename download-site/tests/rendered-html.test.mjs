import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const releaseMetadata = JSON.parse(
  await readFile(new URL("../release-metadata.json", import.meta.url), "utf8"),
);

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
  assert.match(html, new RegExp(releaseMetadata.versionName.replaceAll(".", "\\.")));
  assert.doesNotMatch(html, /2\.6\.36/);
  assert.doesNotMatch(html, /2\.6\.35/);
  assert.doesNotMatch(html, /2\.6\.34/);
  assert.doesNotMatch(html, /codex-preview|Your site is taking shape/i);
});

test("keeps release metadata and download links consistent", async () => {
  const [page, layout, packageJson, exporter, nginx] = await Promise.all([
    readFile(new URL("../app/page.tsx", import.meta.url), "utf8"),
    readFile(new URL("../app/layout.tsx", import.meta.url), "utf8"),
    readFile(new URL("../package.json", import.meta.url), "utf8"),
    readFile(new URL("../scripts/export-static.mjs", import.meta.url), "utf8"),
    readFile(new URL("../deploy/nginx-download-center.conf", import.meta.url), "utf8"),
  ]);

  assert.match(page, /import releaseMetadata from "\.\.\/release-metadata\.json"/);
  assert.match(exporter, /release-metadata\.json/);
  assert.doesNotMatch(page, /const VERSION = "\d+\.\d+\.\d+"/);
  assert.equal(releaseMetadata.releases.length, 4);
  for (const release of releaseMetadata.releases) {
    assert.match(page, /releaseMetadata\.releases/);
    assert.match(exporter, /releaseMetadata\.releases/);
    assert.match(release.fileName, new RegExp(`v${releaseMetadata.versionName.replaceAll(".", "\\.")}`));
    assert.match(release.sha256, /^[A-F0-9]{64}$/);
    assert.ok(release.sizeBytes > 0);
  }
  assert.match(layout, /title:\s*"易运盈官方下载中心"/);
  assert.match(packageJson, /"lucide-react":\s*"[^"]+"/);
  assert.match(page, /\?sha256=/);
  assert.match(page, /download=\{selected\.fileName\}/);
  assert.match(exporter, /downloadButton\.download = current\.fileName/);
  assert.match(nginx, /no-store, no-cache, must-revalidate/);
  assert.match(nginx, /application\/vnd\.android\.package-archive/);
  assert.match(nginx, /immutable, no-transform/);
});
