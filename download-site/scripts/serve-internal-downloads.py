#!/usr/bin/env python3
"""Serve a verified four-APK download page on a loopback-only random URL."""

from __future__ import annotations

import argparse
from dataclasses import dataclass
import hashlib
import html
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import secrets
import socket
import sys
from urllib.parse import urlsplit


ROLE_ORDER = ("user", "admin", "authorized", "owner")
ROLE_LABELS = {
    "user": "用户端",
    "admin": "管理员端",
    "authorized": "授权平台端",
    "owner": "平台总控端",
}
ROLE_FILE_STEMS = {
    "user": "user",
    "admin": "admin",
    "authorized": "authorized-platform",
    "owner": "platform-owner",
}
ROLE_VERSION_SUFFIXES = ROLE_FILE_STEMS
STABLE_PACKAGE_NAMES = {
    "user": "xyz.jjmxg.yiyunying.user",
    "admin": "xyz.jjmxg.yiyunying.admin",
    "authorized": "xyz.jjmxg.yiyunying.authorized",
    "owner": "xyz.jjmxg.yiyunying.platformowner",
}
ALLOWED_HOSTS = {"127.0.0.1", "::1"}
SHA256_RE = re.compile(r"^[0-9a-fA-F]{64}$")
SAFE_APK_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,254}\.apk$")
SESSION_RE = re.compile(r"^[A-Za-z0-9_-]{32,128}$")
SECURITY_HEADERS = {
    "Cache-Control": "no-store, max-age=0",
    "Pragma": "no-cache",
    "X-Content-Type-Options": "nosniff",
    "X-Robots-Tag": "noindex, nofollow, noarchive",
    "Referrer-Policy": "no-referrer",
    "Content-Security-Policy": (
        "default-src 'none'; style-src 'unsafe-inline'; img-src 'none'; "
        "base-uri 'none'; frame-ancestors 'none'; form-action 'none'"
    ),
}


@dataclass(frozen=True)
class ApkArtifact:
    role: str
    label: str
    file_name: str
    path: Path
    size: int
    sha256: str
    version_name: str


@dataclass(frozen=True)
class DownloadCatalog:
    version_name: str
    version_code: int
    channel: str
    finalization_status: str
    status_label: str
    artifacts: tuple[ApkArtifact, ...]


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def publication_label(channel: str, finalization_status: str) -> str:
    if channel == "Debug":
        return "Debug 非生产测试版"
    if finalization_status == "finalized":
        return "Stable 正式版（已 Finalize）"
    return "Release candidate（待完成发布）"


def load_catalog(manifest_path: Path) -> DownloadCatalog:
    if not manifest_path.is_file() or manifest_path.is_symlink():
        raise RuntimeError("--manifest must be a regular, non-symlink JSON file")
    manifest_path = manifest_path.resolve()
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Cannot read release manifest: {exc}") from exc
    if not isinstance(manifest, dict):
        raise RuntimeError("Release manifest must be a JSON object")

    entries = manifest.get("releases")
    legacy_debug = (
        manifest.get("channel") in (None, "")
        and isinstance(entries, list)
        and len(entries) == len(ROLE_ORDER)
        and all(
            isinstance(entry, dict)
            and str(entry.get("fileName", "")).endswith("-debug.apk")
            and str(entry.get("versionName", "")).endswith("-debug")
            and str(entry.get("packageName", "")).endswith(".debug")
            for entry in entries
        )
    )
    channel = "Debug" if legacy_debug else manifest.get("channel")
    if channel not in {"Debug", "Stable"}:
        raise RuntimeError("Release manifest channel must be Debug or Stable")
    finalization_status = (
        "finalized" if legacy_debug and manifest.get("finalizationStatus") in (None, "")
        else manifest.get("finalizationStatus")
    )
    if finalization_status not in {"pending", "finalized"}:
        raise RuntimeError("Release manifest finalizationStatus must be pending or finalized")
    try:
        version_code = int(manifest.get("versionCode"))
    except (TypeError, ValueError) as exc:
        raise RuntimeError("Release manifest versionCode must be a positive integer") from exc
    version_name = manifest.get("versionName")
    if (
        not isinstance(version_name, str)
        or not version_name.strip()
        or version_name != version_name.strip()
        or re.fullmatch(r"\d+\.\d+\.\d+", version_name) is None
        or version_code < 1
    ):
        raise RuntimeError("Release manifest version identity is invalid")

    if not isinstance(entries, list) or len(entries) != len(ROLE_ORDER):
        raise RuntimeError("Release manifest must contain exactly four APK entries")
    by_role: dict[str, dict] = {}
    for entry in entries:
        if not isinstance(entry, dict):
            raise RuntimeError("Each APK entry must be an object")
        role = entry.get("id")
        if role not in ROLE_ORDER or role in by_role:
            raise RuntimeError("APK entries must use four unique supported role ids")
        by_role[role] = entry
    if set(by_role) != set(ROLE_ORDER):
        raise RuntimeError("Release manifest does not contain the complete four-role APK set")

    release_root = manifest_path.parent.resolve()
    artifacts: list[ApkArtifact] = []
    for role in ROLE_ORDER:
        entry = by_role[role]
        file_name = entry.get("fileName")
        if not isinstance(file_name, str) or SAFE_APK_RE.fullmatch(file_name) is None:
            raise RuntimeError(f"{role} fileName must be a safe APK basename")
        if Path(file_name).name != file_name:
            raise RuntimeError(f"{role} fileName may not contain a path")
        candidate = release_root / file_name
        if candidate.is_symlink():
            raise RuntimeError(f"{role} APK must be a regular file beside the manifest")
        path = candidate.resolve()
        if path.parent != release_root or not path.is_file():
            raise RuntimeError(f"{role} APK must be a regular file beside the manifest")
        try:
            expected_size = int(entry.get("sizeBytes"))
        except (TypeError, ValueError) as exc:
            raise RuntimeError(f"{role} sizeBytes is invalid") from exc
        expected_hash = entry.get("sha256")
        if expected_size < 1 or path.stat().st_size != expected_size:
            raise RuntimeError(f"{role} APK size does not match the manifest")
        if not isinstance(expected_hash, str) or SHA256_RE.fullmatch(expected_hash) is None:
            raise RuntimeError(f"{role} SHA-256 is invalid")
        digest = file_sha256(path)
        if not secrets.compare_digest(digest, expected_hash.lower()):
            raise RuntimeError(f"{role} APK SHA-256 does not match the manifest")
        entry_code = entry.get("versionCode")
        if entry_code is None or str(entry_code) != str(version_code):
            raise RuntimeError(f"{role} APK versionCode does not match the manifest")
        embedded_version = entry.get("versionName", version_name)
        if not isinstance(embedded_version, str) or not embedded_version:
            raise RuntimeError(f"{role} versionName is invalid")
        debug_suffix = "-debug" if channel == "Debug" else ""
        expected_file = (
            f"yiyunying-{ROLE_FILE_STEMS[role]}-v{version_name}{debug_suffix}.apk"
        )
        expected_version = (
            f"{version_name}-{ROLE_VERSION_SUFFIXES[role]}{debug_suffix}"
        )
        expected_package = STABLE_PACKAGE_NAMES[role] + debug_suffix.replace("-", ".")
        if file_name != expected_file or embedded_version != expected_version:
            raise RuntimeError(f"{role} APK role/version identity is inconsistent")
        if entry.get("packageName") != expected_package:
            raise RuntimeError(f"{role} APK packageName is inconsistent")
        artifacts.append(
            ApkArtifact(
                role=role,
                label=ROLE_LABELS[role],
                file_name=file_name,
                path=path,
                size=expected_size,
                sha256=digest,
                version_name=embedded_version,
            )
        )

    return DownloadCatalog(
        version_name=version_name,
        version_code=version_code,
        channel=channel,
        finalization_status=finalization_status,
        status_label=publication_label(channel, finalization_status),
        artifacts=tuple(artifacts),
    )


def parse_range(value: str, size: int) -> tuple[int, int]:
    if not value.startswith("bytes=") or "," in value:
        raise ValueError("Only one byte range is supported")
    spec = value[6:]
    if "-" not in spec:
        raise ValueError("Invalid byte range")
    start_text, end_text = spec.split("-", 1)
    try:
        if start_text == "":
            suffix = int(end_text)
            if suffix < 1:
                raise ValueError("Invalid suffix range")
            start = max(0, size - suffix)
            end = size - 1
        else:
            start = int(start_text)
            end = size - 1 if end_text == "" else int(end_text)
            if start < 0 or start >= size or end < start:
                raise ValueError("Unsatisfiable range")
            end = min(end, size - 1)
    except (TypeError, ValueError) as exc:
        raise ValueError("Invalid byte range") from exc
    return start, end


def render_index(catalog: DownloadCatalog, session_prefix: str) -> bytes:
    cards = []
    for artifact in catalog.artifacts:
        cards.append(
            f"""<article><h2>{html.escape(artifact.label)}</h2>
<p><strong>{html.escape(artifact.file_name)}</strong></p>
<p>包内版本：{html.escape(artifact.version_name)} · 大小：{artifact.size:,} 字节</p>
<code>SHA-256 {artifact.sha256.upper()}</code>
<a href="{session_prefix}apk/{artifact.role}">下载 APK</a></article>"""
        )
    document = f"""<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive"><title>本机内部下载</title>
<style>body{{font:15px/1.6 system-ui;margin:0;background:#f4f6f8;color:#172033}}main{{max-width:920px;margin:auto;padding:32px 18px}}header,article,aside{{background:#fff;border:1px solid #dce2e8;border-radius:14px;padding:18px;margin:12px 0}}section{{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}}article{{margin:0}}code{{display:block;overflow-wrap:anywhere;font-size:12px;background:#f5f7fa;padding:10px;border-radius:8px}}a{{display:inline-block;margin-top:14px;padding:9px 14px;border-radius:9px;background:#1769e0;color:#fff;text-decoration:none}}.status{{font-weight:700;color:#934b00}}</style></head>
<body><main><header><p class="status">{html.escape(catalog.status_label)}</p>
<h1>易云盈内部四端下载</h1><p>版本 {html.escape(catalog.version_name)} · versionCode {catalog.version_code} · {html.escape(catalog.channel)}</p></header>
<aside><strong>安装说明</strong><p>首次安装前请核对角色、文件大小和 SHA-256。覆盖升级必须保持相同包名与签名，并确保目标 versionCode 更高；不要卸载旧版或使用降级参数，以免丢失本地数据。Debug 与正式包可能使用不同包名，不能冒充原地升级。</p></aside>
<section>{''.join(cards)}</section></main></body></html>"""
    return document.encode("utf-8")


class InternalDownloadServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = False


class InternalDownloadServerV6(InternalDownloadServer):
    address_family = socket.AF_INET6


class InternalDownloadHandler(BaseHTTPRequestHandler):
    server_version = "YiyunyingInternalDownloads"
    sys_version = ""

    def log_message(self, format: str, *args: object) -> None:
        return

    def end_headers(self) -> None:
        for name, value in SECURITY_HEADERS.items():
            self.send_header(name, value)
        super().end_headers()

    def do_GET(self) -> None:  # noqa: N802
        self._serve(send_body=True)

    def do_HEAD(self) -> None:  # noqa: N802
        self._serve(send_body=False)

    def do_POST(self) -> None:  # noqa: N802
        self._method_not_allowed()

    def _method_not_allowed(self) -> None:
        self.send_response(HTTPStatus.METHOD_NOT_ALLOWED)
        self.send_header("Allow", "GET, HEAD")
        self.send_header("Content-Length", "0")
        self.end_headers()

    def _serve(self, *, send_body: bool) -> None:
        server = self.server
        assert isinstance(server, InternalDownloadServer)
        path = urlsplit(self.path).path
        prefix = f"/s/{server.session_token}/"
        if path == prefix:
            body = server.index_html
            self.send_response(HTTPStatus.OK)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            if send_body:
                self.wfile.write(body)
            return
        role_prefix = prefix + "apk/"
        if not path.startswith(role_prefix):
            self._not_found()
            return
        role = path[len(role_prefix) :]
        artifact = server.artifacts_by_role.get(role)
        if artifact is None or "/" in role:
            self._not_found()
            return
        self._serve_apk(artifact, send_body=send_body)

    def _serve_apk(self, artifact: ApkArtifact, *, send_body: bool) -> None:
        if artifact.path.is_symlink():
            self._artifact_changed()
            return
        try:
            stream = artifact.path.open("rb")
        except OSError:
            self._artifact_changed()
            return
        with stream:
            stat_result = os.fstat(stream.fileno())
            digest = hashlib.sha256()
            for chunk in iter(lambda: stream.read(1024 * 1024), b""):
                digest.update(chunk)
            actual_hash = digest.hexdigest()
            if stat_result.st_size != artifact.size or not secrets.compare_digest(
                actual_hash, artifact.sha256
            ):
                self._artifact_changed()
                return
            stream.seek(0)
            etag = f'"sha256-{artifact.sha256}"'
            if self.headers.get("If-None-Match") == etag:
                self.send_response(HTTPStatus.NOT_MODIFIED)
                self.send_header("ETag", etag)
                self.send_header("Content-Length", "0")
                self.end_headers()
                return
            start, end = 0, artifact.size - 1
            range_header = self.headers.get("Range")
            if range_header:
                try:
                    start, end = parse_range(range_header, artifact.size)
                except ValueError:
                    self.send_response(HTTPStatus.REQUESTED_RANGE_NOT_SATISFIABLE)
                    self.send_header("Content-Range", f"bytes */{artifact.size}")
                    self.send_header("Content-Length", "0")
                    self.end_headers()
                    return
            length = end - start + 1
            self.send_response(
                HTTPStatus.PARTIAL_CONTENT if range_header else HTTPStatus.OK
            )
            self.send_header("Content-Type", "application/vnd.android.package-archive")
            self.send_header(
                "Content-Disposition", f'attachment; filename="{artifact.file_name}"'
            )
            self.send_header("Accept-Ranges", "bytes")
            self.send_header("ETag", etag)
            if range_header:
                self.send_header("Content-Range", f"bytes {start}-{end}/{artifact.size}")
            self.send_header("Content-Length", str(length))
            self.end_headers()
            if not send_body:
                return
            stream.seek(start)
            remaining = length
            while remaining:
                chunk = stream.read(min(1024 * 1024, remaining))
                if not chunk:
                    break
                self.wfile.write(chunk)
                remaining -= len(chunk)

    def _not_found(self) -> None:
        self.send_response(HTTPStatus.NOT_FOUND)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def _artifact_changed(self) -> None:
        self.send_response(HTTPStatus.CONFLICT)
        self.send_header("Content-Length", "0")
        self.end_headers()


def create_server(
    catalog: DownloadCatalog,
    host: str = "127.0.0.1",
    port: int = 0,
    *,
    session_token: str | None = None,
) -> InternalDownloadServer:
    if host not in ALLOWED_HOSTS:
        raise RuntimeError("Internal downloads may bind only to 127.0.0.1 or ::1")
    if not isinstance(port, int) or port < 0 or port > 65535:
        raise RuntimeError("Port must be between 0 and 65535")
    token = session_token or secrets.token_urlsafe(32)
    if SESSION_RE.fullmatch(token) is None:
        raise RuntimeError("Session token is invalid")
    server_type = InternalDownloadServerV6 if host == "::1" else InternalDownloadServer
    server = server_type((host, port), InternalDownloadHandler)
    server.catalog = catalog
    server.session_token = token
    server.session_prefix = f"/s/{token}/"
    server.index_html = render_index(catalog, server.session_prefix)
    server.artifacts_by_role = {item.role: item for item in catalog.artifacts}
    return server


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, type=Path)
    parser.add_argument("--host", default="127.0.0.1", choices=sorted(ALLOWED_HOSTS))
    parser.add_argument("--port", type=int, default=0)
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    catalog = load_catalog(args.manifest)
    server = create_server(catalog, args.host, args.port)
    address = server.server_address
    actual_port = int(address[1])
    display_host = "[::1]" if args.host == "::1" else "127.0.0.1"
    print(
        f"Internal downloads ready: http://{display_host}:{actual_port}{server.session_prefix}",
        flush=True,
    )
    try:
        server.serve_forever(poll_interval=0.25)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"Internal download server failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        raise SystemExit(1)
