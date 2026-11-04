"""Owner-approved virtual display and browser for the Marina MCP.

Bound to grant GUI_BROWSER_GRANT_PR. Everything runs as the unprivileged service
account, never as root. The display is a dedicated Xvfb instance owned by the
service account (not the operator's session), and the browser uses its own
profile under the service home with no stored credentials. Disabled unless the
service starts with --enable-gui-browser and with --access-config.
"""
from __future__ import annotations

import hashlib
import json
import os
import pwd
import signal
import subprocess
import time
from pathlib import Path

GRANT_PR = 3620  # PR that carries the owner grant
SERVICE_USER = "shopvivaliz-marina-mcp"
SERVICE_HOME = Path("/var/lib/shopvivaliz-marina-mcp")
STATE_DIR = SERVICE_HOME / "state" / "gui"
PROFILE_DIR = SERVICE_HOME / "browser-profile"
RUNTIME_DIR = SERVICE_HOME / "browser-runtime"
BROWSERS_DIR = SERVICE_HOME / "browsers"
RUNNER_SCRIPT = Path(__file__).with_name("browser_navigate.js")
AUDIT_FILE = SERVICE_HOME / "state" / "gui-browser-audit.log"
DISPLAY = ":99"
XVFB = "/usr/bin/Xvfb"
SCREEN = "1280x800x24"
ALLOWED_SCHEMES = ("http://", "https://")
MAX_URL_BYTES = 2048
MAX_SCREENSHOT_BYTES = 2 * 1024 * 1024
DEFAULT_TIMEOUT = 30
MAX_TIMEOUT = 120

GRANT_FILE = Path(__file__).with_name("mcp-admin-grant.json")
# Keep identical to admin_shell.py and security/owner-approvals/3620.json.
EXPECTED_GRANT = {
    "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
    "repository": "Vivaliz-site/site-shopvivaliz",
    "pr_number": 3620,
    "resource": "mcp:marina",
    "principal": "marina-mcp-service",
    "target_repository": "Vivaliz-site/site-shopvivaliz",
    "host": "marina-a1-4c24g",
    "capabilities": [
        "admin_shell",
        "admin_shell_root",
        "gui_session",
        "browser_automation"
    ],
    "operation": "marina_admin_execute",
    "run_as_user": "shopvivaliz-marina-mcp",
    "arbitrary_shell_allowed": True,
    "root_via_sudo_allowed": True,
    "root_or_shell_allowed": True,
    "sudo_allowed": True,
    "root_http_daemon_allowed": False,
    "external_jwt_admin_allowed": False,
    "requires_access_jwt": True,
    "display": ":99",
    "browser_profile": "/var/lib/shopvivaliz-marina-mcp/browser-profile",
    "stored_credentials_allowed": False
}

GUI_BROWSER_TOOLS = [
    {
        "name": "marina_gui_session",
        "description": "Start, status or stop the dedicated virtual display (Xvfb " + DISPLAY + ") of the Marina MCP service account. Owner grant PR " + str(GRANT_PR) + ".",
        "inputSchema": {
            "type": "object",
            "properties": {"action": {"type": "string", "enum": ["start", "status", "stop"]}},
            "required": ["action"],
            "additionalProperties": False,
        },
    },
    {
        "name": "marina_browser_navigate",
        "description": "Open one http(s) URL in the service account's browser on the dedicated display and return the title, final URL and a PNG screenshot. Stored credentials are not used. Owner grant PR " + str(GRANT_PR) + ".",
        "inputSchema": {
            "type": "object",
            "properties": {
                "url": {"type": "string", "minLength": 8, "maxLength": MAX_URL_BYTES},
                "timeout_seconds": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT},
            },
            "required": ["url"],
            "additionalProperties": False,
        },
    },
]


def load_grant(path: Path = GRANT_FILE) -> dict:
    """Fail closed unless the shipped grant matches the pinned owner approval."""
    data = json.loads(path.read_text(encoding="utf-8"))
    if data != EXPECTED_GRANT:
        raise RuntimeError("gui_browser_grant_mismatch")
    return data


def _service_identity_ok() -> bool:
    uid = os.geteuid()
    return uid != 0 and pwd.getpwuid(uid).pw_name == SERVICE_USER


def _audit(entry: dict) -> bool:
    try:
        fd = os.open(AUDIT_FILE, os.O_WRONLY | os.O_APPEND | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "a", encoding="utf-8") as stream:
            stream.write(json.dumps(entry, sort_keys=True) + "\n")
        return True
    except OSError:
        return False


def _digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def _pid_file() -> Path:
    return STATE_DIR / "xvfb.pid"


def _running_xvfb_pid() -> int | None:
    try:
        pid = int(_pid_file().read_text(encoding="utf-8").strip())
        cmdline = Path(f"/proc/{pid}/cmdline").read_bytes().split(b"\0")
    except (OSError, ValueError):
        return None
    # Only signal a process that is still our own Xvfb on the dedicated display.
    return pid if cmdline and cmdline[0].endswith(b"Xvfb") and DISPLAY.encode() in cmdline else None


def gui_session(arguments: dict, *, audit=_audit, spawn=subprocess.Popen) -> dict:
    action = arguments.get("action")
    if set(arguments) != {"action"} or action not in {"start", "status", "stop"}:
        return {"ok": False, "reason": "invalid_arguments"}
    if not _service_identity_ok():
        return {"ok": False, "reason": "service_identity_required"}
    if not audit({"event": "gui_session", "action": action, "at": int(time.time())}):
        return {"ok": False, "reason": "audit_unavailable"}
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    pid = _running_xvfb_pid()
    if action == "status":
        return {"ok": True, "running": pid is not None, "display": DISPLAY}
    if action == "stop":
        if pid is not None:
            os.kill(pid, signal.SIGTERM)
        _pid_file().unlink(missing_ok=True)
        return {"ok": True, "running": False, "display": DISPLAY}
    if pid is None:
        if not Path(XVFB).exists():
            return {"ok": False, "reason": "xvfb_unavailable"}
        proc = spawn([XVFB, DISPLAY, "-screen", "0", SCREEN, "-nolisten", "tcp"],
                     start_new_session=True, stdin=subprocess.DEVNULL,
                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        _pid_file().write_text(str(proc.pid), encoding="utf-8")
        pid = proc.pid
        # Right after fork /proc/<pid>/cmdline still shows the parent, so wait for the
        # exec; otherwise status/stop cannot recognise the display and orphan it.
        for _ in range(50):
            if _running_xvfb_pid() == pid:
                break
            if proc.poll() is not None:
                _pid_file().unlink(missing_ok=True)
                return {"ok": False, "reason": "xvfb_failed"}
            time.sleep(0.1)
        else:
            return {"ok": False, "reason": "xvfb_not_ready", "pid": pid}
    return {"ok": True, "running": True, "display": DISPLAY, "pid": pid}


def _node_binary() -> Path | None:
    for candidate in sorted(Path("/opt").glob("node-v*/bin/node"), reverse=True):
        if os.access(candidate, os.X_OK):
            return candidate
    return None


def _runtime_ready() -> bool:
    # The browser runtime lives under the service home: playwright from npm plus a
    # chromium build in BROWSERS_DIR. Nothing from another account's home is used.
    return (_node_binary() is not None
            and (RUNTIME_DIR / "node_modules" / "playwright").is_dir()
            and any(BROWSERS_DIR.glob("chromium-*")))


def browser_navigate(arguments: dict, *, audit=_audit, runner=subprocess.run) -> dict:
    url = arguments.get("url")
    timeout = arguments.get("timeout_seconds", DEFAULT_TIMEOUT)
    if (set(arguments) - {"url", "timeout_seconds"} or not isinstance(url, str)
            or not url.startswith(ALLOWED_SCHEMES) or len(url.encode("utf-8")) > MAX_URL_BYTES
            or type(timeout) is not int or not 1 <= timeout <= MAX_TIMEOUT):
        return {"ok": False, "reason": "invalid_arguments"}
    if not _service_identity_ok():
        return {"ok": False, "reason": "service_identity_required"}
    if _running_xvfb_pid() is None:
        return {"ok": False, "reason": "gui_session_not_running"}
    if not _runtime_ready():
        return {"ok": False, "reason": "browser_unavailable"}
    digest = _digest(url)
    started = time.time()
    if not audit({"event": "browser_start", "url_sha256": digest, "at": int(started)}):
        return {"ok": False, "reason": "audit_unavailable"}
    PROFILE_DIR.mkdir(parents=True, exist_ok=True)
    env = {"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": str(SERVICE_HOME), "DISPLAY": DISPLAY,
           "NODE_PATH": str(RUNTIME_DIR / "node_modules"), "PLAYWRIGHT_BROWSERS_PATH": str(BROWSERS_DIR)}
    try:
        proc = runner([str(_node_binary()), str(RUNNER_SCRIPT), url, str(timeout * 1000), str(PROFILE_DIR)],
                      cwd=str(RUNTIME_DIR), env=env, capture_output=True, timeout=timeout + 15, check=False)
    except subprocess.TimeoutExpired:
        audit({"event": "browser_end", "url_sha256": digest, "outcome": "timeout"})
        return {"ok": False, "reason": "browser_timeout"}
    if proc.returncode != 0 or len(proc.stdout) > MAX_SCREENSHOT_BYTES * 2:
        audit({"event": "browser_end", "url_sha256": digest, "outcome": "failed",
               "exit_code": proc.returncode})
        return {"ok": False, "reason": "browser_failed", "exit_code": proc.returncode}
    try:
        page = json.loads(proc.stdout.decode("utf-8"))
        result = {"ok": True, "title": str(page["title"]), "final_url": str(page["final_url"])}
        png_b64 = str(page["screenshot_png_base64"])
    except (ValueError, KeyError, UnicodeDecodeError):
        audit({"event": "browser_end", "url_sha256": digest, "outcome": "bad_output"})
        return {"ok": False, "reason": "browser_failed"}
    audit({"event": "browser_end", "url_sha256": digest, "outcome": "ok",
           "duration_seconds": round(time.time() - started, 3)})
    if len(png_b64) > MAX_SCREENSHOT_BYTES:
        result["screenshot_omitted"] = True
    else:
        result["screenshot_png_base64"] = png_b64
    return result


def bootstrap_gui_browser(grant_file: Path = GRANT_FILE):
    load_grant(grant_file)
    if not _service_identity_ok():
        raise RuntimeError("gui_browser_requires_service_identity")
    return {"gui_session": gui_session, "browser_navigate": browser_navigate}
