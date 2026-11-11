"""Gateway que valida o JWT do Cloudflare Access antes de alcancar o MCP do backend.

Escuta apenas em loopback. Aceita somente POST /mcp com `Cf-Access-Jwt-Assertion`
valido (RS256, emissor, audiencia, expiracao e principal na allowlist). O bearer
local do MCP e lido do arquivo protegido e injetado no upstream; nunca e logado
nem devolvido ao cliente. Falha fechada: qualquer duvida resulta em 401/403.

Reutiliza `cloudflare_access_auth` (mesmo verificador do MCP da Marina), que deve
ser instalado ao lado deste arquivo.
"""
from __future__ import annotations

import http.client
import os
import stat
import sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from cloudflare_access_auth import CloudflareAccessVerifier, load_access_config

LISTEN_HOST = "127.0.0.1"
LISTEN_PORT = int(os.environ.get("GATEWAY_PORT", "5590"))
UPSTREAM_HOST = "127.0.0.1"
UPSTREAM_PORT = int(os.environ.get("GATEWAY_UPSTREAM_PORT", "5581"))
UPSTREAM_PATH = "/mcp"
MAX_BODY = 1_048_576
UPSTREAM_TIMEOUT = 120


def load_upstream_token(path: Path) -> str:
    """Le o bearer local exigindo arquivo regular, do proprio usuario, modo 0600."""
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    with os.fdopen(fd, "rb") as stream:
        meta = os.fstat(stream.fileno())
        if (not stat.S_ISREG(meta.st_mode) or meta.st_uid != os.geteuid()
                or stat.S_IMODE(meta.st_mode) != 0o600):
            raise RuntimeError("upstream_token_metadata_invalid")
        token = stream.read(4096).decode("utf-8").strip()
    if not token or any(ch.isspace() for ch in token):
        raise RuntimeError("upstream_token_invalid")
    return token


def make_handler(verifier, upstream_token: str, upstream_port: int = UPSTREAM_PORT):
    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"
        server_version = "rc-access-gateway"

        def log_message(self, fmt, *args):  # sem corpo, sem cabecalhos
            return

        def _reply(self, status: int, code: str) -> None:
            body = ('{"error":"%s"}' % code).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Connection", "close")
            self.end_headers()
            self.wfile.write(body)
            self.close_connection = True

        def do_POST(self) -> None:
            if self.path != UPSTREAM_PATH:
                return self._reply(404, "not_found")
            principal = verifier.verify(self.headers.get("Cf-Access-Jwt-Assertion", ""))
            if principal is None:
                return self._reply(401, "access_assertion_invalid")
            try:
                length = int(self.headers.get("Content-Length", ""))
            except ValueError:
                return self._reply(411, "content_length_required")
            if not 0 < length <= MAX_BODY:
                return self._reply(413, "body_size_invalid")
            body = self.rfile.read(length)
            headers = {
                "Authorization": "Bearer " + upstream_token,
                "Content-Type": self.headers.get("Content-Type", "application/json"),
                "Accept": self.headers.get("Accept", "application/json, text/event-stream"),
                "Content-Length": str(len(body)),
            }
            sid = self.headers.get("Mcp-Session-Id")
            if sid and len(sid) <= 256 and sid.isascii() and sid.isprintable():
                headers["Mcp-Session-Id"] = sid
            try:
                conn = http.client.HTTPConnection(UPSTREAM_HOST, upstream_port, timeout=UPSTREAM_TIMEOUT)
                conn.request("POST", UPSTREAM_PATH, body, headers)
                resp = conn.getresponse()
            except (OSError, http.client.HTTPException):
                return self._reply(502, "upstream_unavailable")
            try:
                payload = resp.read()
                self.send_response(resp.status)
                for name in ("Content-Type", "Mcp-Session-Id"):
                    value = resp.getheader(name)
                    if value:
                        self.send_header(name, value)
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
            finally:
                conn.close()

        def do_GET(self) -> None:
            self._reply(405, "method_not_allowed")

        do_PUT = do_DELETE = do_PATCH = do_GET

    return Handler


def main() -> int:
    config = load_access_config(Path(os.environ["GATEWAY_ACCESS_CONFIG"]))
    token = load_upstream_token(Path(os.environ["GATEWAY_UPSTREAM_TOKEN_FILE"]))
    server = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), make_handler(CloudflareAccessVerifier(config), token))
    server.serve_forever()
    return 0


if __name__ == "__main__":
    sys.exit(main())
