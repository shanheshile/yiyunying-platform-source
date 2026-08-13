from __future__ import annotations

import base64
import json
import os
from pathlib import Path
import socket
import subprocess
import threading


BACKEND_ROOT = Path(__file__).resolve().parents[2]


class FakeSmtpServer:
    def __init__(self) -> None:
        self.listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.listener.bind(("127.0.0.1", 0))
        self.listener.listen(1)
        self.port = self.listener.getsockname()[1]
        self.commands: list[str] = []
        self.message = b""
        self.error: BaseException | None = None
        self.thread = threading.Thread(target=self._serve, daemon=True)

    def __enter__(self) -> "FakeSmtpServer":
        self.thread.start()
        return self

    def __exit__(self, exc_type, exc, traceback) -> None:
        self.thread.join(timeout=10)
        self.listener.close()
        if self.thread.is_alive():
            raise AssertionError("fake SMTP server did not terminate")
        if self.error is not None:
            raise self.error

    def _serve(self) -> None:
        try:
            connection, _ = self.listener.accept()
            with connection, connection.makefile("rwb", buffering=0) as stream:
                stream.write(b"220 localhost test SMTP\r\n")
                in_data = False
                data_lines: list[bytes] = []
                while True:
                    line = stream.readline()
                    if not line:
                        break
                    if in_data:
                        if line == b".\r\n":
                            self.message = b"".join(data_lines)
                            stream.write(b"250 2.0.0 accepted for delivery\r\n")
                            in_data = False
                        else:
                            data_lines.append(line)
                        continue

                    command = line.decode("ascii", errors="replace").rstrip("\r\n")
                    self.commands.append(command)
                    upper = command.upper()
                    if upper.startswith("EHLO "):
                        stream.write(b"250-localhost\r\n250-AUTH PLAIN LOGIN\r\n250 SIZE 1048576\r\n")
                    elif upper.startswith("AUTH PLAIN "):
                        stream.write(b"235 2.7.0 authenticated\r\n")
                    elif upper.startswith("MAIL FROM:") or upper.startswith("RCPT TO:"):
                        stream.write(b"250 2.1.0 ok\r\n")
                    elif upper == "DATA":
                        in_data = True
                        stream.write(b"354 end with dot\r\n")
                    elif upper == "QUIT":
                        stream.write(b"221 2.0.0 bye\r\n")
                        break
                    else:
                        stream.write(b"500 unsupported\r\n")
        except BaseException as error:  # surfaced in __exit__
            self.error = error


PHP_PROBE = r"""
require getenv('YY_BACKEND_ROOT') . '/bootstrap.php';
$GLOBALS['yiyunying_config']['app']['env'] = 'local';
$GLOBALS['yiyunying_config']['app']['debug'] = false;
$GLOBALS['yiyunying_config']['mail'] = [
    'transport' => 'smtp',
    'database_config_enabled' => false,
    'from_address' => 'no-reply@example.test',
    'from_name' => '易运盈测试',
    'smtp' => [
        'host' => '127.0.0.1',
        'port' => (int) getenv('YY_SMTP_PORT'),
        'encryption' => 'none',
        'username' => 'smtp-user',
        'password' => 'smtp-password',
        'timeout' => 5,
        'helo' => 'test.local',
    ],
];
$result = \Yiyunying\Services\VerificationEmailDeliveryService::deliver(
    'recipient@example.com', '测试应用', '123456', '注册'
);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
"""


def main() -> None:
    with FakeSmtpServer() as server:
        environment = os.environ.copy()
        environment["YY_BACKEND_ROOT"] = str(BACKEND_ROOT)
        environment["YY_SMTP_PORT"] = str(server.port)
        result = subprocess.run(
            ["php", "-r", PHP_PROBE],
            cwd=BACKEND_ROOT,
            env=environment,
            text=True,
            capture_output=True,
            timeout=15,
            check=False,
        )
        assert result.returncode == 0, result.stderr
        receipt = json.loads(result.stdout)
        assert receipt == {"delivery_status": "accepted_unconfirmed"}, receipt

    assert any(command.startswith("AUTH PLAIN ") for command in server.commands)
    assert any(command == "MAIL FROM:<no-reply@example.test>" for command in server.commands)
    assert any(command == "RCPT TO:<recipient@example.com>" for command in server.commands)
    headers, encoded_body = server.message.split(b"\r\n\r\n", 1)
    body = base64.b64decode(b"".join(encoded_body.splitlines())).decode("utf-8")
    assert "123456" in body
    assert "smtp-password" not in result.stdout
    assert b"smtp-password" not in server.message
    assert b"Content-Transfer-Encoding: base64" in headers
    print("Verification SMTP handoff test passed.")


if __name__ == "__main__":
    main()
