#!/usr/bin/env python3
"""ShopVivaliz Remote Control Browser MCP.

Extends the canonical Remote Control MCP with GUI-only browser actions on the
backend VM. Browser control is performed through the graphical X11 session
(xdotool/xclip/scrot); no CDP, DevTools protocol, cookie extraction, or browser
profile parsing is used.
"""
from __future__ import annotations

import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import subprocess
import sys
import tempfile
import time
from typing import Any
from urllib.parse import urlsplit, urlunsplit

BASE_SERVER = os.environ.get(
    "SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER",
    "/opt/shopvivaliz-remote-control/server.py",
)
GUI_USER = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_GUI_USER", "fredrdp")
DISPLAY = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_DISPLAY", ":0")
MAX_SCREENSHOT_BYTES = int(os.environ.get("SHOPVIVALIZ_BROWSER_MCP_MAX_SCREENSHOT_BYTES", str(8 * 1024 * 1024)))
MAX_TABS = max(1, min(int(os.environ.get("SHOPVIVALIZ_BROWSER_MCP_MAX_TABS", "32")), 64))

BASE_SERVER_DIR = str(Path(BASE_SERVER).resolve().parent)
if BASE_SERVER_DIR not in sys.path:
    sys.path.insert(0, BASE_SERVER_DIR)

spec = importlib.util.spec_from_file_location("shopvivaliz_remote_control_base", BASE_SERVER)
if spec is None or spec.loader is None:
    raise RuntimeError("base_remote_control_server_not_found")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

VERSION = "1.0.0-browser"
BROWSER_HOST = "always-free-arm-1787907847-26"
BROWSER_TOOLS = {
    "browser_tabs",
    "browser_open",
    "browser_navigate",
    "browser_screenshot",
    "browser_click",
    "browser_type",
}
URL_RE = re.compile(r"^https?://", re.I)


def gui_prefix() -> list[str]:
    try:
        info = pwd.getpwnam(GUI_USER)
    except KeyError as exc:
        raise RuntimeError("browser_gui_user_missing") from exc
    env = [
        f"DISPLAY={DISPLAY}",
        f"XAUTHORITY={info.pw_dir}/.Xauthority",
        f"HOME={info.pw_dir}",
        f"USER={GUI_USER}",
        f"LOGNAME={GUI_USER}",
        f"XDG_RUNTIME_DIR=/run/user/{info.pw_uid}",
    ]
    return ["sudo", "-n", "-u", GUI_USER, "env", *env]


def run_gui(argv: list[str], *, timeout: int = 15, input_text: str | None = None, check: bool = True) -> subprocess.CompletedProcess[str]:
    completed = subprocess.run(
        gui_prefix() + argv,
        input=input_text,
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if check and completed.returncode != 0:
        stderr = base.redact_text((completed.stderr or completed.stdout or "browser_gui_command_failed").strip())
        raise RuntimeError(stderr)
    return completed


def require_binary(name: str) -> str:
    path = shutil.which(name)
    if not path:
        raise RuntimeError(f"browser_dependency_missing:{name}")
    return path


def browser_windows() -> list[str]:
    require_binary("xdotool")
    found: list[str] = []
    for klass in ("google-chrome", "Google-chrome", "chromium", "Chromium"):
        r = run_gui(["xdotool", "search", "--onlyvisible", "--class", klass], check=False)
        if r.returncode == 0:
            for item in (r.stdout or "").split():
                if item.isdigit() and item not in found:
                    found.append(item)
    return found


def active_browser_window() -> str:
    windows = browser_windows()
    if not windows:
        raise RuntimeError("browser_window_not_found")
    active = run_gui(["xdotool", "getactivewindow"], check=False)
    current = (active.stdout or "").strip()
    if current in windows:
        return current
    window = windows[0]
    run_gui(["xdotool", "windowactivate", "--sync", window])
    return window


def focus(window: str) -> None:
    run_gui(["xdotool", "windowactivate", "--sync", window])


def key(*keys: str) -> None:
    run_gui(["xdotool", "key", "--clearmodifiers", *keys])


def type_text(value: str) -> None:
    if len(value) > 20000:
        raise ValueError("browser_text_too_long")
    run_gui(["xdotool", "type", "--clearmodifiers", "--delay", "1", "--", value], timeout=30)


def validate_url(value: Any) -> str:
    url = str(value or "").strip()
    if not URL_RE.match(url):
        raise ValueError("browser_url_must_be_http_or_https")
    parts = urlsplit(url)
    if parts.username or parts.password or not parts.hostname:
        raise ValueError("browser_url_userinfo_not_allowed")
    return url


def safe_url(value: str) -> str:
    try:
        parts = urlsplit(value.strip())
        if parts.scheme not in {"http", "https"} or not parts.netloc:
            return ""
        host = parts.hostname or ""
        if parts.port:
            host += f":{parts.port}"
        return urlunsplit((parts.scheme, host, parts.path, "", ""))
    except Exception:
        return ""


def window_title(window: str) -> str:
    r = run_gui(["xdotool", "getwindowname", window], check=False)
    return (r.stdout or "").strip()[:1000]


def selected_url(window: str) -> str:
    focus(window)
    key("ctrl+l")
    key("ctrl+c")
    copied = run_gui(["xclip", "-selection", "clipboard", "-o"], check=False)
    key("Escape")
    if copied.returncode != 0:
        return ""
    return safe_url(copied.stdout or "")


def browser_tabs() -> dict[str, Any]:
    require_binary("xclip")
    windows = browser_windows()
    if not windows:
        return {"ok": True, "host": BROWSER_HOST, "windows": [], "tabs": []}
    original_active = run_gui(["xdotool", "getactivewindow"], check=False).stdout.strip()
    tabs: list[dict[str, Any]] = []
    try:
        for widx, window in enumerate(windows):
            focus(window)
            first = (window_title(window), selected_url(window))
            current = first
            seen: set[tuple[str, str]] = set()
            for tidx in range(MAX_TABS):
                if current in seen:
                    break
                seen.add(current)
                title, url = current
                tabs.append({"window_index": widx, "tab_index": tidx, "title": title, "url": url})
                key("ctrl+Tab")
                time.sleep(0.08)
                current = (window_title(window), selected_url(window))
                if current == first:
                    break
    finally:
        if original_active.isdigit():
            run_gui(["xdotool", "windowactivate", "--sync", original_active], check=False)
    return {"ok": True, "host": BROWSER_HOST, "window_count": len(windows), "tab_count": len(tabs), "tabs": tabs}


def browser_open(args: dict[str, Any]) -> dict[str, Any]:
    url = validate_url(args.get("url"))
    windows = browser_windows()
    if windows:
        focus(windows[0])
        key("ctrl+t")
        type_text(url)
        key("Return")
        return {"ok": True, "host": BROWSER_HOST, "action": "new_tab", "url": safe_url(url)}
    candidates = ["google-chrome", "google-chrome-stable", "chromium-browser", "chromium"]
    binary = next((name for name in candidates if shutil.which(name)), None)
    if not binary:
        raise RuntimeError("browser_binary_not_found")
    subprocess.Popen(
        gui_prefix() + [binary, "--new-window", url],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    return {"ok": True, "host": BROWSER_HOST, "action": "new_window", "url": safe_url(url)}


def browser_navigate(args: dict[str, Any]) -> dict[str, Any]:
    url = validate_url(args.get("url"))
    window = active_browser_window()
    focus(window)
    key("ctrl+l")
    type_text(url)
    key("Return")
    return {"ok": True, "host": BROWSER_HOST, "window_id": window, "url": safe_url(url)}


def parse_geometry(window: str) -> dict[str, int]:
    r = run_gui(["xdotool", "getwindowgeometry", "--shell", window])
    values: dict[str, int] = {}
    for line in (r.stdout or "").splitlines():
        if "=" not in line:
            continue
        k, v = line.split("=", 1)
        if k in {"X", "Y", "WIDTH", "HEIGHT"} and re.fullmatch(r"-?\d+", v):
            values[k] = int(v)
    if not {"X", "Y", "WIDTH", "HEIGHT"} <= values.keys():
        raise RuntimeError("browser_window_geometry_unavailable")
    return values


def browser_click(args: dict[str, Any]) -> dict[str, Any]:
    x = int(args.get("x"))
    y = int(args.get("y"))
    button_name = str(args.get("button") or "left")
    clicks = max(1, min(int(args.get("clicks", 1)), 3))
    button = {"left": "1", "middle": "2", "right": "3"}.get(button_name)
    if button is None:
        raise ValueError("browser_invalid_mouse_button")
    window = active_browser_window()
    focus(window)
    geo = parse_geometry(window)
    if not (geo["X"] <= x < geo["X"] + geo["WIDTH"] and geo["Y"] <= y < geo["Y"] + geo["HEIGHT"]):
        raise ValueError("browser_click_outside_active_window")
    run_gui(["xdotool", "mousemove", "--sync", str(x), str(y)])
    for _ in range(clicks):
        run_gui(["xdotool", "click", button])
    return {"ok": True, "host": BROWSER_HOST, "window_id": window, "x": x, "y": y, "button": button_name, "clicks": clicks}


def browser_type(args: dict[str, Any]) -> dict[str, Any]:
    text = str(args.get("text") or "")
    if not text:
        raise ValueError("browser_text_required")
    window = active_browser_window()
    focus(window)
    type_text(text)
    if bool(args.get("press_enter", False)):
        key("Return")
    return {
        "ok": True,
        "host": BROWSER_HOST,
        "window_id": window,
        "typed_characters": len(text),
        "press_enter": bool(args.get("press_enter", False)),
    }


def browser_screenshot() -> dict[str, Any]:
    window = active_browser_window()
    focus(window)
    tmpdir = tempfile.mkdtemp(prefix="shopvivaliz-browser-")
    path = str(Path(tmpdir) / "screenshot.png")
    try:
        if shutil.which("scrot"):
            run_gui(["scrot", "-u", path], timeout=20)
        elif shutil.which("gnome-screenshot"):
            run_gui(["gnome-screenshot", "-f", path], timeout=20)
        elif shutil.which("import"):
            run_gui(["import", "-window", "root", path], timeout=20)
        else:
            raise RuntimeError("browser_screenshot_dependency_missing")
        raw = Path(path).read_bytes()
        if not raw or len(raw) > MAX_SCREENSHOT_BYTES:
            raise RuntimeError("browser_screenshot_size_invalid")
        return {
            "ok": True,
            "host": BROWSER_HOST,
            "window_id": window,
            "mime_type": "image/png",
            "sha256": hashlib.sha256(raw).hexdigest(),
            "bytes": len(raw),
            "__mcp_image__": base64.b64encode(raw).decode("ascii"),
        }
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


BASE_EXECUTE_TOOL = base.execute_tool
BASE_TOOL_SPECS = base.tool_specs
BASE_AUDIT = base.audit


def execute_tool(name: str, args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    if name == "browser_tabs":
        return browser_tabs()
    if name == "browser_open":
        return browser_open(args)
    if name == "browser_navigate":
        return browser_navigate(args)
    if name == "browser_screenshot":
        return browser_screenshot()
    if name == "browser_click":
        return browser_click(args)
    if name == "browser_type":
        return browser_type(args)
    return BASE_EXECUTE_TOOL(name, args, cancel_check=cancel_check)


def audit(tool: str, host: str | None, args: dict[str, Any], ok: bool, summary: str) -> str:
    safe = dict(args)
    if tool == "browser_type" and "text" in safe:
        raw = str(safe.pop("text"))
        safe["text_sha256"] = hashlib.sha256(raw.encode()).hexdigest()
        safe["text_length"] = len(raw)
    if tool in {"browser_open", "browser_navigate"} and "url" in safe:
        safe["url"] = safe_url(str(safe["url"]))
    return BASE_AUDIT(tool, host or (BROWSER_HOST if tool in BROWSER_TOOLS else host), safe, ok, summary)


BROWSER_TOOL_SPECS = [
    {
        "name": "browser_tabs",
        "description": "List Chrome/Chromium tabs from the authenticated graphical backend session using GUI automation only.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_open",
        "description": "Open an http(s) URL in a new Chrome/Chromium tab on the authenticated graphical backend session.",
        "inputSchema": {"type": "object", "properties": {"url": {"type": "string"}}, "required": ["url"], "additionalProperties": False},
        "annotations": {"readOnlyHint": False, "openWorldHint": True, "destructiveHint": False},
    },
    {
        "name": "browser_navigate",
        "description": "Navigate the active graphical Chrome/Chromium tab to an http(s) URL.",
        "inputSchema": {"type": "object", "properties": {"url": {"type": "string"}}, "required": ["url"], "additionalProperties": False},
        "annotations": {"readOnlyHint": False, "openWorldHint": True, "destructiveHint": False},
    },
    {
        "name": "browser_screenshot",
        "description": "Capture the current graphical backend display and return it as a PNG image.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_click",
        "description": "Click absolute screen coordinates only when they fall inside the active browser window.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "x": {"type": "integer", "minimum": 0},
                "y": {"type": "integer", "minimum": 0},
                "button": {"type": "string", "enum": ["left", "middle", "right"]},
                "clicks": {"type": "integer", "minimum": 1, "maximum": 3},
            },
            "required": ["x", "y"],
            "additionalProperties": False,
        },
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": True},
    },
    {
        "name": "browser_type",
        "description": "Type text into the focused element of the active graphical browser. Typed text is never persisted in audit logs.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "text": {"type": "string", "maxLength": 20000},
                "press_enter": {"type": "boolean"},
            },
            "required": ["text"],
            "additionalProperties": False,
        },
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": True},
    },
]


def tool_specs() -> list[dict[str, Any]]:
    return BASE_TOOL_SPECS() + BROWSER_TOOL_SPECS


base.execute_tool = execute_tool
base.tool_specs = tool_specs
base.audit = audit
base.VERSION = VERSION


class BrowserHandler(base.Handler):
    server_version = "ShopVivalizRemoteControlBrowserMCP/" + VERSION

    def do_GET(self) -> None:
        if self.path == "/health":
            dependencies = {name: bool(shutil.which(name)) for name in ("xdotool", "xclip", "scrot")}
            self._json(200, {
                "ok": True,
                "endpoint": "shopvivaliz-remote-control-browser-mcp",
                "version": VERSION,
                "base_endpoint": "shopvivaliz-remote-control-mcp",
                "browser_host": BROWSER_HOST,
                "display": DISPLAY,
                "dependencies": dependencies,
                "timestamp": base.now(),
            })
            return
        self._json(405, {"error": "method_not_allowed"})

    def do_POST(self) -> None:
        if self.path != "/mcp":
            self._json(404, {"error": "not_found"})
            return
        if self.client_address[0] not in {"127.0.0.1", "::1"}:
            self._json(403, {"error": "loopback_only"})
            return
        if not base.is_authorized(self.headers.get("Authorization", "")):
            self._json(401, {"error": "unauthorized"})
            return
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if size <= 0 or size > 1_048_576:
                raise ValueError("invalid_body_size")
            req = json.loads(self.rfile.read(size))
            method = req.get("method")
            rid = req.get("id")
            if method == "initialize":
                result = {
                    "protocolVersion": base.PROTOCOL_VERSION,
                    "capabilities": {"tools": {"listChanged": False}},
                    "serverInfo": {"name": "shopvivaliz-remote-control-browser", "version": VERSION},
                }
            elif method == "tools/list":
                result = {"tools": tool_specs()}
            elif method == "tools/call":
                params = req.get("params") or {}
                name = str(params.get("name") or "")
                args = params.get("arguments") or {}
                host = args.get("host") or (BROWSER_HOST if name in BROWSER_TOOLS else None)
                try:
                    output = execute_tool(name, args, cancel_check=self._client_disconnected)
                    image_data = output.pop("__mcp_image__", None) if isinstance(output, dict) else None
                    ok = not (isinstance(output, dict) and output.get("ok") is False)
                    aid = audit(name, host, args, ok, "ok" if ok else "command_failed")
                    if isinstance(output, dict):
                        output["audit_id"] = aid
                    content = [{"type": "text", "text": json.dumps(output, ensure_ascii=False)}]
                    if image_data:
                        content.append({"type": "image", "data": image_data, "mimeType": "image/png"})
                    result = {"content": content, "structuredContent": output, "isError": not ok}
                except base.ClientDisconnected:
                    audit(name, host, args, False, "client_disconnected_command_cancelled")
                    raise
                except Exception as exc:
                    aid = audit(name, host, args, False, str(exc))
                    output = {"error": base.redact_text(str(exc)), "audit_id": aid}
                    result = {
                        "content": [{"type": "text", "text": json.dumps(output, ensure_ascii=False)}],
                        "structuredContent": output,
                        "isError": True,
                    }
            elif method and method.startswith("notifications/"):
                self.send_response(202)
                self.end_headers()
                return
            else:
                self._json(200, {"jsonrpc": "2.0", "id": rid, "error": {"code": -32601, "message": "Method not found"}})
                return
            self._json(200, {"jsonrpc": "2.0", "id": rid, "result": result})
        except base.ClientDisconnected:
            self.close_connection = True
        except Exception as exc:
            self._json(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": base.redact_text(str(exc))}})


def main() -> None:
    base.STATE_DIR.mkdir(parents=True, exist_ok=True)
    base.init_db()
    server = base.ThreadingHTTPServer((base.LISTEN_HOST, base.LISTEN_PORT), BrowserHandler)
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
