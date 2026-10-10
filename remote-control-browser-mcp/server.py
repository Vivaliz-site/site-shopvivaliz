#!/usr/bin/env python3
"""ShopVivaliz Remote Control Browser MCP.

Extends the canonical Remote Control MCP with GUI-only browser actions on the
backend VM. Browser control is performed through the graphical X11 session
(xdotool/xclip/scrot); no CDP, DevTools protocol, cookie extraction, or browser
profile parsing is used.
"""
from __future__ import annotations

import base64
import binascii
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import struct
import subprocess
import sys
import tempfile
import time
from typing import Any
import zlib
from urllib.parse import urlsplit, urlunsplit
from urllib.request import Request, urlopen

BASE_SERVER = os.environ.get(
    "SHOPVIVALIZ_BROWSER_MCP_BASE_SERVER",
    "/opt/shopvivaliz-remote-control/server.py",
)
GUI_USER = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_GUI_USER", "fredconsole")
DISPLAY = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_DISPLAY", ":0")
BROWSER_BINARY = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_BROWSER_BINARY", "/opt/shopvivaliz-browser/chrome-linux/chrome")
BROWSER_PROFILE_DIR = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_PROFILE_DIR", "/home/fredconsole/.config/shopvivaliz-general-chromium")
BROWSER_WINDOW_CLASS = os.environ.get("SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS", "shopvivaliz-general")
RUSTDESK_BINARY = os.environ.get("SHOPVIVALIZ_DESKTOP_RUSTDESK_BINARY", "/usr/bin/rustdesk")
FREDWIN_NATIVE_DESKTOP_BRIDGE = os.environ.get(
    "SHOPVIVALIZ_FREDWIN_NATIVE_DESKTOP_BRIDGE",
    r"C:\site-shopvivaliz\scripts\shopvivaliz-native-desktop-bridge.ps1",
)
DESKTOP_ALIASES = {
    "KOCEPSV": ("kocepsv", "desktop-kocepsv"),
    "Fred-Win": ("fred-win", "laptop-nig4ifuu"),
}
MAX_SCREENSHOT_BYTES = int(os.environ.get("SHOPVIVALIZ_BROWSER_MCP_MAX_SCREENSHOT_BYTES", str(8 * 1024 * 1024)))
MAX_XWD_BYTES = int(os.environ.get("SHOPVIVALIZ_BROWSER_MCP_MAX_XWD_BYTES", str(64 * 1024 * 1024)))
MAX_TABS = max(1, min(int(os.environ.get("SHOPVIVALIZ_BROWSER_MCP_MAX_TABS", "32")), 64))

BASE_SERVER_DIR = str(Path(BASE_SERVER).resolve().parent)
if BASE_SERVER_DIR not in sys.path:
    sys.path.insert(0, BASE_SERVER_DIR)

spec = importlib.util.spec_from_file_location("shopvivaliz_remote_control_base", BASE_SERVER)
if spec is None or spec.loader is None:
    raise RuntimeError("base_remote_control_server_not_found")
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)

VERSION = "1.1.0-browser"
BROWSER_HOST = "always-free-arm-1787907847-26"
ACCOUNT_AUTH_TOOLS = {"browser_auth_tabs", "browser_auth_action", "browser_auth_open"}

BROWSER_TOOLS = {
    "browser_health",
    "browser_gui_tabs",
    "browser_open",
    "browser_gui_navigate",
    "browser_screenshot",
    "browser_gui_click",
    "browser_gui_type",
}

ATTENDIMENTO_TOOL_MAP = {
    "browser_atendimento_tabs": "browser_tabs",
    "browser_atendimento_controls": "browser_controls",
    "browser_atendimento_navigate": "browser_navigate",
    "browser_atendimento_click": "browser_click",
    "browser_atendimento_click_control": "browser_click_control",
    "browser_atendimento_type": "browser_type",
}
ATTENDIMENTO_TOOLS = set(ATTENDIMENTO_TOOL_MAP)
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
        "TMPDIR=/var/tmp",
    ]
    return ["sudo", "-n", "-u", GUI_USER, "env", *env]


def gui_session_active() -> bool:
    """Fail closed if the canonical X11 user/display is not on the active seat."""
    try:
        active = subprocess.run(
            ["loginctl", "show-seat", "seat0", "-p", "ActiveSession", "--value"],
            capture_output=True,
            text=True,
            check=False,
            timeout=3,
        )
        session_id = active.stdout.strip()
        if active.returncode != 0 or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", session_id):
            return False
        details = subprocess.run(
            ["loginctl", "show-session", session_id, "-p", "Name", "-p", "Display", "-p", "Active"],
            capture_output=True,
            text=True,
            check=False,
            timeout=3,
        )
        if details.returncode != 0:
            return False
        fields = dict(line.split("=", 1) for line in details.stdout.splitlines() if "=" in line)
        return fields.get("Name") == GUI_USER and fields.get("Display") == DISPLAY and fields.get("Active") == "yes"
    except (OSError, subprocess.TimeoutExpired):
        return False


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
    r = run_gui(["xdotool", "search", "--onlyvisible", "--class", BROWSER_WINDOW_CLASS], check=False)
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
    focus(window)
    return window


def focus(window: str) -> None:
    activated = run_gui(["xdotool", "windowactivate", "--sync", window], check=False)
    if activated.returncode == 0:
        return
    run_gui(["xdotool", "windowfocus", "--sync", window])


def key(*keys: str) -> None:
    run_gui(["xdotool", "key", "--clearmodifiers", *keys])


def check_text(value: str) -> None:
    if len(value) > 20000:
        raise ValueError("browser_text_too_long")
    if "\x00" in value:
        raise ValueError("browser_text_contains_nul")
    try:
        value.encode("utf-8")
    except UnicodeEncodeError:
        raise ValueError("browser_text_not_utf8") from None


def type_text(value: str) -> None:
    check_text(value)
    try:
        run_gui(
            ["xdotool", "type", "--clearmodifiers", "--delay", "1", "--file", "-"],
            timeout=30,
            input_text=value,
        )
    except subprocess.TimeoutExpired:
        raise RuntimeError("browser_type_timeout") from None
    except RuntimeError:
        raise RuntimeError("browser_type_command_failed") from None


def validate_url(value: Any) -> str:
    raw = str(value or "")
    if any(ord(ch) < 0x20 or ord(ch) == 0x7f for ch in raw):
        raise ValueError("browser_url_control_character")
    url = raw.strip()
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


def rustdesk_windows(host: str, target_id: str) -> list[str]:
    base.validate_desktop_host(host)
    require_binary("xdotool")
    aliases = tuple(item.lower() for item in DESKTOP_ALIASES.get(host, (host.lower(),)))
    found: list[str] = []
    result = run_gui(["xdotool", "search", "--onlyvisible", "--class", "rustdesk"], check=False)
    if result.returncode != 0:
        return found
    for item in (result.stdout or "").split():
        if not item.isdigit() or item in found:
            continue
        title = window_title(item).lower()
        if target_id in title or any(alias in title for alias in aliases):
            found.append(item)
    return found


def active_desktop_window(host: str, target_id: str) -> str:
    windows = rustdesk_windows(host, target_id)
    if not windows:
        raise RuntimeError("rustdesk_session_window_not_found")
    if len(windows) != 1:
        raise RuntimeError("rustdesk_session_window_ambiguous")
    window = windows[0]
    active = run_gui(["xdotool", "getactivewindow"], check=False)
    if (active.stdout or "").strip() != window:
        focus(window)
    return window


def _completed_text(value: Any) -> str:
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return str(value or "")


def native_desktop_bridge(host: str, payload: dict[str, Any]) -> dict[str, Any]:
    if host != "Fred-Win":
        raise ValueError("native_desktop_bridge_host_unsupported")
    cfg = base.validate_desktop_host(host)
    address = str(cfg.get("address") or "")
    user = str(cfg.get("user") or "")
    port = int(cfg.get("port", 22))
    argv = base.ssh_base(address, user, port) + [
        "powershell.exe",
        "-NoLogo",
        "-NoProfile",
        "-NonInteractive",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        FREDWIN_NATIVE_DESKTOP_BRIDGE,
    ]
    request = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    try:
        completed = subprocess.run(
            argv,
            input=request,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=35,
            check=False,
        )
    except subprocess.TimeoutExpired:
        raise RuntimeError("native_desktop_bridge_timeout") from None
    stdout = _completed_text(completed.stdout).strip().lstrip("\ufeff")
    if completed.returncode != 0:
        raise RuntimeError("native_desktop_bridge_failed")
    try:
        result = json.loads(stdout)
    except (TypeError, ValueError):
        raise RuntimeError("native_desktop_bridge_invalid_response") from None
    if not isinstance(result, dict):
        raise RuntimeError("native_desktop_bridge_invalid_response")
    result.setdefault("host", host)
    return result


def desktop_health(args: dict[str, Any]) -> dict[str, Any]:
    host = str(args.get("host") or "")
    if host == "Fred-Win":
        result = native_desktop_bridge(host, {"action": "health"})
        result["surface"] = "windows_interactive"
        result["display_accessible"] = bool(result.get("ok"))
        result["session_open"] = bool(result.get("ok"))
        return result
    target_id = base.rustdesk_host_id(host)
    dependencies = {name: bool(shutil.which(name)) for name in ("xdotool", "xclip", "scrot", "xwd")}
    rustdesk_launchable = os.path.isfile(RUSTDESK_BINARY) and os.access(RUSTDESK_BINARY, os.X_OK)
    display_accessible = dependencies["xdotool"] and run_gui(["xdotool", "getactivewindow"], check=False).returncode == 0
    windows = rustdesk_windows(host, target_id) if display_accessible else []
    return {
        "ok": all(dependencies.values()) and rustdesk_launchable and display_accessible and len(windows) <= 1,
        "host": host,
        "gui_user": GUI_USER,
        "display": DISPLAY,
        "dependencies": dependencies,
        "rustdesk_launchable": rustdesk_launchable,
        "display_accessible": bool(display_accessible),
        "session_window_count": len(windows),
        "session_open": len(windows) == 1,
        "surface": "rustdesk",
    }


def desktop_open(args: dict[str, Any]) -> dict[str, Any]:
    host = str(args.get("host") or "")
    if host == "Fred-Win":
        result = native_desktop_bridge(host, {"action": "health"})
        result["surface"] = "windows_interactive"
        result["action"] = "native_bridge_ready"
        return result
    target_id = base.rustdesk_host_id(host)
    windows = rustdesk_windows(host, target_id)
    if len(windows) > 1:
        raise RuntimeError("rustdesk_session_window_ambiguous")
    if len(windows) == 1:
        focus(windows[0])
        return {"ok": True, "host": host, "window_id": windows[0], "action": "focus_existing"}
    if not (os.path.isfile(RUSTDESK_BINARY) and os.access(RUSTDESK_BINARY, os.X_OK)):
        raise RuntimeError("rustdesk_binary_not_found")
    subprocess.Popen(
        gui_prefix() + [RUSTDESK_BINARY, "--connect", target_id],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    for _ in range(40):
        time.sleep(0.25)
        windows = rustdesk_windows(host, target_id)
        if len(windows) > 1:
            raise RuntimeError("rustdesk_session_window_ambiguous")
        if len(windows) == 1:
            focus(windows[0])
            return {"ok": True, "host": host, "window_id": windows[0], "action": "opened"}
    raise RuntimeError("rustdesk_session_window_timeout")


def desktop_click(args: dict[str, Any]) -> dict[str, Any]:
    host = str(args.get("host") or "")
    x = int(args.get("x"))
    y = int(args.get("y"))
    button_name = str(args.get("button") or "left")
    clicks = max(1, min(int(args.get("clicks", 1)), 3))
    if host == "Fred-Win":
        result = native_desktop_bridge(
            host,
            {"action": "click", "x": x, "y": y, "button": button_name, "clicks": clicks},
        )
        result["surface"] = "windows_interactive"
        return result
    target_id = base.rustdesk_host_id(host)
    button = {"left": "1", "middle": "2", "right": "3"}.get(button_name)
    if button is None:
        raise ValueError("desktop_invalid_mouse_button")
    window = active_desktop_window(host, target_id)
    focus(window)
    geo = parse_geometry(window)
    if not (0 <= x < geo["WIDTH"] and 0 <= y < geo["HEIGHT"]):
        raise ValueError("desktop_click_outside_window")
    absolute_x = geo["X"] + x
    absolute_y = geo["Y"] + y
    run_gui(["xdotool", "mousemove", str(absolute_x), str(absolute_y)])
    for _ in range(clicks):
        run_gui(["xdotool", "click", button])
    return {"ok": True, "host": host, "window_id": window, "x": x, "y": y, "button": button_name, "clicks": clicks}


def desktop_type(args: dict[str, Any]) -> dict[str, Any]:
    host = str(args.get("host") or "")
    text = str(args.get("text") or "")
    if not text:
        raise ValueError("desktop_text_required")
    if len(text) > 4096:
        raise ValueError("desktop_text_too_long")
    if host == "Fred-Win":
        result = native_desktop_bridge(
            host,
            {"action": "type", "text": text, "press_enter": bool(args.get("press_enter", False))},
        )
        result["surface"] = "windows_interactive"
        return result
    target_id = base.rustdesk_host_id(host)
    window = active_desktop_window(host, target_id)
    focus(window)
    require_binary("xclip")
    try:
        run_gui(["xclip", "-selection", "clipboard", "-i"], input_text=text)
        key("ctrl+v")
    finally:
        run_gui(["xclip", "-selection", "clipboard", "-i"], input_text="", check=False)
    if bool(args.get("press_enter", False)):
        key("Return")
    return {"ok": True, "host": host, "window_id": window, "typed_characters": len(text), "press_enter": bool(args.get("press_enter", False))}


def browser_health() -> dict[str, Any]:
    dependencies = {name: bool(shutil.which(name)) for name in ("xdotool", "xclip", "scrot", "xwd")}
    display_accessible = False
    if dependencies["xdotool"]:
        display_accessible = run_gui(["xdotool", "getactivewindow"], check=False).returncode == 0
    windows = browser_windows() if dependencies["xdotool"] and display_accessible else []
    browser_launchable = os.path.isfile(BROWSER_BINARY) and os.access(BROWSER_BINARY, os.X_OK)
    session_active = gui_session_active()
    return {
        "ok": all(dependencies.values()) and display_accessible and session_active and (bool(windows) or browser_launchable),
        "gui_session_active": session_active,
        **({"reason": "gui_session_inactive"} if not session_active else {}),
        "host": BROWSER_HOST,
        "display": DISPLAY,
        "gui_user": GUI_USER,
        "dependencies": dependencies,
        "display_accessible": display_accessible,
        "browser_launchable": browser_launchable,
        "window_count": len(windows),
    }


# Dedicated corporate-account MCP bridges use CDP rather than the general GUI
# browser. Their liveness must not block on X11/xdotool in fredconsole/:0.
# This is a TRANSPORT check only, never evidence of ChatGPT sign-in.
DEDICATED_BROWSER_BRIDGES = {
    "5582": ("atendimento", "http://127.0.0.1:9556"),
    "5583": ("dev", "http://127.0.0.1:9559"),
}


def browser_service_health() -> dict[str, Any]:
    service_port = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_PORT", "").strip()
    binding = DEDICATED_BROWSER_BRIDGES.get(service_port)
    if binding is None:
        return browser_health()

    session, endpoint = binding
    result: dict[str, Any] = {
        "ok": False,
        "host": BROWSER_HOST,
        "session": session,
        "cdp_reachable": False,
        "account_authenticated": None,
        "account_identity_verified": False,
    }
    if (os.environ.get("SHOPVIVALIZ_BROWSER_SESSION_NAME", "").strip() != session
            or os.environ.get("SHOPVIVALIZ_BROWSER_CDP_URL", "").rstrip("/") != endpoint):
        result["reason"] = "cdp_session_binding_mismatch"
        return result

    # Never ask GUI automation for a health request. An overloaded/missing
    # Chromium process returns a bounded, honest negative result.
    try:
        with urlopen(endpoint + "/json/version", timeout=2) as response:
            raw = response.read(65537)
        if len(raw) > 65536:
            raise ValueError("cdp_version_oversized")
        version = json.loads(raw)
        ws = version.get("webSocketDebuggerUrl") if isinstance(version, dict) else None
        if not isinstance(ws, str):
            raise ValueError("cdp_browser_socket_missing")
        parsed = urlsplit(ws)
        cdp_port = int(urlsplit(endpoint).port or 0)
        if parsed.scheme != "ws" or parsed.hostname not in {"127.0.0.1", "localhost"} or parsed.port != cdp_port:
            raise ValueError("cdp_browser_socket_invalid")
    except (OSError, ValueError, TypeError):
        result["reason"] = "cdp_unavailable"
        return result
    result.update({"ok": True, "cdp_reachable": True})
    return result


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
    if not gui_session_active():
        raise RuntimeError("gui_session_inactive")
    windows = browser_windows()
    if windows:
        focus(windows[0])
        key("ctrl+t")
        type_text(url)
        key("Return")
        return {"ok": True, "host": BROWSER_HOST, "surface": "isolated_gui", "action": "new_tab", "url": safe_url(url)}
    candidates = ["google-chrome", "google-chrome-stable", "chromium-browser", "chromium"]
    binary = BROWSER_BINARY if os.path.isfile(BROWSER_BINARY) and os.access(BROWSER_BINARY, os.X_OK) else next((name for name in candidates if shutil.which(name)), None)
    if not binary:
        raise RuntimeError("browser_binary_not_found")
    subprocess.Popen(
        gui_prefix() + [
            binary,
            f"--user-data-dir={BROWSER_PROFILE_DIR}",
            f"--class={BROWSER_WINDOW_CLASS}",
            "--no-sandbox",
            "--no-first-run",
            "--no-default-browser-check",
            "--new-window",
            url,
        ],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    return {"ok": True, "host": BROWSER_HOST, "surface": "isolated_gui", "action": "new_window", "url": safe_url(url)}


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
    run_gui(["xdotool", "mousemove", str(x), str(y)])
    for _ in range(clicks):
        run_gui(["xdotool", "click", button])
    return {"ok": True, "host": BROWSER_HOST, "window_id": window, "x": x, "y": y, "button": button_name, "clicks": clicks}


def browser_type(args: dict[str, Any]) -> dict[str, Any]:
    text = str(args.get("text") or "")
    if not text:
        raise ValueError("browser_text_required")
    check_text(text)
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


def _png_chunk(kind: bytes, payload: bytes) -> bytes:
    checksum = binascii.crc32(kind + payload) & 0xFFFFFFFF
    return struct.pack(">I", len(payload)) + kind + payload + struct.pack(">I", checksum)


def _xwd_channel(value: int, mask: int) -> int:
    if mask <= 0:
        raise ValueError("xwd_color_mask_invalid")
    shift = (mask & -mask).bit_length() - 1
    maximum = mask >> shift
    if maximum <= 0:
        raise ValueError("xwd_color_mask_invalid")
    sample = (value & mask) >> shift
    return (sample * 255 + maximum // 2) // maximum


def xwd_to_png_bytes(raw: bytes) -> bytes:
    if len(raw) < 100 or len(raw) > MAX_XWD_BYTES:
        raise ValueError("xwd_size_invalid")
    fields = struct.unpack(">25I", raw[:100])
    (
        header_size, file_version, pixmap_format, _pixmap_depth, width, height,
        _xoffset, byte_order, _bitmap_unit, _bitmap_bit_order, _bitmap_pad,
        bits_per_pixel, bytes_per_line, _visual_class, red_mask, green_mask,
        blue_mask, _bits_per_rgb, _colormap_entries, ncolors, *_window_fields,
    ) = fields
    if file_version != 7 or pixmap_format != 2:
        raise ValueError("xwd_format_unsupported")
    if width < 1 or height < 1 or width * height > 16_777_216:
        raise ValueError("xwd_dimensions_invalid")
    if bits_per_pixel not in {16, 24, 32} or byte_order not in {0, 1}:
        raise ValueError("xwd_pixel_format_unsupported")
    bytes_per_pixel = bits_per_pixel // 8
    if bytes_per_line < width * bytes_per_pixel:
        raise ValueError("xwd_stride_invalid")
    pixel_offset = header_size + ncolors * 12
    pixel_bytes = bytes_per_line * height
    if header_size < 100 or pixel_offset < header_size or pixel_offset + pixel_bytes > len(raw):
        raise ValueError("xwd_payload_invalid")
    if red_mask & green_mask or red_mask & blue_mask or green_mask & blue_mask:
        raise ValueError("xwd_color_mask_invalid")

    endian = "little" if byte_order == 0 else "big"
    scanlines = bytearray()
    pixels = memoryview(raw)
    for y in range(height):
        scanlines.append(0)
        row_start = pixel_offset + y * bytes_per_line
        for x in range(width):
            start = row_start + x * bytes_per_pixel
            value = int.from_bytes(pixels[start:start + bytes_per_pixel], endian)
            scanlines.extend((
                _xwd_channel(value, red_mask),
                _xwd_channel(value, green_mask),
                _xwd_channel(value, blue_mask),
            ))

    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return (
        b"\x89PNG\r\n\x1a\n"
        + _png_chunk(b"IHDR", ihdr)
        + _png_chunk(b"IDAT", zlib.compress(bytes(scanlines), 6))
        + _png_chunk(b"IEND", b"")
    )


def capture_xwd_window(window: str, host: str, prefix: str) -> dict[str, Any]:
    focus(window)
    require_binary("xwd")
    tmpdir = tempfile.mkdtemp(prefix=prefix)
    gui = pwd.getpwnam(GUI_USER)
    os.chown(tmpdir, gui.pw_uid, gui.pw_gid)
    os.chmod(tmpdir, 0o700)
    path = str(Path(tmpdir) / "screenshot.xwd")
    try:
        run_gui(["xwd", "-silent", "-id", window, "-out", path], timeout=20)
        xwd = Path(path).read_bytes()
        raw = xwd_to_png_bytes(xwd)
        if not raw or len(raw) > MAX_SCREENSHOT_BYTES:
            raise RuntimeError("screenshot_size_invalid")
        return {
            "ok": True, "host": host, "window_id": window, "mime_type": "image/png",
            "sha256": hashlib.sha256(raw).hexdigest(), "bytes": len(raw),
            "__mcp_image__": base64.b64encode(raw).decode("ascii"),
        }
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def capture_window(window: str, host: str, prefix: str) -> dict[str, Any]:
    focus(window)
    tmpdir = tempfile.mkdtemp(prefix=prefix)
    gui = pwd.getpwnam(GUI_USER)
    os.chown(tmpdir, gui.pw_uid, gui.pw_gid)
    os.chmod(tmpdir, 0o700)
    path = str(Path(tmpdir) / "screenshot.png")
    try:
        if shutil.which("scrot"):
            run_gui(["scrot", "-u", path], timeout=20)
        elif shutil.which("gnome-screenshot"):
            run_gui(["gnome-screenshot", "-f", path], timeout=20)
        elif shutil.which("import"):
            run_gui(["import", "-window", window, path], timeout=20)
        else:
            raise RuntimeError("screenshot_dependency_missing")
        raw = Path(path).read_bytes()
        if not raw or len(raw) > MAX_SCREENSHOT_BYTES:
            raise RuntimeError("screenshot_size_invalid")
        return {
            "ok": True, "host": host, "window_id": window, "mime_type": "image/png",
            "sha256": hashlib.sha256(raw).hexdigest(), "bytes": len(raw),
            "__mcp_image__": base64.b64encode(raw).decode("ascii"),
        }
    finally:
        shutil.rmtree(tmpdir, ignore_errors=True)


def browser_screenshot() -> dict[str, Any]:
    return capture_window(active_browser_window(), BROWSER_HOST, "shopvivaliz-browser-")


def desktop_screenshot(args: dict[str, Any]) -> dict[str, Any]:
    host = str(args.get("host") or "")
    if host == "Fred-Win":
        result = native_desktop_bridge(host, {"action": "screenshot"})
        image_b64 = result.pop("image_b64", None)
        if not image_b64:
            raise RuntimeError("native_desktop_screenshot_missing")
        result["surface"] = "windows_interactive"
        result["__mcp_image__"] = image_b64
        return result
    target_id = base.rustdesk_host_id(host)
    window = active_desktop_window(host, target_id)
    result = capture_xwd_window(window, host, "shopvivaliz-desktop-")
    result["surface"] = "rustdesk"
    return result


BASE_EXECUTE_TOOL = base.execute_tool
BASE_TOOL_SPECS = base.tool_specs
BASE_AUDIT = base.audit


def desktop_session_attach(args: dict[str, Any]) -> dict[str, Any]:
    """Attach to an existing authorized desktop without launching duplicates."""
    host = str(args.get("host") or "")
    base.validate_desktop_host(host)
    result = desktop_open({"host": host})
    if not result.get("ok", False):
        return result
    return {**result, "attached": True, "host": host}


def browser_dom_inspect(args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    """Return sanitized interactive DOM controls for one canonical tab."""
    tab_id = str(args.get("tab_id") or "")
    if not tab_id:
        raise ValueError("tab_id_required")
    return BASE_EXECUTE_TOOL("browser_controls", {"tab_id": tab_id}, cancel_check=cancel_check)


def browser_click_index(args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    """Click an inspected control index in an allowlisted canonical browser tab."""
    tab_id = str(args.get("tab_id") or "")
    index = args.get("index")
    if not tab_id:
        raise ValueError("tab_id_required")
    if isinstance(index, bool) or not isinstance(index, int) or not 0 <= index < 120:
        raise ValueError("invalid_control_index")
    base._assert_runtime_mutation("browser_click_control", args)
    return BASE_EXECUTE_TOOL("browser_click_control", {"tab_id": tab_id, "index": index}, cancel_check=cancel_check)


UNIVERSAL_MCP_TOOLS = {
    "browser_universal_probe", "browser_universal_open", "browser_universal_inspect",
    "browser_universal_click", "browser_universal_fill", "browser_universal_select",
    "browser_universal_check", "browser_universal_press", "browser_universal_upload",
    "browser_universal_download", "browser_universal_tabs_list",
    "browser_universal_tabs_open", "browser_universal_tabs_switch",
    "browser_universal_tabs_close",
}

UNIVERSAL_MCP_SCHEMAS: list[dict[str, Any]] = [
    {"name": name,
     "description": "Isolated Playwright browser; public websites only. " + name,
     "inputSchema": {"type": "object", "properties": props, "required": req, "additionalProperties": False},
     "annotations": {"readOnlyHint": name.endswith(("_list", "_inspect", "_probe")), "openWorldHint": True,
                     "destructiveHint": not name.endswith(("_list", "_inspect", "_probe", "_open"))}}
    for name, props, req in [
        ("browser_universal_probe", {}, []),
        ("browser_universal_open", {"url": {"type": "string"}}, ["url"]),
        ("browser_universal_inspect", {"url": {"type": "string"}, "tab_id": {"type": "string"}}, []),
        ("browser_universal_click", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}}, ["selector"]),
        ("browser_universal_fill", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}, "text": {"type": "string"}}, ["selector", "text"]),
        ("browser_universal_select", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}, "value": {"type": "string"}}, ["selector", "value"]),
        ("browser_universal_check", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}}, ["selector"]),
        ("browser_universal_press", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}, "key": {"type": "string", "enum": ["Enter", "Tab", "Escape", "ArrowUp", "ArrowDown", "Space"]}}, ["selector", "key"]),
        ("browser_universal_upload", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}, "filename": {"type": "string"}}, ["selector", "filename"]),
        ("browser_universal_download", {"url": {"type": "string"}, "tab_id": {"type": "string"}, "selector": {"type": "string"}}, ["selector"]),
        ("browser_universal_tabs_list", {}, []),
        ("browser_universal_tabs_open", {"url": {"type": "string"}}, ["url"]),
        ("browser_universal_tabs_switch", {"tab_id": {"type": "string"}}, ["tab_id"]),
        ("browser_universal_tabs_close", {"tab_id": {"type": "string"}}, ["tab_id"]),
    ]
]


def universal_browser_call(name: str, args: dict[str, Any]) -> dict[str, Any]:
    if name not in UNIVERSAL_MCP_TOOLS:
        raise ValueError("unknown_universal_browser_tool")
    request = Request(
        "http://127.0.0.1:5595/mcp",
        data=json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/call",
                         "params": {"name": name, "arguments": args}}).encode("utf-8"),
        headers={"Authorization": "Bearer " + base.AUTH_TOKEN, "Content-Type": "application/json"},
        method="POST",
    )
    with urlopen(request, timeout=60) as response:
        payload = json.load(response)
    if payload.get("error"):
        return {"ok": False, "error": base.redact_text(str(payload["error"].get("message", "browser_backend_error")))}
    items = payload.get("result", {}).get("content", [])
    if not items or items[0].get("type") != "text":
        return {"ok": False, "error": "invalid_universal_browser_response"}
    value = json.loads(items[0].get("text", "{}"))
    return value if isinstance(value, dict) else {"ok": False, "error": "invalid_universal_browser_result"}



# Pre-login mutations deliberately use a distinct, narrowly scoped maintenance
# lease. Conversation mutation leases cannot exist until the account is signed in.
# Normal browser_* mutations remain behind the original conversation gate.
ACCOUNT_AUTH_PORTS = {"dev": 9559, "atendimento": 9556}
ACCOUNT_AUTH_EMAILS = {
    "dev": "dev@shopvivaliz.com.br",
    "atendimento": "atendimento@shopvivaliz.com.br",
}
ACCOUNT_AUTH_ACTIONS = {"fill_email", "fill_password", "fill_code", "continue", "resend", "back_to_methods", "open_login", "continue_google", "continue_microsoft"}

ACCOUNT_AUTH_NODE_SCRIPT = r"""
const { Cdp } = await import("file:///home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs");
const [session, tabId, action] = process.argv.slice(1);
const ports = {dev: 9559, atendimento: 9556};
if (!Object.prototype.hasOwnProperty.call(ports, session)) throw new Error("invalid_auth_session");
if (!["fill_email","fill_password","fill_code","continue","resend","back_to_methods","open_login","continue_google","continue_microsoft"].includes(action)) throw new Error("invalid_auth_action");
let input = "";
for await (const chunk of process.stdin) input += chunk;
if (input.length > 512) throw new Error("auth_text_too_long");
const port = ports[session];
const validStage = u => u.protocol === "https:" && (
    (u.hostname === "auth.openai.com" && (u.pathname === "/log-in-or-create-account" || /^\/(?:log-in|email-verification)(?:\/|$)/.test(u.pathname))) ||
    (u.hostname === "chatgpt.com" && (u.pathname === "/auth/login_with" || /^\/auth\/login(?:\/|$)/.test(u.pathname)))
);
const tabs = await (await fetch("http://127.0.0.1:" + port + "/json", {
    signal: AbortSignal.timeout(2500)
})).json();
const matches = tabs.filter(t => t?.type === "page" && t?.id === tabId && t.webSocketDebuggerUrl);
if (matches.length !== 1) throw new Error("auth_tab_not_unique");
const t = matches[0];
let targetUrl;
try { targetUrl = new URL(String(t.url || "")); } catch { throw new Error("auth_stage_not_allowed"); }
if (!validStage(targetUrl)) throw new Error("auth_stage_not_allowed");
let c, ws;
try {
    ws = new WebSocket(t.webSocketDebuggerUrl);
    await Promise.race([
        new Promise((resolve, reject) => {
            ws.addEventListener("open", resolve, {once:true});
            ws.addEventListener("error", reject, {once:true});
        }),
        new Promise((_, reject) => setTimeout(() => reject(new Error("auth_socket_timeout")), 2500))
    ]);
    // A previously backgrounded login page can be frozen even when CDP's
    // /json endpoint remains healthy. Activate only the already verified
    // official login tab; do not reload, navigate or change browser profiles.
    c = new Cdp(ws, { commandTimeoutMs: 7000 });
    try {
        await c.send("Page.bringToFront");
    } catch {
        throw new Error("auth_tab_activate_failed");
    }
    const expr = "(()=>{" +
        "const u=new URL(location.href);" +
        "const allowed=u.protocol==='https:'&&((u.hostname==='auth.openai.com'&&(u.pathname==='/log-in-or-create-account'||/^\\/(?:log-in|email-verification)(?:\\/|$)/.test(u.pathname)))||(u.hostname==='chatgpt.com'&&(u.pathname==='/auth/login_with'||/^\\/auth\\/login(?:\\/|$)/.test(u.pathname))));" +
        "if(!allowed)throw Error('auth_stage_not_allowed');" +
        "const action=" + JSON.stringify(action) + ";" +
        "if(['back_to_methods','open_login','continue_google','continue_microsoft'].includes(action)){" +
        "let choices=[];" +
        "const label=e=>String(e.innerText||e.getAttribute('aria-label')||'').trim();" +
        "const safeLink=(a,origin,path)=>{try{const d=new URL(a.href);return d.origin===origin&&d.pathname===path;}catch{return false;}};" +
        "if(action==='back_to_methods'){" +
        "if(u.hostname!=='auth.openai.com'||u.pathname!=='/log-in/password')throw Error('auth_stage_not_allowed');" +
        "choices=[...document.querySelectorAll('a')].filter(a=>safeLink(a,'https://auth.openai.com','/log-in-or-create-account')&&/^edit$/i.test(label(a)));" +
        "}" +
        "if(action==='open_login'){" +
        "if(u.hostname!=='auth.openai.com'||u.pathname!=='/log-in-or-create-account')throw Error('auth_stage_not_allowed');" +
        "choices=[...document.querySelectorAll('a')].filter(a=>safeLink(a,'https://chatgpt.com','/auth/login_with')&&/^log in$/i.test(label(a)));" +
        "}" +
        "if(action==='continue_google'||action==='continue_microsoft'){" +
        "if(!((u.hostname==='auth.openai.com'&&u.pathname==='/log-in')||(u.hostname==='chatgpt.com'&&u.pathname==='/auth/login_with')))throw Error('auth_stage_not_allowed');" +
        "const provider=action==='continue_google'?'google':'microsoft';choices=[...document.querySelectorAll('button,a')].filter(e=>!e.disabled&&new RegExp('^continue with '+provider+'$','i').test(label(e)));" +
        "}" +
        "if(choices.length!==1)throw Error('auth_action_ambiguous_or_unavailable');" +
        "choices[0].click();return {clicked:true};" +
        "}" +
        "if(action.startsWith('fill_')){" +
        "let e=null;" +
        "if(action==='fill_email')e=document.querySelector('input[type=email],input[name=email]');" +
        "if(action==='fill_password')e=document.querySelector('input[type=password]');" +
        "if(action==='fill_code')e=document.querySelector('input[autocomplete=one-time-code],input[name=code]');" +
        "if(!e||e.tagName!=='INPUT'||e.disabled||e.readOnly)throw Error('auth_field_unavailable');" +
        "e.focus();e.select();return {ready:true};" +
        "}" +
        "if(action==='continue'&&u.hostname==='auth.openai.com'&&u.pathname==='/log-in'&&" +
    "document.body?.innerText?.includes('Your session has ended')){" +
    "const safeLink=(a,origin,path)=>{try{const d=new URL(a.href);return d.origin===origin&&d.pathname===path;}catch{return false;}};" +
    "const recoveryLinks=[...document.querySelectorAll('a')].filter(a=>safeLink(a,'https://chatgpt.com','/auth/login_with')&&/^log in$/i.test(String(a.innerText||'').trim()));" +
    "if(recoveryLinks.length!==1)throw Error('auth_action_ambiguous_or_unavailable');" +
    "recoveryLinks[0].click();return {clicked:true};" +
    "}" +
    "const buttons=[...document.querySelectorAll('button,input[type=submit]')].filter(b=>!b.disabled);" +
        "const labels=action==='resend'?/^(?:resend(?: (?:email|e-mail|code))?|send a new (?:email|code)|reenviar(?: (?:e-?mail|c[oó]digo))?)$/i:/^(?:continue|next|log in|sign in|verify|confirm|continuar|entrar|verificar)$/i;" +
        "const matches=buttons.filter(b=>labels.test(String(b.innerText||b.value||'').trim()));" +
        "if(matches.length!==1)throw Error('auth_button_ambiguous_or_unavailable');" +
        "matches[0].click();return {clicked:true};" +
        "})()";
    const r = await c.send("Runtime.evaluate",{expression:expr,returnByValue:true,awaitPromise:true});
    if (r.exceptionDetails) throw new Error("auth_ui_preflight_failed");
    const state = r.result?.value || {};
    if (action.startsWith("fill_")) {
        if (state.ready !== true) throw new Error("auth_field_unavailable");
        await c.send("Input.insertText", {text:input});
        console.log(JSON.stringify({ok:true,session,action,typed:true}));
    } else {
        if (state.clicked !== true) throw new Error("auth_button_unavailable");
        console.log(JSON.stringify({ok:true,session,action,clicked:true}));
    }
} catch (error) {
    const reason = String(error?.message || "auth_action_failed");
    const known = /^(auth_[a-z_]+|invalid_auth_session|invalid_auth_action)$/;
    console.error(known.test(reason) ? reason : "auth_action_failed");
    process.exitCode = 1;
} finally {
    try { c?.close(); } catch {}
    try { ws?.close(); } catch {}
}
"""

ACCOUNT_AUTH_OPEN_NODE_SCRIPT = r"""
// Open a NEW fixed official sign-in tab in the already dedicated browser
// profile. No existing tab, conversation, cookies or local storage is edited.
const { Cdp } = await import("file:///home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs");
const [session] = process.argv.slice(1);
const ports = { dev: 9559, atendimento: 9556 };
if (!Object.prototype.hasOwnProperty.call(ports, session)) throw new Error("invalid_auth_session");
const port = ports[session];
const origin = "http://127.0.0.1:" + port;
const preflight = await (await fetch(origin + "/json", {signal:AbortSignal.timeout(3000)})).json();
if (!Array.isArray(preflight) || preflight.length > 512) throw new Error("auth_tabs_invalid");
const stage = u => u.protocol === "https:" && (
    (u.hostname === "auth.openai.com" &&
        (u.pathname === "/log-in-or-create-account" || /^\/(?:log-in|email-verification)(?:\/|$)/.test(u.pathname))) ||
    (u.hostname === "chatgpt.com" &&
        (u.pathname === "/auth/login_with" || /^\/auth\/login(?:\/|$)/.test(u.pathname)))
);
if (preflight.some(t => {
    if (t?.type !== "page") return false;
    try { return stage(new URL(t.url)); } catch { return false; }
})) throw new Error("auth_stage_already_open");
const version = await (await fetch(origin + "/json/version", {signal:AbortSignal.timeout(3000)})).json();
const endpoint = new URL(String(version.webSocketDebuggerUrl || ""));
if (endpoint.protocol !== "ws:" ||
    !["127.0.0.1", "localhost"].includes(endpoint.hostname) ||
    Number(endpoint.port) !== port ||
    !endpoint.pathname.startsWith("/devtools/browser/"))
    throw new Error("auth_browser_socket_invalid");
let ws, c;
try {
    ws = new WebSocket(endpoint.href);
    await Promise.race([
        new Promise((resolve, reject) => {
            ws.addEventListener("open", resolve, {once:true});
            ws.addEventListener("error", reject, {once:true});
        }),
        new Promise((_, reject) => setTimeout(() => reject(new Error("auth_socket_timeout")), 2500))
    ]);
    c = new Cdp(ws, {commandTimeoutMs:7000});
    const result = await c.send("Target.createTarget", {
        url:"https://chatgpt.com/auth/login", background:true, newWindow:false
    });
    const tabId = String(result?.targetId || "");
    if (!/^[A-F0-9]{32}$/i.test(tabId)) throw new Error("auth_open_no_tab");
    console.log(JSON.stringify({ok:true, session, tab_id:tabId, stage:"/auth/login"}));
} catch (error) {
    const reason = String(error?.message || "auth_open_failed");
    console.error(/^auth_[a-z_]+$/.test(reason) ? reason : "auth_open_failed");
    process.exitCode = 1;
} finally {
    try { c?.close(); } catch {}
    try { ws?.close(); } catch {}
}
"""

def _auth_stage(url: str) -> str | None:
    try:
        parsed = urlsplit(url)
        if parsed.scheme != "https":
            return None
        if parsed.hostname == "auth.openai.com" and (
            parsed.path == "/log-in-or-create-account"
            or re.match(r"^/(log-in|email-verification)(/|$)", parsed.path)
        ):
            return parsed.path
        if parsed.hostname == "chatgpt.com" and (
            parsed.path == "/auth/login_with"
            or re.match(r"^/auth/login(/|$)", parsed.path)
        ):
            return parsed.path
    except (TypeError, ValueError):
        return None
    return None


def browser_auth_tabs(args: dict[str, Any]) -> dict[str, Any]:
    session = str(args.get("session") or "")
    if session not in ACCOUNT_AUTH_PORTS:
        raise ValueError("account_auth_session_invalid")
    endpoint = "http://127.0.0.1:" + str(ACCOUNT_AUTH_PORTS[session]) + "/json"
    with urlopen(endpoint, timeout=4) as response:
        raw = response.read(1_048_577)
    if len(raw) > 1_048_576:
        raise ValueError("account_auth_tabs_oversized")
    pages = json.loads(raw)
    if not isinstance(pages, list) or len(pages) > 512:
        raise ValueError("account_auth_tabs_invalid")
    tabs = []
    for item in pages[:128]:
        if not isinstance(item, dict) or item.get("type") != "page":
            continue
        stage = _auth_stage(str(item.get("url") or ""))
        if stage is not None and re.fullmatch(r"[A-Za-z0-9_.:-]{1,240}", str(item.get("id") or "")):
            tabs.append({"tab_id": item["id"], "stage": stage, "session": session})
    return {"ok": True, "session": session, "tabs": tabs}


def browser_auth_open(args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    """Open only a new official sign-in tab under a real account maintenance lease."""
    session = str(args.get("session") or "")
    if session not in ACCOUNT_AUTH_PORTS:
        raise ValueError("account_auth_session_invalid")
    if not base._durable_handoff_enabled():
        raise ValueError("account_auth_lease_mode_required")
    lease_id = str(args.get("runtime_lease_id") or "")
    token = args.get("runtime_fencing_token")
    if not lease_id or token is None:
        raise ValueError("account_auth_runtime_lock_required")
    try:
        lease = base.runtime_lock.assert_runtime_lock(lease_id, int(token), "browser_auth_open")
    except (ValueError, TypeError, base.runtime_lock.RuntimeLockConflict):
        raise ValueError("account_auth_runtime_lock_invalid") from None
    if (lease.get("owner_kind") != "maintenance"
            or lease.get("owner_id") != "shopvivaliz-account-auth:" + session):
        raise ValueError("account_auth_maintenance_owner_required")
    invocation = [base.BROWSER_NODE_BIN, "--input-type=module", "-e",
                  ACCOUNT_AUTH_OPEN_NODE_SCRIPT, session]
    result = base.run_local_command_with_stdin(invocation, "", base.DEFAULT_TIMEOUT, cancel_check)
    result["ok"] = result.get("exit_code") == 0
    return result


def browser_auth_action(args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    session = str(args.get("session") or "")
    action = str(args.get("action") or "")
    tab_id = str(args.get("tab_id") or "")
    if session not in ACCOUNT_AUTH_PORTS or action not in ACCOUNT_AUTH_ACTIONS:
        raise ValueError("account_auth_scope_invalid")
    if not re.fullmatch(r"[A-Za-z0-9_.:-]{1,240}", tab_id):
        raise ValueError("account_auth_tab_invalid")
    if not base._durable_handoff_enabled():
        raise ValueError("account_auth_lease_mode_required")
    lease_id = str(args.get("runtime_lease_id") or "")
    fencing_token = args.get("runtime_fencing_token")
    if not lease_id or fencing_token is None:
        raise ValueError("account_auth_runtime_lock_required")
    try:
        lease = base.runtime_lock.assert_runtime_lock(lease_id, int(fencing_token), "browser_auth_action")
    except (ValueError, TypeError, base.runtime_lock.RuntimeLockConflict):
        raise ValueError("account_auth_runtime_lock_invalid") from None
    if (lease.get("owner_kind") != "maintenance"
            or lease.get("owner_id") != "shopvivaliz-account-auth:" + session):
        raise ValueError("account_auth_maintenance_owner_required")
    value = str(args.get("value") or "")
    if action.startswith("fill_"):
        if not value or len(value) > 512 or "\x00" in value or "\n" in value:
            raise ValueError("account_auth_value_invalid")
        if action == "fill_email" and value.strip().lower() != ACCOUNT_AUTH_EMAILS[session]:
            raise ValueError("account_auth_email_mismatch")
        if action == "fill_code" and not re.fullmatch(r"[A-Za-z0-9]{4,32}", value):
            raise ValueError("account_auth_code_invalid")
    elif value:
        raise ValueError("account_auth_value_not_allowed")
    invocation = [
        base.BROWSER_NODE_BIN, "--input-type=module", "-e",
        ACCOUNT_AUTH_NODE_SCRIPT, session, tab_id, action,
    ]
    result = base.run_local_command_with_stdin(invocation, value, base.DEFAULT_TIMEOUT, cancel_check)
    result["ok"] = result.get("exit_code") == 0
    return result


def execute_tool(name: str, args: dict[str, Any], cancel_check=None) -> dict[str, Any]:
    if name == "browser_auth_tabs":
        return browser_auth_tabs(args)
    if name == "browser_auth_open":
        return browser_auth_open(args, cancel_check=cancel_check)
    if name == "browser_auth_action":
        return browser_auth_action(args, cancel_check=cancel_check)
    if name in UNIVERSAL_MCP_TOOLS:
        return universal_browser_call(name, args)
    if name == "desktop.session.attach":
        return desktop_session_attach(args)
    if name == "browser.dom.inspect":
        return browser_dom_inspect(args, cancel_check=cancel_check)
    if name == "browser.click":
        return browser_click_index(args, cancel_check=cancel_check)
    if name in {"desktop_open", "desktop_click", "desktop_type"}:
        base._assert_runtime_mutation(name, args)
    if name == "desktop_health":
        return desktop_health(args)
    if name == "desktop_open":
        return desktop_open(args)
    if name == "desktop_screenshot":
        return desktop_screenshot(args)
    if name == "desktop_click":
        return desktop_click(args)
    if name == "desktop_type":
        return desktop_type(args)
    if name in ATTENDIMENTO_TOOL_MAP:
        return BASE_EXECUTE_TOOL(ATTENDIMENTO_TOOL_MAP[name], args, cancel_check=cancel_check)
    if name == "browser_health":
        return browser_health()
    if name == "browser_gui_tabs":
        return browser_tabs()
    if name == "browser_open":
        return browser_open(args)
    if name == "browser_gui_navigate":
        return browser_navigate(args)
    if name == "browser_screenshot":
        return browser_screenshot()
    if name == "browser_gui_click":
        return browser_click(args)
    if name == "browser_gui_type":
        return browser_type(args)

    # Compatibility shim for connectors that expose the graphical browser
    # contracts under the legacy public names. Preserve the canonical CDP
    # actions when their tab_id/selector arguments are present.
    if name == "browser_navigate" and "tab_id" not in args:
        return browser_navigate(args)
    if name == "browser_click" and "x" in args and "y" in args:
        return browser_click(args)
    if name == "browser_type" and "tab_id" not in args and "selector" not in args:
        return browser_type(args)

    return BASE_EXECUTE_TOOL(name, args, cancel_check=cancel_check)


def audit(tool: str, host: str | None, args: dict[str, Any], ok: bool, summary: str) -> str:
    safe = dict(args)
    if tool == "browser_auth_action" and "value" in safe:
        raw = str(safe.pop("value"))
        safe["value_length"] = len(raw)
    if tool in {"browser_gui_type", "browser_type", "browser_atendimento_type", "browser_universal_fill"} and "text" in safe:
        raw = str(safe.pop("text"))
        safe.pop("text_sha256", None)
        safe["text_length"] = len(raw)
    if (tool in {"browser_open", "browser_gui_navigate", "browser_atendimento_navigate"} or tool in UNIVERSAL_MCP_TOOLS) and "url" in safe:
        safe["url"] = safe_url(str(safe["url"]))
    return BASE_AUDIT(tool, host or (BROWSER_HOST if tool in BROWSER_TOOLS else host), safe, ok, summary)


BROWSER_TOOL_SPECS = [
    {
        "name": "browser_auth_open",
        "description": "Open a new fixed official ChatGPT sign-in tab only in the requested isolated corporate profile using a valid account-specific maintenance runtime lease. Never touches an existing conversation or secrets.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "session": {"type": "string", "enum": ["dev", "atendimento"]},
                "runtime_lease_id": {"type": "string", "maxLength": 200},
                "runtime_fencing_token": {"type": "integer", "minimum": 1},
            },
            "required": ["session", "runtime_lease_id", "runtime_fencing_token"],
            "additionalProperties": False,
        },
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_auth_tabs",
        "description": "List only official login-stage tabs for one isolated Dev or Atendimento ChatGPT profile, excluding URLs, query parameters, and secrets.",
        "inputSchema": {"type": "object", "properties": {"session": {"type": "string", "enum": ["dev", "atendimento"]}}, "required": ["session"], "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_auth_action",
        "description": "Perform a tightly restricted official ChatGPT login step for one isolated session only with a live account-specific maintenance runtime lease. Values use protected stdin and are never recorded; does not bypass MFA, CAPTCHA, OAuth consent, or conversation mutation gates.",
        "inputSchema": {
            "type": "object",
            "properties": {
                "session": {"type": "string", "enum": ["dev", "atendimento"]},
                "tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"},
                "action": {"type": "string", "enum": ["fill_email", "fill_password", "fill_code", "continue", "resend", "back_to_methods", "open_login", "continue_google", "continue_microsoft"]},
                "value": {"type": "string", "maxLength": 512},
                "runtime_lease_id": {"type": "string", "maxLength": 200},
                "runtime_fencing_token": {"type": "integer", "minimum": 1},
            },
            "required": ["session", "tab_id", "action", "runtime_lease_id", "runtime_fencing_token"],
            "additionalProperties": False,
        },
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": True},
    },
    {
        "name": "desktop.session.attach",
        "description": "Attach or focus an existing approved Fred-Win or KOCEPSV desktop session without creating duplicate sessions.",
        "inputSchema": {"type": "object", "properties": {"host": {"type": "string", "enum": ["Fred-Win", "KOCEPSV"]}}, "required": ["host"], "additionalProperties": False},
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser.dom.inspect",
        "description": "Inspect sanitized visible interactive DOM controls in an allowlisted canonical browser tab.",
        "inputSchema": {"type": "object", "properties": {"tab_id": {"type": "string", "minLength": 1}}, "required": ["tab_id"], "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser.click",
        "description": "Click a DOM control by index from browser.dom.inspect in an allowlisted canonical browser tab.",
        "inputSchema": {"type": "object", "properties": {"tab_id": {"type": "string", "minLength": 1}, "index": {"type": "integer", "minimum": 0, "maximum": 119}}, "required": ["tab_id", "index"], "additionalProperties": False},
        "annotations": {"readOnlyHint": False, "openWorldHint": False, "destructiveHint": True},
    },
    {
        "name": "browser_health",
        "description": "Check graphical backend browser dependencies and visible browser window availability.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_gui_tabs",
        "description": "List tabs from the isolated graphical helper browser; canonical continuity tabs use browser_tabs.",
        "inputSchema": {"type": "object", "properties": {}, "additionalProperties": False},
        "annotations": {"readOnlyHint": True, "openWorldHint": False, "destructiveHint": False},
    },
    {
        "name": "browser_open",
        "description": "Open an http(s) URL in the isolated graphical helper browser. The returned surface is isolated_gui; browser_tabs lists the canonical continuity session, while browser_gui_tabs lists this helper.",
        "inputSchema": {"type": "object", "properties": {"url": {"type": "string"}}, "required": ["url"], "additionalProperties": False},
        "annotations": {"readOnlyHint": False, "openWorldHint": True, "destructiveHint": False},
    },
    {
        "name": "browser_gui_navigate",
        "description": "Navigate the isolated graphical helper browser; canonical continuity navigation uses browser_navigate.",
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
        "name": "browser_gui_click",
        "description": "Click absolute coordinates in the isolated graphical helper browser.",
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
        "name": "browser_gui_type",
        "description": "Type text into the focused element of the isolated graphical helper browser. Typed text is never persisted in audit logs.",
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


def atendimento_tool_specs() -> list[dict[str, Any]]:
    base_specs = {spec["name"]: spec for spec in BASE_TOOL_SPECS()}
    out = []
    for public_name, base_name in ATTENDIMENTO_TOOL_MAP.items():
        src = base_specs[base_name]
        spec = dict(src)
        spec["name"] = public_name
        spec["description"] = f"Use the isolated atendimento ChatGPT session: {src['description']}"
        out.append(spec)
    return out


def tool_specs() -> list[dict[str, Any]]:
    browser_names = {spec["name"] for spec in BROWSER_TOOL_SPECS}
    atendimento_names = set(ATTENDIMENTO_TOOL_MAP)
    inherited = [spec for spec in BASE_TOOL_SPECS() if spec["name"] not in browser_names and spec["name"] not in atendimento_names]
    return inherited + BROWSER_TOOL_SPECS + UNIVERSAL_MCP_SCHEMAS + atendimento_tool_specs()


base.execute_tool = execute_tool
base.tool_specs = tool_specs
base.audit = audit
base.VERSION = VERSION


class BrowserHandler(base.Handler):
    server_version = "ShopVivalizRemoteControlBrowserMCP/" + VERSION

    def do_GET(self) -> None:
        if self.path == "/health":
            health = browser_service_health()
            health.update({
                "endpoint": "shopvivaliz-remote-control-browser-mcp",
                "version": VERSION,
                "base_endpoint": "shopvivaliz-remote-control-mcp",
                "browser_host": BROWSER_HOST,
                "timestamp": base.now(),
            })
            self._json(200, health)
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
                host = args.get("host") or (BROWSER_HOST if name in BROWSER_TOOLS or name in ATTENDIMENTO_TOOLS or name in ACCOUNT_AUTH_TOOLS else None)
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
