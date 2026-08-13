#!/usr/bin/env python3
"""Private loopback credential console for the local Windows desktop export.

The server binds only to 127.0.0.1, requires an in-memory bearer token or its
HttpOnly session cookie for every credential-bearing request, never logs request
bodies, and persists data only below its configured root. The credential-free
JavaScript bundle is the sole anonymous resource. The root is prepared with a
CurrentUser + SYSTEM protected ACL by the PowerShell exporter.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import secrets
import shutil
import stat
import threading
import time
import urllib.parse
import webbrowser
from datetime import datetime, timezone
from http import HTTPStatus
from http.cookies import SimpleCookie
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any


MAX_BODY_BYTES = 8 * 1024 * 1024
MAX_ACCOUNTS = 20_000
MAX_BACKUPS = 100
STATE_FILE = "账号总表.json"
HTML_FILE = "账号管理.html"
TEST_HTML_FILE = "测试账号.html"
JS_FILE = "credential-console.js"
BACKUP_DIR = "Backups"
DERIVED_DIR = "JSON"
MANIFEST_FILE = ".managed-manifest.json"
ALLOWED_ENVIRONMENTS = {"production", "test", "unknown"}
ALLOWED_STATUSES = {"active", "disabled", "inactive"}
RECORD_ID_RE = re.compile(r"^[a-f0-9]{32,64}$")
BACKUP_NAME_RE = re.compile(r"^账号总表-\d{8}T\d{6}Z-[a-f0-9]{8}\.json$")


class ConsoleError(Exception):
    def __init__(self, message: str, status: int = HTTPStatus.BAD_REQUEST):
        super().__init__(message)
        self.status = int(status)


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def _is_reparse(path: Path) -> bool:
    info = os.lstat(path)
    attributes = int(getattr(info, "st_file_attributes", 0))
    reparse_flag = int(getattr(stat, "FILE_ATTRIBUTE_REPARSE_POINT", 0x400))
    return stat.S_ISLNK(info.st_mode) or bool(attributes & reparse_flag)


def assert_private_tree_shape(root: Path) -> None:
    if not root.exists() or not root.is_dir():
        raise ConsoleError("Credential console root is missing.", HTTPStatus.INTERNAL_SERVER_ERROR)
    if _is_reparse(root):
        raise ConsoleError("Credential console root must not be a reparse point.", HTTPStatus.FORBIDDEN)
    for name in (STATE_FILE, HTML_FILE, TEST_HTML_FILE, JS_FILE, BACKUP_DIR, DERIVED_DIR):
        candidate = root / name
        if candidate.exists() and _is_reparse(candidate):
            raise ConsoleError(f"Managed path is a reparse point: {name}", HTTPStatus.FORBIDDEN)


def _string(value: Any, field: str, *, allow_empty: bool = False, maximum: int = 16_384) -> str:
    if not isinstance(value, str):
        raise ConsoleError(f"{field} must be a string.")
    if len(value) > maximum:
        raise ConsoleError(f"{field} is too long.")
    if not allow_empty and not value.strip():
        raise ConsoleError(f"{field} must not be empty.")
    if "\x00" in value:
        raise ConsoleError(f"{field} contains an invalid character.")
    return value


def validate_account(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ConsoleError("Each account must be an object.")
    allowed = {
        "recordId", "platform", "software", "role", "packageClass", "accountId",
        "loginAccount", "password", "appId", "adminId", "appSecret", "status",
        "environment", "canLogin", "loginEvidence", "deleted", "notes",
        "createdAtUtc", "updatedAtUtc",
    }
    unexpected = set(value) - allowed
    if unexpected:
        raise ConsoleError("Account contains unsupported fields.")
    account: dict[str, Any] = {}
    account["recordId"] = _string(value.get("recordId"), "recordId", maximum=64)
    if not RECORD_ID_RE.fullmatch(account["recordId"]):
        raise ConsoleError("recordId is invalid.")
    for key, maximum in (
        ("platform", 256), ("software", 128), ("role", 128), ("packageClass", 64),
        ("accountId", 512), ("loginAccount", 1024), ("password", 4096),
    ):
        account[key] = _string(value.get(key), key, maximum=maximum)
    for key, maximum in (("appId", 512), ("adminId", 512), ("appSecret", 4096), ("notes", 4096)):
        raw = value.get(key)
        if raw is None:
            account[key] = None if key != "notes" else ""
        else:
            account[key] = _string(raw, key, allow_empty=True, maximum=maximum)
    status_value = _string(value.get("status"), "status", maximum=32).lower()
    if status_value not in ALLOWED_STATUSES:
        raise ConsoleError("status is invalid.")
    account["status"] = status_value
    environment = _string(value.get("environment"), "environment", maximum=32).lower()
    if environment not in ALLOWED_ENVIRONMENTS:
        raise ConsoleError("environment is invalid.")
    disabled = status_value != "active"
    account["environment"] = environment
    if not isinstance(value.get("canLogin"), bool):
        raise ConsoleError("canLogin must be boolean.")
    account["canLogin"] = bool(value["canLogin"]) and not disabled
    account["loginEvidence"] = _string(
        value.get("loginEvidence"), "loginEvidence", allow_empty=True, maximum=256
    )
    if not isinstance(value.get("deleted"), bool):
        raise ConsoleError("deleted must be boolean.")
    account["deleted"] = bool(value["deleted"])
    for key in ("createdAtUtc", "updatedAtUtc"):
        account[key] = _string(value.get(key), key, maximum=64)
    return account


def validate_state(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ConsoleError("Credential state must be an object.")
    allowed = {"schemaVersion", "title", "revision", "createdAtUtc", "updatedAtUtc", "source", "accounts"}
    if set(value) - allowed:
        raise ConsoleError("Credential state contains unsupported fields.")
    if value.get("schemaVersion") != 1:
        raise ConsoleError("Unsupported credential state schema.")
    revision = value.get("revision")
    if not isinstance(revision, int) or isinstance(revision, bool) or revision < 1:
        raise ConsoleError("revision must be a positive integer.")
    raw_accounts = value.get("accounts")
    if not isinstance(raw_accounts, list) or len(raw_accounts) > MAX_ACCOUNTS:
        raise ConsoleError("accounts must be a bounded array.")
    accounts = [validate_account(item) for item in raw_accounts]
    record_ids = [item["recordId"] for item in accounts]
    if len(record_ids) != len(set(record_ids)):
        raise ConsoleError("Duplicate recordId values are not allowed.")
    source = value.get("source")
    if not isinstance(source, dict):
        raise ConsoleError("source must be an object.")
    safe_source: dict[str, Any] = {}
    for key, raw in source.items():
        if key not in {"packageDirectory", "packageCount", "payloadCount", "batchIds", "exportedAtUtc"}:
            raise ConsoleError("source contains unsupported fields.")
        if key in {"packageCount", "payloadCount"}:
            if not isinstance(raw, int) or isinstance(raw, bool) or raw < 0:
                raise ConsoleError(f"source.{key} is invalid.")
            safe_source[key] = raw
        elif key == "batchIds":
            if not isinstance(raw, list) or len(raw) > 10_000:
                raise ConsoleError("source.batchIds is invalid.")
            safe_source[key] = [_string(item, "batchId", maximum=256) for item in raw]
        else:
            safe_source[key] = _string(raw, f"source.{key}", allow_empty=True, maximum=2048)
    return {
        "schemaVersion": 1,
        "title": _string(value.get("title"), "title", maximum=256),
        "revision": revision,
        "createdAtUtc": _string(value.get("createdAtUtc"), "createdAtUtc", maximum=64),
        "updatedAtUtc": _string(value.get("updatedAtUtc"), "updatedAtUtc", maximum=64),
        "source": safe_source,
        "accounts": accounts,
    }


def json_bytes(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def atomic_write(path: Path, data: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    if _is_reparse(path.parent):
        raise ConsoleError("Refusing to write through a reparse-point directory.", HTTPStatus.FORBIDDEN)
    temporary = path.parent / f".{path.name}.{secrets.token_hex(8)}.partial"
    try:
        with temporary.open("xb") as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass


def safe_slug(value: str) -> str:
    text = re.sub(r"[^\w.-]+", "_", value, flags=re.UNICODE).strip("._")[:48] or "item"
    digest = hashlib.sha256(value.encode("utf-8")).hexdigest()[:10]
    return f"{text}-{digest}"


def public_account(account: dict[str, Any]) -> dict[str, Any]:
    # The destination is explicitly a private plaintext credential export. Keep a
    # stable JSON shape so each per-software file is independently usable.
    return {key: account[key] for key in (
        "recordId", "platform", "software", "role", "packageClass", "accountId",
        "loginAccount", "password", "appId", "adminId", "appSecret", "status",
        "environment", "canLogin", "loginEvidence", "deleted", "notes",
        "createdAtUtc", "updatedAtUtc",
    )}


def derived_documents(state: dict[str, Any]) -> dict[str, bytes]:
    active = [item for item in state["accounts"] if not item["deleted"] and item["status"] == "active"]
    disabled = [item for item in state["accounts"] if not item["deleted"] and item["status"] != "active"]
    tests = [item for item in active if item["environment"] == "test"]
    production = [item for item in active if item["environment"] == "production"]
    unclassified = [item for item in active if item["environment"] == "unknown"]
    trash = [item for item in state["accounts"] if item["deleted"]]
    now = utc_now()
    documents: dict[str, bytes] = {}

    def add(relative: str, category: str, accounts: list[dict[str, Any]], explanation: str) -> None:
        documents[relative] = json_bytes({
            "schemaVersion": 1,
            "category": category,
            "generatedAtUtc": now,
            "explanation": explanation,
            "count": len(accounts),
            "accounts": [public_account(item) for item in accounts],
        })

    add("可登录账号.json", "active", active, "Source status is active; no live login was performed by this export.")
    add("生产账号.json", "production", production, "Only accounts explicitly classified as production are included.")
    add("测试账号.json", "test", tests, "Only accounts explicitly classified as test are included.")
    add("环境待确认账号.json", "unclassified", unclassified, "Active accounts without explicit production/test evidence.")
    add("已停用_不可登录.json", "disabled", disabled, "Disabled/inactive accounts are retained for recovery and must not be presented as login-ready.")
    add("回收站.json", "deleted", trash, "Soft-deleted records; use the console to undo or permanently remove them.")

    groups: dict[tuple[str, str, str, str], list[dict[str, Any]]] = {}
    for item in active + disabled:
        status_group = "可登录" if item["status"] == "active" else "不可登录"
        group_key = (status_group, item["platform"], item["software"], item["packageClass"] + "|" + (item["appId"] or "none"))
        groups.setdefault(group_key, []).append(item)
    for (status_group, platform, software, role_app), accounts in groups.items():
        relative = "/".join((
            status_group,
            safe_slug(platform),
            safe_slug(software),
            safe_slug(role_app) + ".json",
        ))
        add(
            relative,
            "active" if status_group == "可登录" else "disabled",
            accounts,
            "Grouped by platform, software, role and application. Status comes from the source package.",
        )
    return documents


def rebuild_derived(root: Path, state: dict[str, Any]) -> None:
    derived = root / DERIVED_DIR
    derived.mkdir(parents=True, exist_ok=True)
    if _is_reparse(derived):
        raise ConsoleError("Derived JSON directory is a reparse point.", HTTPStatus.FORBIDDEN)
    manifest_path = derived / MANIFEST_FILE
    previous: set[str] = set()
    if manifest_path.exists():
        if _is_reparse(manifest_path):
            raise ConsoleError("Derived manifest is a reparse point.", HTTPStatus.FORBIDDEN)
        try:
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            if isinstance(manifest, dict) and isinstance(manifest.get("files"), list):
                previous = {item for item in manifest["files"] if isinstance(item, str)}
        except (OSError, json.JSONDecodeError):
            previous = set()
    documents = derived_documents(state)
    current = set(documents)
    for relative, content in documents.items():
        parts = Path(relative).parts
        if not parts or any(part in {"", ".", ".."} for part in parts):
            raise ConsoleError("Unsafe derived path.")
        target = derived.joinpath(*parts)
        target.parent.mkdir(parents=True, exist_ok=True)
        if _is_reparse(target.parent):
            raise ConsoleError("Derived target parent is a reparse point.", HTTPStatus.FORBIDDEN)
        atomic_write(target, content)
    for relative in previous - current:
        parts = Path(relative).parts
        if parts and all(part not in {"", ".", ".."} for part in parts):
            target = derived.joinpath(*parts)
            if target.exists() and target.is_file() and not _is_reparse(target):
                target.unlink()
    atomic_write(manifest_path, json_bytes({"schemaVersion": 1, "files": sorted(current)}))


class CredentialStore:
    def __init__(self, root: Path):
        self.root = root.resolve(strict=True)
        self.state_path = self.root / STATE_FILE
        self.backup_dir = self.root / BACKUP_DIR
        self.lock = threading.RLock()
        assert_private_tree_shape(self.root)
        self.backup_dir.mkdir(parents=True, exist_ok=True)
        if _is_reparse(self.backup_dir):
            raise ConsoleError("Backup directory is a reparse point.", HTTPStatus.FORBIDDEN)
        self.load()

    def load(self) -> dict[str, Any]:
        with self.lock:
            assert_private_tree_shape(self.root)
            if not self.state_path.exists() or _is_reparse(self.state_path):
                raise ConsoleError("Credential state is missing or unsafe.", HTTPStatus.INTERNAL_SERVER_ERROR)
            try:
                raw = self.state_path.read_text(encoding="utf-8")
                return validate_state(json.loads(raw))
            except json.JSONDecodeError as exc:
                raise ConsoleError("Credential state is invalid JSON.", HTTPStatus.INTERNAL_SERVER_ERROR) from exc

    def _backup_current(self, current: dict[str, Any]) -> Path:
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
        backup = self.backup_dir / f"账号总表-{stamp}-{secrets.token_hex(4)}.json"
        atomic_write(backup, json_bytes(current))
        return backup

    def _prune_backups(self) -> None:
        files = sorted(
            (item for item in self.backup_dir.iterdir() if item.is_file() and BACKUP_NAME_RE.fullmatch(item.name)),
            key=lambda item: item.stat().st_mtime,
            reverse=True,
        )
        for item in files[MAX_BACKUPS:]:
            if not _is_reparse(item):
                item.unlink()

    def replace_accounts(self, expected_revision: int, accounts: Any) -> dict[str, Any]:
        if not isinstance(expected_revision, int) or isinstance(expected_revision, bool):
            raise ConsoleError("expectedRevision must be an integer.")
        if not isinstance(accounts, list):
            raise ConsoleError("accounts must be an array.")
        with self.lock:
            current = self.load()
            if expected_revision != current["revision"]:
                raise ConsoleError("The credential file changed; reload before saving.", HTTPStatus.CONFLICT)
            candidate = dict(current)
            candidate["revision"] = current["revision"] + 1
            candidate["updatedAtUtc"] = utc_now()
            candidate["accounts"] = accounts
            candidate = validate_state(candidate)
            backup = self._backup_current(current)
            try:
                atomic_write(self.state_path, json_bytes(candidate))
                rebuild_derived(self.root, candidate)
            except Exception:
                atomic_write(self.state_path, backup.read_bytes())
                rebuild_derived(self.root, current)
                raise
            self._prune_backups()
            return candidate

    def backups(self) -> list[dict[str, Any]]:
        with self.lock:
            result = []
            for item in sorted(self.backup_dir.iterdir(), key=lambda path: path.stat().st_mtime, reverse=True):
                if item.is_file() and BACKUP_NAME_RE.fullmatch(item.name) and not _is_reparse(item):
                    result.append({"name": item.name, "size": item.stat().st_size, "modifiedAtUtc": datetime.fromtimestamp(item.stat().st_mtime, timezone.utc).isoformat().replace("+00:00", "Z")})
            return result

    def restore(self, name: str, expected_revision: int) -> dict[str, Any]:
        if not isinstance(name, str) or not BACKUP_NAME_RE.fullmatch(name):
            raise ConsoleError("Invalid backup name.")
        backup = self.backup_dir / name
        if not backup.exists() or not backup.is_file() or _is_reparse(backup):
            raise ConsoleError("Backup was not found.", HTTPStatus.NOT_FOUND)
        try:
            restored = validate_state(json.loads(backup.read_text(encoding="utf-8")))
        except json.JSONDecodeError as exc:
            raise ConsoleError("Backup is invalid JSON.") from exc
        return self.replace_accounts(expected_revision, restored["accounts"])


class CredentialConsoleServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = False

    def __init__(self, address: tuple[str, int], store: CredentialStore, token: str):
        super().__init__(address, CredentialHandler)
        self.store = store
        self.token = token


class CredentialHandler(BaseHTTPRequestHandler):
    server: CredentialConsoleServer
    protocol_version = "HTTP/1.1"

    def log_message(self, _format: str, *_args: Any) -> None:
        return

    def _headers(self, status: int, content_type: str, length: int, extra: dict[str, str] | None = None) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(length))
        self.send_header("Cache-Control", "no-store, max-age=0")
        self.send_header("Pragma", "no-cache")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("Cross-Origin-Resource-Policy", "same-origin")
        self.send_header("Content-Security-Policy", "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
        if extra:
            for key, value in extra.items():
                self.send_header(key, value)
        self.end_headers()

    def _send(self, status: int, body: bytes, content_type: str = "application/json; charset=utf-8", extra: dict[str, str] | None = None) -> None:
        self._headers(status, content_type, len(body), extra)
        if self.command != "HEAD" and body:
            self.wfile.write(body)

    def _json(self, status: int, value: Any) -> None:
        self._send(status, json_bytes(value))

    def _authorized(self) -> bool:
        parsed = urllib.parse.urlsplit(self.path)
        query = urllib.parse.parse_qs(parsed.query, keep_blank_values=True)
        supplied = self.headers.get("X-Local-Credential-Token", "")
        if not supplied:
            supplied = query.get("token", [""])[0]
        if not supplied:
            cookie = SimpleCookie()
            try:
                cookie.load(self.headers.get("Cookie", ""))
                morsel = cookie.get("yiyun_console")
                supplied = "" if morsel is None else morsel.value
            except Exception:
                supplied = ""
        return isinstance(supplied, str) and hmac.compare_digest(supplied, self.server.token)

    def _session_cookie(self) -> dict[str, str]:
        return {"Set-Cookie": f"yiyun_console={self.server.token}; Path=/; HttpOnly; SameSite=Strict"}

    def _origin_allowed(self) -> bool:
        origin = self.headers.get("Origin")
        if not origin:
            return True
        expected = f"http://127.0.0.1:{self.server.server_port}"
        return hmac.compare_digest(origin, expected)

    def _read_json(self) -> Any:
        raw_length = self.headers.get("Content-Length")
        if raw_length is None or not raw_length.isdigit():
            raise ConsoleError("Content-Length is required.", HTTPStatus.LENGTH_REQUIRED)
        length = int(raw_length)
        if length < 0 or length > MAX_BODY_BYTES:
            raise ConsoleError("Request body is too large.", HTTPStatus.REQUEST_ENTITY_TOO_LARGE)
        content_type = self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        if content_type != "application/json":
            raise ConsoleError("Content-Type must be application/json.", HTTPStatus.UNSUPPORTED_MEDIA_TYPE)
        data = self.rfile.read(length)
        try:
            return json.loads(data.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise ConsoleError("Request body is invalid JSON.") from exc

    def _handle_error(self, exc: Exception) -> None:
        if isinstance(exc, ConsoleError):
            self._json(exc.status, {"error": str(exc)})
        else:
            self._json(HTTPStatus.INTERNAL_SERVER_ERROR, {"error": "Local credential operation failed."})

    def do_GET(self) -> None:  # noqa: N802
        try:
            parsed = urllib.parse.urlsplit(self.path)
            # This bundle contains no credentials. Keeping it anonymously
            # readable on loopback avoids propagating the bearer token into a
            # subresource URL, browser history, or referrer metadata.
            if parsed.path == "/credential-console.js":
                body = (self.server.store.root / JS_FILE).read_bytes()
                self._send(HTTPStatus.OK, body, "text/javascript; charset=utf-8")
                return
            if not self._authorized():
                self._json(HTTPStatus.NOT_FOUND, {"error": "Not found."})
                return
            if parsed.path in {"/", "/index.html"}:
                body = (self.server.store.root / HTML_FILE).read_bytes()
                self._send(HTTPStatus.OK, body, "text/html; charset=utf-8", self._session_cookie())
            elif parsed.path in {"/tests", "/tests.html"}:
                body = (self.server.store.root / TEST_HTML_FILE).read_bytes()
                self._send(HTTPStatus.OK, body, "text/html; charset=utf-8", self._session_cookie())
            elif parsed.path == "/api/state":
                self._json(HTTPStatus.OK, self.server.store.load())
            elif parsed.path == "/api/backups":
                self._json(HTTPStatus.OK, {"backups": self.server.store.backups()})
            elif parsed.path == "/api/export":
                body = json_bytes(self.server.store.load())
                self._send(HTTPStatus.OK, body, "application/json; charset=utf-8", {"Content-Disposition": "attachment; filename=credential-export.json"})
            elif parsed.path == "/api/health":
                state = self.server.store.load()
                self._json(HTTPStatus.OK, {"status": "ok", "revision": state["revision"], "accountCount": len(state["accounts"])})
            elif parsed.path == "/favicon.ico":
                self._send(HTTPStatus.NO_CONTENT, b"", "image/x-icon")
            else:
                self._json(HTTPStatus.NOT_FOUND, {"error": "Not found."})
        except Exception as exc:
            self._handle_error(exc)

    def do_POST(self) -> None:  # noqa: N802
        try:
            if not self._authorized() or not self._origin_allowed():
                self._json(HTTPStatus.NOT_FOUND, {"error": "Not found."})
                return
            parsed = urllib.parse.urlsplit(self.path)
            body = self._read_json()
            if not isinstance(body, dict):
                raise ConsoleError("Request body must be an object.")
            if parsed.path == "/api/state":
                saved = self.server.store.replace_accounts(body.get("expectedRevision"), body.get("accounts"))
                self._json(HTTPStatus.OK, saved)
            elif parsed.path == "/api/restore":
                saved = self.server.store.restore(body.get("name"), body.get("expectedRevision"))
                self._json(HTTPStatus.OK, saved)
            elif parsed.path == "/api/shutdown":
                self._json(HTTPStatus.OK, {"status": "stopping"})
                threading.Thread(target=self.server.shutdown, daemon=True).start()
            else:
                self._json(HTTPStatus.NOT_FOUND, {"error": "Not found."})
        except Exception as exc:
            self._handle_error(exc)


def run_server(root: Path, view: str, no_browser: bool) -> int:
    store = CredentialStore(root)
    token = secrets.token_urlsafe(32)
    server = CredentialConsoleServer(("127.0.0.1", 0), store, token)
    route = "/tests" if view == "test" else "/"
    url = f"http://127.0.0.1:{server.server_port}{route}?token={urllib.parse.quote(token)}"
    if not no_browser:
        webbrowser.open(url, new=1, autoraise=True)
    # Only non-sensitive readiness metadata is emitted. The bearer token remains
    # in the browser URL and is never printed to stdout/stderr.
    print(json.dumps({"status": "ready", "host": "127.0.0.1", "port": server.server_port, "view": view}, ensure_ascii=False))
    try:
        server.serve_forever(poll_interval=0.25)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the private desktop credential console.")
    parser.add_argument("--root", required=True, type=Path)
    parser.add_argument("--view", choices=("all", "test"), default="all")
    parser.add_argument("--no-browser", action="store_true")
    parser.add_argument("--rebuild-only", action="store_true")
    args = parser.parse_args()
    root = args.root.resolve(strict=True)
    store = CredentialStore(root)
    if args.rebuild_only:
        rebuild_derived(root, store.load())
        print(json.dumps({"status": "rebuilt", "accountCount": len(store.load()["accounts"])}, ensure_ascii=False))
        return 0
    return run_server(root, args.view, args.no_browser)


if __name__ == "__main__":
    raise SystemExit(main())
