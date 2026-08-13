#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import shutil
import tempfile
import threading
import urllib.error
import urllib.request
from pathlib import Path


TOOLS = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("credential_console_server", TOOLS / "credential-console-server.py")
assert SPEC is not None and SPEC.loader is not None
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


def request_json(url: str, token: str | None, method: str = "GET", payload: object | None = None):
    data = None if payload is None else json.dumps(payload).encode("utf-8")
    headers = {}
    if token is not None:
        headers["X-Local-Credential-Token"] = token
    if payload is not None:
        headers["Content-Type"] = "application/json"
    request = urllib.request.Request(url, data=data, headers=headers, method=method)
    with urllib.request.urlopen(request, timeout=5) as response:
        return response.status, json.loads(response.read().decode("utf-8"))


def account(record_id: str, status: str, environment: str) -> dict:
    return {
        "recordId": record_id,
        "platform": "example-platform",
        "software": "example-app",
        "role": "user",
        "packageClass": "user",
        "accountId": f"example-id-{record_id[:4]}",
        "loginAccount": f"example-login-{record_id[:4]}",
        "password": f"example-password-{record_id[:4]}",
        "appId": "example-app",
        "adminId": "example-admin",
        "appSecret": None,
        "status": status,
        "environment": environment,
        "canLogin": status == "active",
        "loginEvidence": "source-status-only-not-live-verified",
        "deleted": False,
        "notes": "",
        "createdAtUtc": "2026-08-14T00:00:00Z",
        "updatedAtUtc": "2026-08-14T00:00:00Z",
    }


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="credential-console-api-") as temporary:
        root = Path(temporary)
        shutil.copy2(TOOLS / "credential-console.html", root / MODULE.HTML_FILE)
        shutil.copy2(TOOLS / "credential-console-tests.html", root / MODULE.TEST_HTML_FILE)
        shutil.copy2(TOOLS / "credential-console.js", root / MODULE.JS_FILE)
        state = {
            "schemaVersion": 1,
            "title": "example private credentials",
            "revision": 1,
            "createdAtUtc": "2026-08-14T00:00:00Z",
            "updatedAtUtc": "2026-08-14T00:00:00Z",
            "source": {"packageDirectory": "example", "packageCount": 2, "payloadCount": 3, "batchIds": ["example-batch"], "exportedAtUtc": "2026-08-14T00:00:00Z"},
            "accounts": [account("a" * 64, "active", "unknown"), account("b" * 64, "active", "unknown"), account("c" * 64, "disabled", "unknown")],
        }
        (root / MODULE.STATE_FILE).write_text(json.dumps(state), encoding="utf-8")
        store = MODULE.CredentialStore(root)
        MODULE.rebuild_derived(root, store.load())
        token = "fixture-token-that-is-never-a-real-secret"
        server = MODULE.CredentialConsoleServer(("127.0.0.1", 0), store, token)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        base = f"http://127.0.0.1:{server.server_port}"
        try:
            try:
                request_json(base + "/api/state", None)
                raise AssertionError("Unauthenticated request was accepted")
            except urllib.error.HTTPError as error:
                try:
                    assert error.code == 404
                finally:
                    error.close()
            status, loaded = request_json(base + "/api/state", token)
            assert status == 200 and loaded["revision"] == 1 and len(loaded["accounts"]) == 3
            malicious = '<img src=x onerror="globalThis.compromised=true"> & ` \' autofocus=onfocus=alert(1)'
            loaded["accounts"][0]["notes"] = malicious
            loaded["accounts"][0]["loginAccount"] = malicious
            loaded["accounts"][0]["environment"] = "test"
            loaded["accounts"][1]["deleted"] = True
            status, saved = request_json(base + "/api/state", token, "POST", {"expectedRevision": 1, "accounts": loaded["accounts"]})
            assert status == 200 and saved["revision"] == 2
            assert saved["accounts"][0]["notes"] == malicious and saved["accounts"][0]["loginAccount"] == malicious
            test_doc = json.loads((root / MODULE.DERIVED_DIR / "测试账号.json").read_text(encoding="utf-8"))
            disabled_doc = json.loads((root / MODULE.DERIVED_DIR / "已停用_不可登录.json").read_text(encoding="utf-8"))
            assert test_doc["count"] == 1 and disabled_doc["count"] == 1
            assert len(store.backups()) == 1
            try:
                request_json(base + "/api/state", token, "POST", {"expectedRevision": 1, "accounts": saved["accounts"]})
                raise AssertionError("Stale revision was accepted")
            except urllib.error.HTTPError as error:
                try:
                    assert error.code == 409
                finally:
                    error.close()
            _, backup_list = request_json(base + "/api/backups", token)
            assert len(backup_list["backups"]) == 1
            _, restored = request_json(base + "/api/restore", token, "POST", {"name": backup_list["backups"][0]["name"], "expectedRevision": 2})
            assert restored["revision"] == 3 and restored["accounts"][0]["environment"] == "unknown"
            with urllib.request.urlopen(urllib.request.Request(base + "/tests", headers={"X-Local-Credential-Token": token}), timeout=5) as response:
                html = response.read().decode("utf-8")
                assert response.status == 200 and "已标记测试" in html and "全部可登录" in html and "已停用" in html
                assert 'src="/credential-console.js"' in html and "?token=" not in html
                cookie = response.headers["Set-Cookie"].split(";", 1)[0]
                assert cookie.startswith("yiyun_console=") and "HttpOnly" in response.headers["Set-Cookie"] and "SameSite=Strict" in response.headers["Set-Cookie"]
            with urllib.request.urlopen(base + "/credential-console.js", timeout=5) as response:
                javascript = response.read().decode("utf-8")
                assert response.status == 200 and "input.value = String(value" in javascript and "$('tbody').innerHTML" not in javascript
            status, state_after_page_load = request_json(base + "/api/state", token)
            assert status == 200 and state_after_page_load["revision"] == 3
            cookie_request = urllib.request.Request(base + "/api/state", headers={"Cookie": cookie})
            with urllib.request.urlopen(cookie_request, timeout=5) as response:
                assert response.status == 200 and json.loads(response.read().decode("utf-8"))["revision"] == 3
            reload_request = urllib.request.Request(base + "/", headers={"Cookie": cookie})
            with urllib.request.urlopen(reload_request, timeout=5) as response:
                assert response.status == 200 and "易运盈平台账号密码" in response.read().decode("utf-8")
            tests_request = urllib.request.Request(base + "/tests", headers={"Cookie": cookie})
            with urllib.request.urlopen(tests_request, timeout=5) as response:
                assert response.status == 200 and "?token=" not in response.read().decode("utf-8")
            _, exported = request_json(base + "/api/export", token)
            assert len(exported["accounts"]) == 3
            _, health = request_json(base + "/api/health", token)
            assert health == {"status": "ok", "revision": 3, "accountCount": 3}
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=5)
    print("PASS: loopback credential console API/edit/backup/restore contract")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
