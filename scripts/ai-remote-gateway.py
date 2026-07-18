#!/usr/bin/env python3
"""
ShopVivaliz Remote AI Gateway.

Public routes:
- GET /
- GET /index.php
- GET /health

Protected routes:
- GET /status
- POST /exec
- POST /file/read|write|delete
- POST /git/status|log|commit|push|pull
- POST /service/status|restart|logs
- POST /npm/install|run
"""

from __future__ import annotations

import argparse
import json
import hmac
import os
import subprocess
import sys
import traceback
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import urlparse, parse_qs


ROOT = Path(__file__).resolve().parent.parent
LOG_DIR = ROOT / "logs"
REMOTE_DIR = ROOT / "storage" / "remote-access"
API_KEY_FILE = REMOTE_DIR / "api-key.txt"
DEFAULT_PORT = 5560
DEFAULT_HOST = "0.0.0.0"
PUBLIC_PATHS = {"/", "/index.php", "/health", "/favicon.ico"}


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_text(path: Path) -> str:
    try:
        return path.read_text(encoding="utf-8").strip()
    except OSError:
        return ""


def load_api_key() -> str:
    for env_name in ("SQUAD_TOKEN", "MCP_DEFAULT_KEY", "MCP_OPENAI_KEY", "MCP_CLAUDE_KEY", "MCP_GEMINI_KEY"):
        value = os.getenv(env_name, "").strip()
        if value:
            return value
    file_value = read_text(API_KEY_FILE)
    if file_value:
        return file_value
    return ""


def json_response(handler: BaseHTTPRequestHandler, code: int, payload: dict[str, object]) -> None:
    body = json.dumps(payload, ensure_ascii=False, separators=(", ", ": ")).encode("utf-8")
    handler.send_response(code)
    handler.send_header("Content-Type", "application/json; charset=utf-8")
    handler.send_header("Cache-Control", "no-store")
    handler.send_header("Access-Control-Allow-Origin", "*")
    handler.send_header("Content-Length", str(len(body)))
    handler.end_headers()
    if handler.command != "HEAD":
        handler.wfile.write(body)


def html_response(handler: BaseHTTPRequestHandler, code: int, html: str) -> None:
    body = html.encode("utf-8")
    handler.send_response(code)
    handler.send_header("Content-Type", "text/html; charset=utf-8")
    handler.send_header("Cache-Control", "no-store")
    handler.send_header("Access-Control-Allow-Origin", "*")
    handler.send_header("Content-Length", str(len(body)))
    handler.end_headers()
    if handler.command != "HEAD":
        handler.wfile.write(body)


class GatewayHandler(BaseHTTPRequestHandler):
    server_version = "ShopVivalizRemoteGateway/1.0"

    @property
    def api_key(self) -> str:
        return getattr(self.server, "api_key", "")  # type: ignore[attr-defined]

    def _provider(self) -> str:
        auth = self.headers.get("Authorization", "")
        api_key = self.headers.get("X-API-Key", "")
        if "sk-claude" in api_key or "sk-claude" in auth:
            return "claude"
        if "sk-gemini" in api_key or "sk-gemini" in auth:
            return "gemini"
        if "sk-openai" in api_key or "sk-openai" in auth:
            return "openai"
        if auth.startswith("Bearer "):
            return "bearer"
        return "unknown"

    def _auth_ok(self) -> bool:
        if self.path in PUBLIC_PATHS:
            return True

        provided = self.headers.get("X-API-Key", "").strip()
        if provided:
            return hmac.compare_digest(provided, self.api_key)

        auth = self.headers.get("Authorization", "").strip()
        if auth.startswith("Bearer "):
            token = auth[7:].strip()
            return hmac.compare_digest(token, self.api_key)

        return False

    def _deny(self) -> None:
        json_response(self, 401, {"ok": False, "error": "unauthorized"})

    def _read_json_body(self) -> dict[str, object]:
        try:
            length = int(self.headers.get("Content-Length", "0") or "0")
        except ValueError:
            length = 0
        if length <= 0:
            return {}
        raw = self.rfile.read(length)
        if not raw:
            return {}
        try:
            parsed = json.loads(raw.decode("utf-8"))
            return parsed if isinstance(parsed, dict) else {}
        except Exception:
            return {}

    def do_OPTIONS(self) -> None:
        self.send_response(200)
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization, X-API-Key")
        self.send_header("Access-Control-Max-Age", "86400")
        self.end_headers()

    def do_GET(self) -> None:
        path = urlparse(self.path).path
        query = parse_qs(urlparse(self.path).query)

        if path in {"/", "/index.php"}:
            html_response(
                self,
                200,
                """<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ShopVivaliz Remote Gateway</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 32px; color: #1f2937; background: #f8fafc; }
    .card { max-width: 720px; background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; box-shadow: 0 12px 40px rgba(15, 23, 42, 0.06); }
    code, pre { background: #f1f5f9; padding: 2px 6px; border-radius: 6px; }
    ul { line-height: 1.8; }
  </style>
</head>
<body>
  <div class="card">
    <h1>ShopVivaliz Remote Gateway</h1>
    <p>Raiz pública ativa. Endpoints protegidos continuam exigindo chave.</p>
    <ul>
      <li><code>/health</code> público</li>
      <li><code>/status</code> protegido por <code>X-API-Key</code> ou <code>Authorization: Bearer</code></li>
      <li><code>/exec</code>, <code>/git/*</code>, <code>/service/*</code> e <code>/npm/*</code> protegidos</li>
    </ul>
  </div>
</body>
</html>""",
            )
            return

        if path == "/health":
            json_response(
                self,
                200,
                {
                    "ok": True,
                    "status": "healthy",
                    "service": "shopvivaliz-remote-gateway",
                    "timestamp": now_iso(),
                    "public": True,
                },
            )
            return

        if not self._auth_ok():
            self._deny()
            return

        if path == "/status":
            hostname = subprocess.getoutput("hostname").strip()
            whoami = subprocess.getoutput("whoami").strip()
            json_response(
                self,
                200,
                {
                    "ok": True,
                    "status": "online",
                    "service": "shopvivaliz-remote-gateway",
                    "hostname": hostname,
                    "user": whoami,
                    "provider": self._provider(),
                    "timestamp": now_iso(),
                },
            )
            return

        if path == "/tools":
            json_response(
                self,
                200,
                {
                    "ok": True,
                    "tools": [
                        "exec",
                        "file/read",
                        "file/write",
                        "file/delete",
                        "git/status",
                        "git/log",
                        "git/commit",
                        "git/push",
                        "git/pull",
                        "service/status",
                        "service/restart",
                        "service/logs",
                        "npm/install",
                        "npm/run",
                    ],
                },
            )
            return

        if path == "/providers":
            json_response(
                self,
                200,
                {"ok": True, "supported": ["openai", "claude", "gemini", "custom"], "current": self._provider()},
            )
            return

        if path == "/logs":
            lines = int(query.get("lines", ["20"])[0] or "20")
            log_path = LOG_DIR / "ai-remote-gateway.log"
            try:
                content = read_text(log_path).splitlines()[-lines:]
            except Exception:
                content = []
            json_response(self, 200, {"ok": True, "logs": content})
            return

        json_response(self, 404, {"ok": False, "error": "not_found"})

    def do_POST(self) -> None:
        path = urlparse(self.path).path

        if not self._auth_ok():
            self._deny()
            return

        data = self._read_json_body()

        if path == "/exec":
            cmd = str(data.get("cmd", "")).strip()
            timeout = int(data.get("timeout", 30) or 30)
            if not cmd:
                json_response(self, 400, {"ok": False, "error": "cmd_required"})
                return
            try:
                result = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=timeout)
                json_response(
                    self,
                    200,
                    {
                        "ok": True,
                        "returncode": result.returncode,
                        "stdout": result.stdout,
                        "stderr": result.stderr,
                        "status": "success" if result.returncode == 0 else "failed",
                    },
                )
            except subprocess.TimeoutExpired:
                json_response(self, 408, {"ok": False, "error": "timeout"})
            return

        if path.startswith("/file/"):
            op = path.removeprefix("/file/")
            file_path = str(data.get("path", "")).strip()
            if not file_path:
                json_response(self, 400, {"ok": False, "error": "path_required"})
                return
            target = Path(file_path)
            if op == "read":
                try:
                    content = target.read_text(encoding="utf-8")
                    json_response(self, 200, {"ok": True, "path": file_path, "content": content, "size": len(content)})
                except FileNotFoundError:
                    json_response(self, 404, {"ok": False, "error": "file_not_found"})
                return
            if op == "write":
                content = str(data.get("content", ""))
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text(content, encoding="utf-8")
                json_response(self, 200, {"ok": True, "path": file_path, "written": len(content)})
                return
            if op == "delete":
                try:
                    target.unlink()
                    json_response(self, 200, {"ok": True, "path": file_path, "deleted": True})
                except FileNotFoundError:
                    json_response(self, 404, {"ok": False, "error": "file_not_found"})
                return

        if path.startswith("/git/"):
            git_op = path.removeprefix("/git/")
            repo = str(data.get("path", ROOT)).strip() or str(ROOT)
            if git_op == "status":
                output = subprocess.getoutput(f'cd "{repo}" && git status --porcelain')
                json_response(self, 200, {"ok": True, "path": repo, "status": output.strip(), "clean": output.strip() == ""})
                return
            if git_op == "log":
                lines = int(data.get("lines", 10) or 10)
                output = subprocess.getoutput(f'cd "{repo}" && git log --oneline -n {lines}')
                json_response(self, 200, {"ok": True, "path": repo, "log": [line for line in output.splitlines() if line]})
                return
            if git_op in {"commit", "push", "pull"}:
                json_response(self, 501, {"ok": False, "error": "not_implemented_on_windows_gateway"})
                return

        if path.startswith("/service/"):
            service_op = path.removeprefix("/service/")
            service = str(data.get("service", "")).strip()
            if not service:
                json_response(self, 400, {"ok": False, "error": "service_required"})
                return
            if service_op == "status":
                result = subprocess.run(["sc", "query", service], capture_output=True, text=True)
                state = "running" if "RUNNING" in result.stdout else "stopped"
                json_response(self, 200, {"ok": True, "service": service, "status": state})
                return
            if service_op == "restart":
                result = subprocess.run(["sc", "stop", service], capture_output=True, text=True)
                result2 = subprocess.run(["sc", "start", service], capture_output=True, text=True)
                json_response(self, 200, {"ok": True, "service": service, "status": "restarted", "stop_rc": result.returncode, "start_rc": result2.returncode})
                return
            if service_op == "logs":
                json_response(self, 501, {"ok": False, "error": "service_logs_not_supported"})
                return

        if path.startswith("/npm/"):
            json_response(self, 501, {"ok": False, "error": "npm_operations_not_supported"})
            return

        json_response(self, 404, {"ok": False, "error": "not_found"})

    def log_message(self, format: str, *args: object) -> None:  # noqa: A003
        return


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--port", type=int, default=DEFAULT_PORT)
    args = parser.parse_args()

    REMOTE_DIR.mkdir(parents=True, exist_ok=True)
    LOG_DIR.mkdir(parents=True, exist_ok=True)

    api_key = load_api_key()
    if not api_key:
        print("ERROR: API key not found in environment or storage/remote-access/api-key.txt", file=sys.stderr)
        return 1

    server = ThreadingHTTPServer((args.host, args.port), GatewayHandler)
    server.api_key = api_key  # type: ignore[attr-defined]

    print(f"ShopVivaliz Remote AI Gateway started")
    print(f"Health: http://{args.host}:{args.port}/health")
    print(f"Status: http://{args.host}:{args.port}/status")
    print(f"Auth header: X-API-Key")

    try:
        server.serve_forever()
    except KeyboardInterrupt:
        return 0
    except Exception:
        traceback.print_exc()
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
