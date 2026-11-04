#!/usr/bin/env python3
"""Agent client for the Marina MCP behind Cloudflare Access (service token).

Lets an agent call the Marina MCP tools, including the admin shell, through the
public hostname with the Access service token. The token is read from protected
files (mode 0600, owned by the caller, not a symlink) or from the environment and
is never printed: not in output, errors or exceptions. Any echo of it in a server
response is redacted.

Credentials (names only here, never values):
  MARINA_MCP_ACCESS_CLIENT_ID, MARINA_MCP_ACCESS_CLIENT_SECRET
  as environment variables, or as files with those names in
  ~/.shopvivaliz/secrets/ (override the directory with MARINA_MCP_SECRETS_DIR).

Usage:
  marina_mcp_agent_client.py preflight          # metadata only, no network
  marina_mcp_agent_client.py tools              # initialize + tools/list (names)
  marina_mcp_agent_client.py call TOOL --args '{"command": "id -un"}'

Exit codes: 0 ok, 2 credentials missing/unsafe or refused endpoint, 3 HTTP or
Access failure, 4 the tool reported an error.
"""
from __future__ import annotations

import argparse
import json
import os
import stat
import sys
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urlparse

DEFAULT_ENDPOINT = "https://mcp-marina.shopvivaliz.com.br/mcp"
ID_NAME = "MARINA_MCP_ACCESS_CLIENT_ID"
SECRET_NAME = "MARINA_MCP_ACCESS_CLIENT_SECRET"
PROTOCOL_VERSION = "2025-06-18"
TIMEOUT_SECONDS = 330  # the admin shell allows up to 300 s per command
MAX_RESPONSE_BYTES = 4 * 1024 * 1024
LOOPBACK = {"127.0.0.1", "localhost", "::1"}


class Refused(Exception):
    """A safety refusal; the message never contains a credential value."""


def secrets_dir() -> Path:
    return Path(os.environ.get("MARINA_MCP_SECRETS_DIR") or (Path.home() / ".shopvivaliz" / "secrets"))


def file_state(path: Path) -> str:
    """PRESENT, ABSENT or UNSAFE_<reason>; looks at metadata only, never the content."""
    try:
        info = path.lstat()
    except FileNotFoundError:
        return "ABSENT"
    except OSError:
        return "UNSAFE_UNREADABLE"
    if stat.S_ISLNK(info.st_mode):
        return "UNSAFE_SYMLINK"
    if not stat.S_ISREG(info.st_mode):
        return "UNSAFE_NOT_A_FILE"
    if info.st_uid != os.geteuid():
        return "UNSAFE_OWNER"
    if stat.S_IMODE(info.st_mode) & 0o077:
        return "UNSAFE_MODE"
    return "PRESENT"


def source_state(name: str) -> tuple[str, str]:
    """(state, where) for one credential; the environment wins over the file."""
    if os.environ.get(name):
        return "PRESENT", "env"
    path = secrets_dir() / name
    return file_state(path), "file"


def load_credential(name: str) -> str:
    if os.environ.get(name):
        return os.environ[name].strip()
    path = secrets_dir() / name
    state = file_state(path)
    if state != "PRESENT":
        raise Refused(f"{name}: {state}")
    value = path.read_text(encoding="utf-8").strip()
    if not value:
        raise Refused(f"{name}: EMPTY")
    return value


def check_endpoint(endpoint: str) -> str:
    parsed = urlparse(endpoint)
    if parsed.scheme == "https" and parsed.hostname:
        return endpoint
    if parsed.scheme == "http" and parsed.hostname in LOOPBACK:
        return endpoint
    raise Refused("endpoint must be https (http only on loopback)")


def redact(text: str, secrets: list[str]) -> str:
    for secret in secrets:
        if secret:
            text = text.replace(secret, "[REDACTED]")
    return text


def parse_body(raw: bytes, content_type: str):
    text = raw.decode("utf-8", "replace")
    if "text/event-stream" in content_type:
        events = [line[5:].strip() for line in text.splitlines() if line.startswith("data:")]
        text = events[-1] if events else "null"
    return json.loads(text or "null")


class Client:
    def __init__(self, endpoint: str, client_id: str, client_secret: str):
        self.endpoint = check_endpoint(endpoint)
        self._id, self._secret = client_id, client_secret
        self.session = None
        self._next = 0

    def rpc(self, method: str, params=None):
        self._next += 1
        body = {"jsonrpc": "2.0", "id": self._next, "method": method}
        if params is not None:
            body["params"] = params
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "CF-Access-Client-Id": self._id,
            "CF-Access-Client-Secret": self._secret,
            "MCP-Protocol-Version": PROTOCOL_VERSION,
        }
        if self.session:
            headers["Mcp-Session-Id"] = self.session
        request = urllib.request.Request(self.endpoint, data=json.dumps(body).encode(), headers=headers, method="POST")
        try:
            with urllib.request.urlopen(request, timeout=TIMEOUT_SECONDS) as response:
                raw = response.read(MAX_RESPONSE_BYTES + 1)
                self.session = response.headers.get("Mcp-Session-Id") or self.session
                content_type = response.headers.get("Content-Type", "")
        except urllib.error.HTTPError as error:
            raise ConnectionError(f"HTTP {error.code} from the endpoint (Access or MCP refused the request)") from None
        except (urllib.error.URLError, TimeoutError, OSError) as error:
            raise ConnectionError(f"request failed: {type(error).__name__}") from None
        if len(raw) > MAX_RESPONSE_BYTES:
            raise ConnectionError("response too large")
        return parse_body(raw, content_type)

    def initialize(self):
        reply = self.rpc("initialize", {"protocolVersion": PROTOCOL_VERSION, "capabilities": {},
                                        "clientInfo": {"name": "marina-agent-client", "version": "1"}})
        if not isinstance(reply, dict) or "result" not in reply:
            raise ConnectionError("initialize did not return a result")
        return reply["result"]


def cmd_preflight(_args) -> int:
    id_state, id_where = source_state(ID_NAME)
    secret_state, secret_where = source_state(SECRET_NAME)
    ready = id_state == "PRESENT" and secret_state == "PRESENT"
    print(json.dumps({
        "client_id": {"state": id_state, "source": id_where},
        "client_secret": {"state": secret_state, "source": secret_where},
        "secrets_dir": str(secrets_dir()),
        "endpoint": os.environ.get("MARINA_MCP_ENDPOINT", DEFAULT_ENDPOINT),
        "ready": ready,
        "note": "metadata only; a present credential is not proof that it is valid",
    }, indent=2))
    return 0 if ready else 2


def make_client(args) -> tuple[Client, list[str]]:
    client_id, client_secret = load_credential(ID_NAME), load_credential(SECRET_NAME)
    endpoint = args.endpoint or os.environ.get("MARINA_MCP_ENDPOINT", DEFAULT_ENDPOINT)
    return Client(endpoint, client_id, client_secret), [client_id, client_secret]


def cmd_tools(args) -> int:
    client, secrets = make_client(args)
    client.initialize()
    reply = client.rpc("tools/list", {})
    names = sorted(tool["name"] for tool in reply["result"]["tools"])
    print(redact(json.dumps({"tools": names}, indent=2), secrets))
    return 0


def cmd_call(args) -> int:
    try:
        arguments = json.loads(args.args)
    except ValueError:
        raise Refused("--args is not valid JSON") from None
    if not isinstance(arguments, dict):
        raise Refused("--args must be a JSON object")
    client, secrets = make_client(args)
    client.initialize()
    reply = client.rpc("tools/call", {"name": args.tool, "arguments": arguments})
    print(redact(json.dumps(reply, indent=2), secrets))
    if not isinstance(reply, dict) or "error" in reply or reply.get("result", {}).get("isError"):
        return 4
    return 0


def main(argv=None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--endpoint", help="MCP URL (default: the public Marina hostname)")
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("preflight").set_defaults(func=cmd_preflight)
    sub.add_parser("tools").set_defaults(func=cmd_tools)
    call = sub.add_parser("call")
    call.add_argument("tool")
    call.add_argument("--args", default="{}")
    call.set_defaults(func=cmd_call)
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except Refused as refusal:
        print(f"REFUSED: {refusal}", file=sys.stderr)
        return 2
    except ConnectionError as failure:
        print(f"FAILED: {failure}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    sys.exit(main())
