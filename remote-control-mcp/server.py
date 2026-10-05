#!/usr/bin/env python3
"""ShopVivaliz private remote-control MCP.

Stateless MCP-over-HTTP endpoint bound to backend loopback. It controls four
canonical hosts through local execution or private/reverse SSH and persists
durable tasks/audit state in SQLite. No GitHub API is used at runtime.
"""
from __future__ import annotations

import base64
from contextlib import contextmanager
import hashlib
import hmac
import json
import os
import re
import signal
import socket
import sqlite3
import subprocess
import sys
import threading
import time
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any, Callable
from urllib.parse import urlsplit, urlunsplit

VERSION = "1.1.0"
PROTOCOL_VERSION = "2025-06-18"
LISTEN_HOST = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_HOST", "127.0.0.1")
LISTEN_PORT = int(os.environ.get("SHOPVIVALIZ_REMOTE_MCP_PORT", "5580"))
STATE_DIR = Path(os.environ.get("SHOPVIVALIZ_REMOTE_MCP_STATE", "/var/lib/shopvivaliz-remote-control"))
DB_PATH = STATE_DIR / "state.db"
SSH_KEY = STATE_DIR / "id_ed25519"
KNOWN_HOSTS = STATE_DIR / "known_hosts"
MAX_OUTPUT = int(os.environ.get("SHOPVIVALIZ_REMOTE_MCP_MAX_OUTPUT", str(65536)))
AUTH_TOKEN = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_TOKEN", "")
DEFAULT_TIMEOUT = 30
MAX_TIMEOUT = 900
TASK_WAIT_MAX_SECONDS = 5
MAX_INLINE_COMMANDS = max(1, int(os.environ.get("SHOPVIVALIZ_REMOTE_MCP_MAX_INLINE_COMMANDS", "4")))
INLINE_COMMAND_SLOTS = threading.BoundedSemaphore(MAX_INLINE_COMMANDS)
SYSTEMD_RUN = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_SYSTEMD_RUN", "/usr/bin/systemd-run")
SYSTEMCTL = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_SYSTEMCTL", "/usr/bin/systemctl")
TASK_UNIT_PREFIX = "shopvivaliz-remote-task-"
TASKS_DIR = STATE_DIR / "tasks"
TERMINAL_STATES = {"succeeded", "failed", "expired", "cancelled", "indeterminate"}
ACTIVE_STATES = {"starting", "running", "cancel_requested"}
STARTING_UNIT_VISIBILITY_GRACE_SECONDS = 15
CONTROLLER_REPO = Path(os.environ.get("SHOPVIVALIZ_CONTROLLER_REPO", "/home/ubuntu/shopvivaliz-deploy/repo"))
CONTROLLER_STATE_FILE = Path(os.environ.get(
    "SHOPVIVALIZ_CONTROLLER_STATE_FILE",
    "/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_gemini-24x7-controller-state.json",
))
CONTROLLER_RUNTIME_DIR = Path(os.environ.get(
    "SHOPVIVALIZ_CONTROLLER_RUNTIME_DIR",
    "/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
))
CONTROLLER_WORKTREE_ROOT = Path(os.environ.get("SHOPVIVALIZ_CONTROLLER_WORKTREE_ROOT", "/home/ubuntu/worktrees"))
CONTROLLER_SERVICE = "shopvivaliz-gemini-24x7-controller.service"
CONTROLLER_BACKEND_HOST = "always-free-arm-1787907847-26"
CLAUDE_REMOTE_CONTROL_SERVICE = "shopvivaliz-claude-remote-control.service"
CLAUDE_REMOTE_CONTROL_POINTER_FILE = Path(os.environ.get(
    "SHOPVIVALIZ_CLAUDE_REMOTE_CONTROL_POINTER_FILE",
    "/home/ubuntu/.claude/projects/-home-ubuntu-shopvivaliz-claude-workspace-site-shopvivaliz/bridge-pointer.json",
))
FULL_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
CONVERSATION_ID_RE = re.compile(r"^[A-Za-z0-9_-]{8,160}$")
CONTINUITY_LIB_DIR = Path(os.environ.get("SHOPVIVALIZ_CONTINUITY_LIB_DIR", str(Path(__file__).resolve().parents[1] / "scripts" / "continuity")))
if str(CONTINUITY_LIB_DIR) not in sys.path:
    sys.path.insert(0, str(CONTINUITY_LIB_DIR))
import runtime_lock


HOSTS = {
    "always-free-arm-1787907847-26": {
        "platform": "linux", "transport": "local", "role": "backend/control/browser",
        "service_user_owner": "ubuntu",
    },
    "shopvivaliz-free-a1": {
        "platform": "linux", "transport": "ssh", "address": "10.0.1.112",
        "user": "shopvivaliz-remote", "role": "production web/deploy"
    },
    "Fred-Win": {
        "platform": "windows", "transport": "reverse_ssh", "address": "127.0.0.1",
        "port": 2222, "user": "FRED", "role": "support workstation"
    },
    "KOCEPSV": {
        "platform": "windows", "transport": "reverse_ssh", "address": "127.0.0.1",
        "port": 2223, "user": "user", "role": "support workstation"
    },
}

# Keep enough durable capacity for a control/diagnostic task even when one
# long job is active. Windows relay work stays serialized.
HOST_DURABLE_LIMITS = {
    "always-free-arm-1787907847-26": 2,
    "shopvivaliz-free-a1": 2,
    "Fred-Win": 1,
    "KOCEPSV": 1,
}

SENSITIVE_PATH_PARTS = (
    "/.ssh/", "\\.ssh\\", ".env", "credential", "secret", "token", "cookie",
    "id_rsa", "id_ed25519", ".pem", ".pfx", ".key", "totp", "auth.json",
)
SERVICE_RE = re.compile(r"^[A-Za-z0-9_.@-]{1,120}$")
SECRET_PATTERNS = (
    re.compile(r"(?i)(authorization\s*:\s*bearer\s+)[^\s]+"),
    re.compile(r"(?i)(bearer\s+)[A-Za-z0-9._~+/-]{12,}"),
    re.compile(r"\bsk-[A-Za-z0-9_-]{12,}\b"),
    re.compile(r"-----BEGIN [^-]+ PRIVATE KEY-----.*?-----END [^-]+ PRIVATE KEY-----", re.S),
    # Preserve existing Bearer/key scrubbing before consuming CLI flag values.
    # Process inventories never collect argv; flag scrubbing is defense in depth.
    re.compile(
        r"(?i)((?<!\S)--?(?:setcookie|password|passwd|token|api[-_]?key|"
        r"client[-_]?secret|access[-_]?token|refresh[-_]?token)(?:\s*=\s*|\s+))"
        r"(?:\"(?:\\.|[^\"\\])*\"|'(?:\\.|[^'\\])*'|[^\s]+)"
    ),
)
STOP_EVENT = threading.Event()


class ClientDisconnected(RuntimeError):
    """Raised when an inline MCP caller disappears before its command finishes."""


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def is_authorized(authorization: str, token: str | None = None) -> bool:
    expected = AUTH_TOKEN if token is None else token
    if not expected or not authorization.startswith("Bearer "):
        return False
    supplied = authorization[7:]
    return bool(supplied) and hmac.compare_digest(supplied, expected)


def redact_text(value: str) -> str:
    out = value
    for pattern in SECRET_PATTERNS:
        if pattern.groups:
            out = pattern.sub(lambda m: m.group(1) + "[REDACTED]", out)
        else:
            out = pattern.sub("[REDACTED_PRIVATE_KEY]", out)
    return out[:MAX_OUTPUT]


def validate_host(host: str) -> dict[str, Any]:
    if host not in HOSTS:
        raise ValueError("unsupported_host")
    return HOSTS[host]


BROWSER_MCP_DROPIN_DIR = "/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d"
BROWSER_MCP_FORBIDDEN_DROPIN = BROWSER_MCP_DROPIN_DIR + "/40-authenticated-session.conf"


def validate_admin_command_policy(host: str, command: str) -> None:
    """Reject shell commands that can re-couple general browser MCP to continuity."""
    if host != CONTROLLER_BACKEND_HOST:
        return
    normalized = command.lower()
    if BROWSER_MCP_FORBIDDEN_DROPIN.lower() in normalized:
        raise ValueError("browser_mcp_session_coupling_forbidden")
    if BROWSER_MCP_DROPIN_DIR.lower() in normalized and any(
        marker in normalized for marker in ("fredrdp", "display=:99", "shopvivaliz-atendimento")
    ):
        raise ValueError("browser_mcp_session_coupling_forbidden")


def _decode_output(value: bytes | str | None) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return value


def _run_local(argv: list[str], *, timeout: int = 60) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(argv, capture_output=True, check=False, timeout=timeout)


def _run_local_checked(argv: list[str], *, timeout: int = 60) -> str:
    completed = _run_local(argv, timeout=timeout)
    stdout = _decode_output(completed.stdout)
    stderr = _decode_output(completed.stderr)
    if completed.returncode != 0:
        raise RuntimeError(redact_text(stderr or stdout or "local_command_failed"))
    return stdout.strip()


def _run_as_ubuntu(argv: list[str], *, timeout: int = 60) -> str:
    return _run_local_checked(["runuser", "-u", "ubuntu", "--", *argv], timeout=timeout)


def _validate_expected_sha(value: Any) -> str:
    sha = str(value or "").strip().lower()
    if not FULL_SHA_RE.fullmatch(sha):
        raise ValueError("invalid_expected_sha")
    return sha


def _validate_conversation_id(value: Any) -> str:
    conversation_id = str(value or "").strip()
    if not CONVERSATION_ID_RE.fullmatch(conversation_id):
        raise ValueError("invalid_conversation_id")
    return conversation_id


def _controller_origin_main_sha(*, refresh: bool = False) -> str:
    if refresh:
        _run_as_ubuntu(["git", "-C", str(CONTROLLER_REPO), "fetch", "origin", "main", "--quiet"], timeout=60)
    sha = _run_as_ubuntu(["git", "-C", str(CONTROLLER_REPO), "rev-parse", "origin/main"], timeout=30).lower()
    if not FULL_SHA_RE.fullmatch(sha):
        raise RuntimeError("controller_origin_main_invalid")
    return sha


def _controller_active_sha() -> str:
    try:
        pid_text = _run_local_checked([SYSTEMCTL, "show", CONTROLLER_SERVICE, "-p", "MainPID", "--value"], timeout=15)
        pid = int(pid_text or "0")
    except (RuntimeError, ValueError):
        return ""
    if pid <= 0:
        return ""
    try:
        cmdline = Path(f"/proc/{pid}/cmdline").read_bytes().replace(b"\0", b" ").decode("utf-8", errors="replace")
    except OSError:
        return ""
    match = re.search(r"/releases/([0-9a-f]{40})/", cmdline)
    return match.group(1) if match else ""


RUNTIME_PRIVATE_KEYS = {
    "sessionid", "environmentid", "pid", "procstart", "token", "secret",
    "cookie", "authorization", "password",
}


def _sanitize_runtime_value(value: Any) -> Any:
    if isinstance(value, dict):
        safe: dict[str, Any] = {}
        for key, item in value.items():
            normalized = str(key).replace("_", "").replace("-", "").lower()
            if normalized in RUNTIME_PRIVATE_KEYS:
                continue
            safe[str(key)] = _sanitize_runtime_value(item)
        return safe
    if isinstance(value, list):
        return [_sanitize_runtime_value(item) for item in value]
    if isinstance(value, str):
        return redact_text(value)
    return value


def _read_controller_state() -> dict[str, Any]:
    try:
        payload = json.loads(CONTROLLER_STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        return {}
    if not isinstance(payload, dict):
        return {}
    allowed = {
        "ok", "liveness_ok", "continuity_ready", "degraded", "degraded_reasons",
        "generated_at", "chatgpt_browser", "chatgpt_monitor", "claude_remote_control",
        "watchdog", "dispatcher", "chatgpt_nudge",
    }
    return {key: _sanitize_runtime_value(payload.get(key)) for key in allowed if key in payload}


def _process_start_ticks(pid: int) -> str:
    if pid <= 0:
        return ""
    try:
        fields = Path(f"/proc/{pid}/stat").read_text(encoding="utf-8").split()
    except (OSError, UnicodeError):
        return ""
    return fields[21] if len(fields) > 21 else ""


def claude_remote_control_status() -> dict[str, Any]:
    active_result = _run_local([SYSTEMCTL, "is-active", CLAUDE_REMOTE_CONTROL_SERVICE], timeout=15)
    service_active = active_result.returncode == 0 and _decode_output(active_result.stdout).strip() == "active"
    exec_result = _run_local(
        [SYSTEMCTL, "show", CLAUDE_REMOTE_CONTROL_SERVICE, "-p", "ExecStart", "--value"],
        timeout=15,
    )
    exec_start = _decode_output(exec_result.stdout) if exec_result.returncode == 0 else ""
    session_recovery_enabled = bool(exec_start) and "--no-create-session-in-dir" not in exec_start

    try:
        pointer = json.loads(CLAUDE_REMOTE_CONTROL_POINTER_FILE.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        pointer = {}
    if not isinstance(pointer, dict):
        pointer = {}
    try:
        pid = int(pointer.get("pid") or 0)
    except (TypeError, ValueError):
        pid = 0
    expected_start = str(pointer.get("procStart") or "").strip()
    actual_start = _process_start_ticks(pid)
    source = str(pointer.get("source") or "").strip()
    pointer_present = bool(pointer)
    process_alive = bool(actual_start)
    identity_match = bool(expected_start and actual_start and expected_start == actual_start)
    session_present = bool(str(pointer.get("sessionId") or "").strip())
    environment_present = bool(str(pointer.get("environmentId") or "").strip())
    ok = bool(
        service_active
        and pointer_present
        and process_alive
        and identity_match
        and source == "standalone"
        and session_present
        and environment_present
        and session_recovery_enabled
    )
    return {
        "ok": ok,
        "service": CLAUDE_REMOTE_CONTROL_SERVICE,
        "service_active": service_active,
        "pointer_present": pointer_present,
        "process_alive": process_alive,
        "identity_match": identity_match,
        "source": source,
        "session_present": session_present,
        "environment_present": environment_present,
        "session_recovery_enabled": session_recovery_enabled,
    }


def controller_status() -> dict[str, Any]:
    service_state = _run_local([SYSTEMCTL, "is-active", CONTROLLER_SERVICE], timeout=15)
    active = _decode_output(service_state.stdout).strip() == "active"
    active_sha = _controller_active_sha()
    try:
        origin_main_sha = _controller_origin_main_sha(refresh=False)
    except Exception:
        origin_main_sha = ""
    state = _read_controller_state()
    return {
        "ok": active and bool(active_sha),
        "host": CONTROLLER_BACKEND_HOST,
        "service": CONTROLLER_SERVICE,
        "service_active": active,
        "active_sha": active_sha,
        "origin_main_sha": origin_main_sha,
        "up_to_date": bool(active_sha and origin_main_sha and active_sha == origin_main_sha),
        "state": state,
    }


def _controller_worktree(sha: str) -> Path:
    CONTROLLER_WORKTREE_ROOT.mkdir(parents=True, exist_ok=True)
    path = CONTROLLER_WORKTREE_ROOT / f"controller-promote-{sha[:8]}-{uuid.uuid4().hex[:8]}"
    _run_as_ubuntu(["git", "-C", str(CONTROLLER_REPO), "worktree", "add", "--detach", str(path), sha], timeout=120)
    return path


def _remove_controller_worktree(path: Path) -> None:
    try:
        _run_as_ubuntu(["git", "-C", str(CONTROLLER_REPO), "worktree", "remove", "--force", str(path)], timeout=60)
    except Exception:
        pass


def _controller_promote_sync(expected_sha: str, *, timeout: int = 240) -> dict[str, Any]:
    sha = _validate_expected_sha(expected_sha)
    origin_main = _controller_origin_main_sha(refresh=True)
    if origin_main != sha:
        raise ValueError("expected_sha_not_origin_main")
    previous_generated_at = (_read_controller_state().get("generated_at") or "")
    worktree = _controller_worktree(sha)
    try:
        installer = worktree / "scripts" / "install-gemini-24x7-controller.sh"
        if not installer.is_file():
            raise RuntimeError("controller_installer_missing")
        _run_as_ubuntu(["bash", str(installer), str(worktree), sha], timeout=timeout)
        deadline = time.monotonic() + 60
        status = controller_status()
        while time.monotonic() < deadline:
            state = status.get("state") or {}
            generated_at = str(state.get("generated_at") or "")
            if (
                status.get("active_sha") == sha
                and status.get("service_active") is True
                and generated_at
                and generated_at != previous_generated_at
            ):
                return {"ok": True, "promoted_sha": sha, "status": status}
            time.sleep(1)
            status = controller_status()
        raise RuntimeError("controller_promotion_not_observed")
    finally:
        _remove_controller_worktree(worktree)


def continuity_status() -> dict[str, Any]:
    status = controller_status()
    state = status.get("state") or {}
    ready = (
        bool(status.get("service_active"))
        and state.get("liveness_ok") is True
        and state.get("continuity_ready") is True
    )
    return {
        "ok": ready,
        "controller": status,
        "continuity_ready": state.get("continuity_ready"),
        "degraded": state.get("degraded"),
        "degraded_reasons": state.get("degraded_reasons") or [],
        "chatgpt_browser": state.get("chatgpt_browser") or {},
        "chatgpt_monitor": state.get("chatgpt_monitor") or {},
        "claude_remote_control": state.get("claude_remote_control") or {},
        "generated_at": state.get("generated_at"),
    }


def _parse_trailing_json_report(stdout: str) -> dict[str, Any]:
    """Return the final JSON object even when earlier probe output precedes it."""
    text = str(stdout or "").strip()
    if not text:
        return {}
    decoder = json.JSONDecoder()
    for index in range(len(text) - 1, -1, -1):
        if text[index] != "{":
            continue
        try:
            value, end = decoder.raw_decode(text[index:])
        except json.JSONDecodeError:
            continue
        if text[index + end :].strip():
            continue
        if isinstance(value, dict):
            return value
    return {}


def _continuity_e2e_sync(conversation_id: str, *, timeout_seconds: int = 240) -> dict[str, Any]:
    cid = _validate_conversation_id(conversation_id)
    timeout_seconds = max(30, min(int(timeout_seconds), 600))
    status = controller_status()
    active_sha = str(status.get("active_sha") or "")
    origin_main = _controller_origin_main_sha(refresh=True)
    if not active_sha or active_sha != origin_main:
        raise RuntimeError("controller_not_current")
    worktree = _controller_worktree(active_sha)
    try:
        probe = worktree / "scripts" / "task_continuity_e2e.py"
        if not probe.is_file():
            raise RuntimeError("continuity_e2e_probe_missing")
        completed = _run_local(
            [
                "runuser", "-u", "ubuntu", "--", "python3", str(probe),
                "--runtime-dir", str(CONTROLLER_RUNTIME_DIR),
                "--timeout-seconds", str(timeout_seconds),
                "--poll-interval-seconds", "2",
                "--repository", "Vivaliz-site/site-shopvivaliz",
                "--conversation-id", cid,
            ],
            timeout=timeout_seconds + 45,
        )
        stdout = _decode_output(completed.stdout).strip()
        stderr = redact_text(_decode_output(completed.stderr))
        report = _parse_trailing_json_report(stdout)
        ok = (
            completed.returncode == 0
            and report.get("pass") is True
            and report.get("observed_request") is True
            and report.get("final_verification") == "continuity_e2e_pass"
        )
        return {
            "ok": ok,
            "controller_sha": active_sha,
            "report": report,
            "probe_returncode": completed.returncode,
            "probe_output_parse_error": bool(stdout and not report),
            "probe_stdout_tail": redact_text(stdout[-4096:]) if (not ok and not report) else "",
            "stderr": stderr if not ok else "",
        }
    finally:
        _remove_controller_worktree(worktree)


def _durable_operation_command(operation: str, value: str, timeout: int) -> str:
    if operation not in {"controller-promote", "continuity-e2e", "claude-reconcile"}:
        raise ValueError("unsupported_durable_operation")
    if operation in {"controller-promote", "claude-reconcile"}:
        _validate_expected_sha(value)
    else:
        _validate_conversation_id(value)
    return (
        "/usr/bin/python3 /opt/shopvivaliz-remote-control/server.py "
        f"--{operation}-run {value} {int(timeout)}"
    )


def _claude_remote_control_reconcile_sync(expected_sha: str, *, timeout: int = 240) -> dict[str, Any]:
    sha = _validate_expected_sha(expected_sha)
    origin_main = _controller_origin_main_sha(refresh=True)
    if origin_main != sha:
        raise ValueError("expected_sha_not_origin_main")
    worktree = _controller_worktree(sha)
    try:
        setup = worktree / "scripts" / "setup-claude-remote-control.sh"
        bridge = worktree / "scripts" / "claude-remote-control-mcp-stdio.py"
        unit = worktree / "deploy" / "systemd" / "shopvivaliz-claude-remote-control.service"
        trust = worktree / "scripts" / "claude_workspace_trust_bootstrap.py"
        for required in (setup, bridge, unit, trust):
            if not required.is_file():
                raise RuntimeError("claude_reconcile_source_missing")
        _run_local_checked(
            ["bash", str(setup), "install", str(bridge), str(unit), str(trust)],
            timeout=timeout,
        )
        deadline = time.monotonic() + 60
        status = claude_remote_control_status()
        while time.monotonic() < deadline:
            if status.get("ok") is True:
                return {"ok": True, "reconciled_sha": sha, "status": status}
            time.sleep(1)
            status = claude_remote_control_status()
        raise RuntimeError("claude_reconcile_not_healthy")
    finally:
        _remove_controller_worktree(worktree)


def claude_remote_control_reconcile(expected_sha: str, *, timeout: int = 240) -> dict[str, Any]:
    sha = _validate_expected_sha(expected_sha)
    origin_main = _controller_origin_main_sha(refresh=True)
    if origin_main != sha:
        raise ValueError("expected_sha_not_origin_main")
    current = claude_remote_control_status()
    if current.get("ok") is True:
        return {"ok": True, "already_healthy": True, "reconciled_sha": sha, "status": current}
    command = _durable_operation_command("claude-reconcile", sha, timeout)
    task = execute_tool(
        "task_submit",
        {
            "host": CONTROLLER_BACKEND_HOST,
            "command": command,
            "timeout": min(MAX_TIMEOUT, timeout + 120),
        },
    )
    return {
        **task,
        "ok": True,
        "durable": True,
        "operation": "claude_remote_control_reconcile",
        "expected_sha": sha,
    }


def controller_promote(expected_sha: str, *, timeout: int = 240) -> dict[str, Any]:
    sha = _validate_expected_sha(expected_sha)
    origin_main = _controller_origin_main_sha(refresh=True)
    if origin_main != sha:
        raise ValueError("expected_sha_not_origin_main")
    current = controller_status()
    if current.get("active_sha") == sha and current.get("service_active") is True:
        return {"ok": True, "already_current": True, "promoted_sha": sha, "status": current}
    command = _durable_operation_command("controller-promote", sha, timeout)
    task = execute_tool(
        "task_submit",
        {
            "host": CONTROLLER_BACKEND_HOST,
            "command": command,
            "timeout": min(MAX_TIMEOUT, timeout + 120),
        },
    )
    return {**task, "ok": True, "durable": True, "operation": "controller_promote", "expected_sha": sha}


def continuity_e2e(conversation_id: str, *, timeout_seconds: int = 240) -> dict[str, Any]:
    cid = _validate_conversation_id(conversation_id)
    timeout_seconds = max(30, min(int(timeout_seconds), 600))
    status = controller_status()
    active_sha = str(status.get("active_sha") or "")
    origin_main = _controller_origin_main_sha(refresh=True)
    if not active_sha or active_sha != origin_main:
        raise RuntimeError("controller_not_current")
    command = _durable_operation_command("continuity-e2e", cid, timeout_seconds)
    task = execute_tool(
        "task_submit",
        {
            "host": CONTROLLER_BACKEND_HOST,
            "command": command,
            "timeout": min(MAX_TIMEOUT, timeout_seconds + 120),
        },
    )
    return {
        **task,
        "ok": True,
        "durable": True,
        "operation": "continuity_e2e",
        "controller_sha": active_sha,
    }


def validate_timeout(value: Any) -> int:
    try:
        timeout = int(value if value is not None else DEFAULT_TIMEOUT)
    except (TypeError, ValueError):
        raise ValueError("invalid_timeout")
    if timeout < 1 or timeout > MAX_TIMEOUT:
        raise ValueError("timeout_out_of_range")
    return timeout


def deny_sensitive_path(path: str) -> None:
    normalized = path.replace("\\", "/").lower()
    if any(part.replace("\\", "/").lower() in normalized for part in SENSITIVE_PATH_PARTS):
        raise PermissionError("sensitive_path_denied")


@contextmanager
def db_conn():
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=30000")
    try:
        with conn:
            yield conn
    finally:
        conn.close()


def init_db() -> None:
    with db_conn() as db:
        # Set WAL once during schema initialization. Reissuing journal_mode=WAL on
        # every concurrent connection can itself require a database lock.
        db.execute("PRAGMA journal_mode=WAL")
        db.executescript("""
        CREATE TABLE IF NOT EXISTS audit (
          id TEXT PRIMARY KEY,
          ts TEXT NOT NULL,
          tool TEXT NOT NULL,
          host TEXT,
          args_json TEXT NOT NULL,
          ok INTEGER NOT NULL,
          result_summary TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS tasks (
          id TEXT PRIMARY KEY,
          host TEXT NOT NULL,
          command TEXT NOT NULL,
          command_sha256 TEXT NOT NULL,
          request_id TEXT,
          state TEXT NOT NULL,
          created_at TEXT NOT NULL,
          started_at TEXT,
          finished_at TEXT,
          heartbeat_at TEXT,
          timeout INTEGER NOT NULL,
          exit_code INTEGER,
          stdout TEXT NOT NULL DEFAULT '',
          stderr TEXT NOT NULL DEFAULT ''
        );
        """)
        ensure_column(db, "tasks", "request_id", "TEXT")
        ensure_column(db, "tasks", "execution_unit", "TEXT")
        ensure_column(db, "tasks", "execution_started_at", "TEXT")
        ensure_column(db, "tasks", "runner_started_at", "TEXT")
        ensure_column(db, "tasks", "reconciled_at", "TEXT")
        ensure_column(db, "tasks", "progress", "TEXT NOT NULL DEFAULT ''")
        ensure_column(db, "tasks", "result_dir", "TEXT")
        ensure_column(db, "tasks", "attempt", "INTEGER NOT NULL DEFAULT 0")
        ensure_column(db, "tasks", "recovery_note", "TEXT NOT NULL DEFAULT ''")
        db.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_request_id ON tasks(request_id) WHERE request_id IS NOT NULL")
        db.execute("CREATE INDEX IF NOT EXISTS idx_tasks_state_created ON tasks(state,created_at)")
        db.execute("CREATE UNIQUE INDEX IF NOT EXISTS idx_tasks_execution_unit ON tasks(execution_unit) WHERE execution_unit IS NOT NULL")


def ensure_column(db: sqlite3.Connection, table: str, name: str, ddl: str) -> None:
    cols = {row[1] for row in db.execute(f"PRAGMA table_info({table})")}
    if name not in cols:
        db.execute(f"ALTER TABLE {table} ADD COLUMN {name} {ddl}")


def task_unit_name(task_id: str) -> str:
    compact = task_id.replace("-", "")
    if not re.fullmatch(r"[0-9a-fA-F]{32}", compact):
        raise ValueError("invalid_task_id")
    return f"{TASK_UNIT_PREFIX}{compact.lower()}.service"


def task_result_dir(task_id: str) -> Path:
    return STATE_DIR / "tasks" / task_id


def ensure_task_result_dir(task_id: str) -> Path:
    path = task_result_dir(task_id)
    path.mkdir(parents=True, exist_ok=True)
    os.chmod(path, 0o700)
    return path


def atomic_json(path: Path, payload: dict[str, Any]) -> None:
    tmp = path.with_suffix(".tmp")
    with open(tmp, "w", encoding="utf-8") as fh:
        json.dump(payload, fh, ensure_ascii=False)
        fh.flush()
        os.fsync(fh.fileno())
    os.replace(tmp, path)
    os.chmod(path, 0o600)


def load_task(task_id: str) -> sqlite3.Row:
    with db_conn() as db:
        row = db.execute("SELECT * FROM tasks WHERE id=?", (task_id,)).fetchone()
    if not row:
        raise ValueError("task_not_found")
    return row

def queued_task_context(task_id: str) -> dict[str, Any]:
    with db_conn() as db:
        row = db.execute(
            "SELECT id,host,created_at,state FROM tasks WHERE id=?",
            (task_id,),
        ).fetchone()
        if not row or row["state"] != "queued":
            return {}
        active_count = int(db.execute(
            "SELECT COUNT(*) FROM tasks WHERE host=? "
            "AND state IN ('starting','running','cancel_requested')",
            (row["host"],),
        ).fetchone()[0])
        ahead = db.execute(
            "SELECT COUNT(*) FROM tasks WHERE host=? AND state='queued' "
            "AND (created_at < ? OR (created_at = ? AND id < ?))",
            (row["host"], row["created_at"], row["created_at"], row["id"]),
        ).fetchone()[0]
    limit = HOST_DURABLE_LIMITS.get(str(row["host"]), 1)
    return {
        "queue_position": int(ahead) + 1,
        "blocked_by_active": active_count >= limit,
        "active_for_host": active_count,
        "host_concurrency_limit": limit,
    }



def systemd_unit_state(unit: str) -> str:
    completed = subprocess.run(
        [SYSTEMCTL, "is-active", unit], text=True, capture_output=True, check=False,
    )
    state = (completed.stdout or "").strip().lower()
    return state if state else "inactive"


def systemd_unit_is_active(unit: str) -> bool:
    return systemd_unit_state(unit) in {"active", "activating", "reloading"}


def stop_task_unit(unit: str) -> bool:
    completed = subprocess.run(
        [SYSTEMCTL, "stop", unit], text=True, capture_output=True, check=False,
    )
    return completed.returncode == 0


def read_capped_text(path: Path) -> str:
    try:
        with open(path, "rb") as handle:
            handle.seek(0, os.SEEK_END)
            size = handle.tell()
            handle.seek(max(0, size - MAX_OUTPUT), os.SEEK_SET)
            return redact_text(handle.read(MAX_OUTPUT).decode("utf-8", errors="replace"))
    except OSError:
        return ""


def load_persisted_result(task_id: str) -> dict[str, Any] | None:
    path = task_result_dir(task_id) / "result.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError):
        return None
    if not isinstance(payload, dict) or payload.get("state") not in TERMINAL_STATES:
        return None
    return payload


def import_result_into_db(task_id: str, result: dict[str, Any]) -> None:
    state = str(result["state"])
    if state not in TERMINAL_STATES:
        raise ValueError("invalid_terminal_result")
    finished = str(result.get("finished_at") or now())
    with db_conn() as db:
        existing = db.execute("SELECT state FROM tasks WHERE id=?", (task_id,)).fetchone()
        if not existing:
            raise ValueError("task_not_found")
        if existing["state"] in TERMINAL_STATES and existing["state"] != state:
            return
        db.execute(
            "UPDATE tasks SET state=?,finished_at=?,heartbeat_at=?,exit_code=?,stdout=?,stderr=?,"
            "progress=?,recovery_note=COALESCE(recovery_note,'') WHERE id=?",
            (state, finished, now(), result.get("exit_code"), redact_text(str(result.get("stdout") or "")),
             redact_text(str(result.get("stderr") or "")), "terminal", task_id),
        )


def finalize_task(task_id: str, state: str, exit_code: int | None, result_dir: Path) -> int:
    if state not in TERMINAL_STATES:
        raise ValueError("invalid_terminal_state")
    stdout = read_capped_text(result_dir / "stdout.log")
    stderr = read_capped_text(result_dir / "stderr.log")
    payload = {
        "task_id": task_id, "state": state, "exit_code": exit_code,
        "stdout": stdout, "stderr": stderr, "finished_at": now(),
    }
    atomic_json(result_dir / "result.json", payload)
    import_result_into_db(task_id, payload)
    return 0 if state == "succeeded" else 1


def mark_indeterminate(task_id: str, note: str) -> None:
    with db_conn() as db:
        db.execute(
            "UPDATE tasks SET state='indeterminate',finished_at=?,reconciled_at=?,recovery_note=? "
            "WHERE id=? AND state NOT IN ('succeeded','failed','expired','cancelled','indeterminate')",
            (now(), now(), note, task_id),
        )


def finalize_cancelled_without_result(task_id: str) -> None:
    result_dir = ensure_task_result_dir(task_id)
    finalize_task(task_id, "cancelled", None, result_dir)


def timestamp_age_seconds(value: str | None) -> float | None:
    if not value:
        return None
    try:
        return max(0.0, (datetime.now(timezone.utc) - datetime.fromisoformat(value)).total_seconds())
    except ValueError:
        return None


def reconcile_task(row: sqlite3.Row) -> str:
    task_id = str(row["id"])
    unit = str(row["execution_unit"] or task_unit_name(task_id))
    result = load_persisted_result(task_id)
    if result:
        import_result_into_db(task_id, result)
        return str(result["state"])
    unit_state = systemd_unit_state(unit)
    if unit_state in {"active", "activating", "reloading"}:
        with db_conn() as db:
            db.execute(
                "UPDATE tasks SET state=CASE WHEN state='starting' THEN 'running' ELSE state END,"
                "execution_unit=?,reconciled_at=?,recovery_note='adopted_live_unit' WHERE id=?",
                (unit, now(), task_id),
            )
        return "running"
    if row["state"] == "cancel_requested":
        finalize_cancelled_without_result(task_id)
        return "cancelled"
    if row["state"] == "running" and not row["execution_unit"] and row["started_at"]:
        mark_indeterminate(task_id, "legacy_running_without_execution_unit")
        return "indeterminate"
    if row["execution_started_at"] is None:
        launch_age = timestamp_age_seconds(row["started_at"])
        if (
            row["state"] == "starting"
            and row["execution_unit"]
            and launch_age is not None
            and launch_age < STARTING_UNIT_VISIBILITY_GRACE_SECONDS
        ):
            with db_conn() as db:
                db.execute(
                    "UPDATE tasks SET reconciled_at=?,recovery_note='awaiting_unit_visibility' WHERE id=? AND state='starting'",
                    (now(), task_id),
                )
            return "starting"
        with db_conn() as db:
            db.execute(
                "UPDATE tasks SET state='queued',execution_unit=NULL,reconciled_at=?,"
                "recovery_note='safe_requeue_before_execution' WHERE id=? AND state IN ('starting','running')",
                (now(), task_id),
            )
        return "queued"
    mark_indeterminate(task_id, "execution_started_but_no_live_unit_or_result")
    return "indeterminate"


def reconcile_tasks() -> list[str]:
    with db_conn() as db:
        rows = db.execute(
            "SELECT * FROM tasks WHERE state IN ('starting','running','cancel_requested') ORDER BY created_at"
        ).fetchall()
    return [reconcile_task(row) for row in rows]


def launch_task_service(task_id: str, timeout: int) -> None:
    unit = task_unit_name(task_id)
    args = [
        SYSTEMD_RUN, f"--unit={unit[:-8]}", "--collect", "--quiet", "--service-type=exec",
        "--property=CPUWeight=25", "--property=IOWeight=25", "--property=CPUQuota=80%",
        "--property=Nice=5", "--property=KillMode=control-group",
        "--property=Restart=no", f"--property=RuntimeMaxSec={int(timeout) + 30}s", "--",
        "/usr/bin/python3", "/opt/shopvivaliz-remote-control/server.py", "--run-task", task_id,
    ]
    completed = subprocess.run(args, text=True, capture_output=True, check=False)
    if completed.returncode != 0 and not systemd_unit_is_active(unit):
        raise RuntimeError(redact_text(completed.stderr or "task_service_launch_failed"))


def launch_claimed_task(task_id: str, timeout: int) -> bool:
    row = load_task(task_id)
    if row["state"] == "cancel_requested":
        finalize_cancelled_without_result(task_id)
        return False
    if row["state"] != "starting":
        return False
    launch_task_service(task_id, timeout)
    return True


def update_heartbeat_and_progress(task_id: str, result_dir: Path) -> None:
    progress = f"stdout_bytes={(result_dir / 'stdout.log').stat().st_size if (result_dir / 'stdout.log').exists() else 0}"
    with db_conn() as db:
        db.execute("UPDATE tasks SET heartbeat_at=?,progress=? WHERE id=?", (now(), progress, task_id))


def run_task_entrypoint(task_id: str) -> int:
    row = load_task(task_id)
    if row["state"] == "cancel_requested":
        finalize_cancelled_without_result(task_id)
        return 0
    if row["state"] not in ACTIVE_STATES:
        return 0
    if row["execution_unit"] and row["execution_unit"] != task_unit_name(task_id):
        raise ValueError("execution_unit_mismatch")
    result_dir = ensure_task_result_dir(task_id)
    with db_conn() as db:
        db.execute("UPDATE tasks SET runner_started_at=?,result_dir=?,heartbeat_at=?,progress='runner_started' WHERE id=?", (now(), str(result_dir), now(), task_id))
    stdout_path, stderr_path = result_dir / "stdout.log", result_dir / "stderr.log"
    with open(stdout_path, "ab", buffering=0) as out, open(stderr_path, "ab", buffering=0) as err:
        os.chmod(stdout_path, 0o600)
        os.chmod(stderr_path, 0o600)
        with db_conn() as db:
            # Keep this claim and Popen in one SQLite write transaction.  A
            # concurrent cancellation either wins before this point (and the
            # conditional update changes no row), or waits until the process
            # has physically started and can then terminate that process.
            claimed = db.execute(
                "UPDATE tasks SET state=CASE WHEN state='starting' THEN 'running' ELSE state END,"
                "execution_started_at=COALESCE(execution_started_at,?),heartbeat_at=?,progress='executing' "
                "WHERE id=? AND state IN ('starting','running')",
                (now(), now(), task_id),
            ).rowcount
            if claimed:
                proc = subprocess.Popen(
                    remote_invocation(str(row["host"]), str(row["command"])),
                    stdout=out, stderr=err, start_new_session=True,
                )
        if not claimed:
            finalize_cancelled_without_result(task_id)
            return 0
        deadline = time.monotonic() + int(row["timeout"])
        while proc.poll() is None:
            state = load_task(task_id)["state"]
            if state == "cancel_requested":
                terminate_process_group(proc)
                return finalize_task(task_id, "cancelled", proc.poll(), result_dir)
            if time.monotonic() >= deadline:
                terminate_process_group(proc)
                return finalize_task(task_id, "expired", proc.poll(), result_dir)
            update_heartbeat_and_progress(task_id, result_dir)
            time.sleep(0.2)
        return finalize_task(task_id, "succeeded" if proc.returncode == 0 else "failed", proc.returncode, result_dir)


def sanitize_audit_args(tool: str, args: dict[str, Any]) -> dict[str, Any]:
    safe_args = dict(args)
    if "command" in safe_args:
        command = str(safe_args.pop("command"))
        safe_args["command_sha256"] = hashlib.sha256(command.encode()).hexdigest()
    for secret_key in ("text", "otp", "secret", "code", "password"):
        if secret_key in safe_args:
            secret_value = str(safe_args.pop(secret_key))
            safe_args[f"{secret_key}_sha256"] = hashlib.sha256(secret_value.encode()).hexdigest()
            safe_args[f"{secret_key}_length"] = len(secret_value)
    if "email" in safe_args:
        email_value = str(safe_args.pop("email"))
        safe_args["email_sha256"] = hashlib.sha256(email_value.encode()).hexdigest()
    if tool == "browser_navigate" and "url" in safe_args:
        parsed = urlsplit(str(safe_args["url"]))
        safe_args["url"] = urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    return safe_args


def audit(tool: str, host: str | None, args: dict[str, Any], ok: bool, summary: str) -> str:
    aid = str(uuid.uuid4())
    safe_args = sanitize_audit_args(tool, args)
    with db_conn() as db:
        db.execute(
            "INSERT INTO audit(id,ts,tool,host,args_json,ok,result_summary) VALUES(?,?,?,?,?,?,?)",
            (aid, now(), tool, host, json.dumps(safe_args, ensure_ascii=False), int(ok), redact_text(summary)[:1000]),
        )
    return aid


def ssh_base(address: str, user: str, port: int = 22) -> list[str]:
    if not SSH_KEY.exists() or not KNOWN_HOSTS.exists():
        raise RuntimeError("controller_ssh_identity_not_ready")
    return [
        "ssh", "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
        "-o", "StrictHostKeyChecking=yes", "-o", f"UserKnownHostsFile={KNOWN_HOSTS}",
        "-o", "ConnectTimeout=8", "-p", str(port), "-i", str(SSH_KEY), f"{user}@{address}",
    ]


def remote_invocation(host: str, command: str) -> list[str]:
    cfg = validate_host(host)
    platform = cfg["platform"]
    if cfg["transport"] == "local":
        return ["/usr/bin/env", "HOME=/root", "USER=root", "LOGNAME=root", "bash", "-lc", command]
    address = str(cfg["address"])
    port = int(cfg.get("port", 22))
    base = ssh_base(address, str(cfg["user"]), port)
    if platform == "linux":
        payload = base64.b64encode(command.encode()).decode()
        remote = f"printf %s {payload} | base64 -d | sudo -n bash"
        return base + [remote]
    encoded = base64.b64encode(command.encode("utf-16le")).decode()
    return base + ["powershell.exe", "-NoLogo", "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded]


def isolated_invocation(args: list[str], label: str = "inline") -> list[str]:
    """Run command payload outside the controller cgroup when systemd is available."""
    if os.geteuid() != 0 or not Path(SYSTEMD_RUN).is_file():
        return args
    unit = f"shopvivaliz-remote-command-{label}-{uuid.uuid4().hex[:12]}"
    return [
        SYSTEMD_RUN, "--scope", "--quiet",
        "--unit", unit,
        "--property", "CPUWeight=50",
        "--property", "IOWeight=50",
        "--",
        *args,
    ]


def _cleanup_isolated_scope(invocation: list[str]) -> None:
    """Stop residual descendants left by a bounded systemd scope."""
    if not invocation or invocation[0] != SYSTEMD_RUN or "--scope" not in invocation:
        return
    try:
        idx = invocation.index("--unit")
        unit = invocation[idx + 1]
    except (ValueError, IndexError):
        return
    if not unit.endswith(".scope"):
        unit += ".scope"
    subprocess.run(
        [SYSTEMCTL, "stop", unit],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        check=False,
        timeout=10,
    )


def terminate_process_group(proc: subprocess.Popen[bytes], grace_seconds: float = 1.0) -> None:
    """Terminate an inline command and every local descendant in its process group."""
    if proc.poll() is not None:
        return
    try:
        os.killpg(proc.pid, signal.SIGTERM)
    except ProcessLookupError:
        return
    except OSError:
        try:
            proc.terminate()
        except ProcessLookupError:
            return
    try:
        proc.wait(timeout=max(0.1, grace_seconds))
        return
    except subprocess.TimeoutExpired:
        pass

    try:
        os.killpg(proc.pid, signal.SIGKILL)
    except ProcessLookupError:
        return
    except OSError:
        try:
            proc.kill()
        except ProcessLookupError:
            return
    try:
        proc.wait(timeout=max(0.1, grace_seconds))
    except subprocess.TimeoutExpired:
        pass


def run_host_command(
    host: str,
    command: str,
    timeout: int = DEFAULT_TIMEOUT,
    cancel_check: Callable[[], bool] | None = None,
) -> dict[str, Any]:
    timeout = validate_timeout(timeout)
    args = isolated_invocation(remote_invocation(host, command))
    started = time.monotonic()
    deadline = started + timeout
    if not INLINE_COMMAND_SLOTS.acquire(blocking=False):
        raise RuntimeError("controller_busy_retry_or_use_task_submit")
    proc = None
    try:
        proc = subprocess.Popen(
            args,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            start_new_session=True,
        )
        stdout = b""
        stderr = b""
        while True:
            if cancel_check is not None and cancel_check():
                terminate_process_group(proc)
                try:
                    proc.communicate(timeout=1)
                except subprocess.TimeoutExpired:
                    terminate_process_group(proc, grace_seconds=0.2)
                raise ClientDisconnected("client_disconnected")

            remaining = deadline - time.monotonic()
            if remaining <= 0:
                terminate_process_group(proc)
                try:
                    proc.communicate(timeout=1)
                except subprocess.TimeoutExpired:
                    terminate_process_group(proc, grace_seconds=0.2)
                raise subprocess.TimeoutExpired(args, timeout)

            try:
                stdout, stderr = proc.communicate(timeout=min(0.1, remaining))
                break
            except subprocess.TimeoutExpired:
                continue

        return {
            "host": host,
            "exit_code": proc.returncode,
            "stdout": redact_text(stdout.decode("utf-8", errors="replace")),
            "stderr": redact_text(stderr.decode("utf-8", errors="replace")),
            "duration_ms": int((time.monotonic() - started) * 1000),
        }
    finally:
        _cleanup_isolated_scope(args)
        INLINE_COMMAND_SLOTS.release()


def run_local_command_with_stdin(
    args: list[str],
    stdin_text: str,
    timeout: int = DEFAULT_TIMEOUT,
    cancel_check: Callable[[], bool] | None = None,
) -> dict[str, Any]:
    timeout = validate_timeout(timeout)
    if len(stdin_text) > 4096:
        raise ValueError("browser_text_too_long")
    invocation = isolated_invocation(args, label="browser-input")
    started = time.monotonic()
    deadline = started + timeout
    if not INLINE_COMMAND_SLOTS.acquire(blocking=False):
        raise RuntimeError("controller_busy_retry_or_use_task_submit")
    proc = None
    try:
        proc = subprocess.Popen(
            invocation,
            stdin=subprocess.PIPE,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            start_new_session=True,
        )
        assert proc.stdin is not None
        try:
            proc.stdin.write(stdin_text.encode("utf-8"))
            proc.stdin.flush()
        finally:
            proc.stdin.close()
            proc.stdin = None
        stdout = b""
        stderr = b""
        while True:
            if cancel_check is not None and cancel_check():
                terminate_process_group(proc)
                raise ClientDisconnected("client_disconnected")
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                terminate_process_group(proc)
                raise subprocess.TimeoutExpired(invocation, timeout)
            try:
                stdout, stderr = proc.communicate(timeout=min(0.1, remaining))
                break
            except subprocess.TimeoutExpired:
                continue
        return {
            "host": CONTROLLER_BACKEND_HOST,
            "exit_code": proc.returncode,
            "stdout": redact_text(stdout.decode("utf-8", errors="replace")),
            "stderr": redact_text(stderr.decode("utf-8", errors="replace")),
            "duration_ms": int((time.monotonic() - started) * 1000),
        }
    finally:
        _cleanup_isolated_scope(invocation)
        INLINE_COMMAND_SLOTS.release()


def health_command(platform: str) -> str:
    if platform == "windows":
        return (
            "$p=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent();"
            "$a=$p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator);"
            "[pscustomobject]@{hostname=$env:COMPUTERNAME;user=[Environment]::UserName;"
            "administrator=$a;ps=$PSVersionTable.PSVersion.ToString()}|ConvertTo-Json -Compress"
        )
    return "printf 'hostname='; hostname; printf 'user='; id -un; printf 'uid='; id -u; uptime -p || true"


def service_command(
    platform: str,
    service: str,
    action: str,
    service_user_owner: str | None = None,
) -> str:
    if not SERVICE_RE.fullmatch(service):
        raise ValueError("invalid_service_name")
    if platform == "windows":
        q = service.replace("'", "''")
        if action == "status":
            return f"Get-Service -Name '{q}' | Select-Object Name,Status,StartType | ConvertTo-Json -Compress"
        verb = {"start": "Start-Service", "stop": "Stop-Service", "restart": "Restart-Service"}[action]
        return f"{verb} -Name '{q}' -ErrorAction Stop; Get-Service -Name '{q}' | Select-Object Name,Status,StartType | ConvertTo-Json -Compress"

    user_probe = ""
    if service_user_owner:
        if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_-]{0,31}", service_user_owner):
            raise ValueError("invalid_service_user_owner")
        if action == "status":
            user_operation = (
                f"runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
                f"systemctl --user --no-pager --full status {service}; "
            )
        else:
            user_operation = (
                f"runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
                f"systemctl --user {action} {service}; "
            )
        user_probe = (
            f"uid=$(id -u {service_user_owner} 2>/dev/null) || exit 4; "
            f"load=$(runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
            f"systemctl --user show -p LoadState --value {service} 2>/dev/null || true); "
            f'if [ "$load" = loaded ]; then '
            f"{user_operation}"
            f"runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
            f"systemctl --user is-active {service}; exit $?; fi; "
        )

    system_operation = (
        f"systemctl --no-pager --full status {service}; "
        if action == "status"
        else f"systemctl {action} {service}; "
    )
    return (
        f"load=$(systemctl show -p LoadState --value {service} 2>/dev/null || true); "
        f'if [ "$load" = loaded ]; then '
        f"{system_operation}"
        f"systemctl is-active {service}; exit $?; fi; "
        f"{user_probe}"
        f"printf '%s\n' 'service_not_found' >&2; exit 4"
    )


def file_read_command(platform: str, path: str, max_bytes: int) -> str:
    deny_sensitive_path(path)
    max_bytes = max(1, min(int(max_bytes), 262144))
    if platform == "windows":
        p = path.replace("'", "''")
        return f"$p='{p}'; if(!(Test-Path -LiteralPath $p -PathType Leaf)){{throw 'file_not_found'}}; $b=[IO.File]::ReadAllBytes($p); if($b.Length -gt {max_bytes}){{$b=$b[0..({max_bytes}-1)]}}; [Text.Encoding]::UTF8.GetString($b)"
    encoded = base64.b64encode(path.encode()).decode()
    return f"p=$(printf %s {encoded} | base64 -d); test -f \"$p\"; head -c {max_bytes} -- \"$p\""


def file_list_command(platform: str, path: str) -> str:
    deny_sensitive_path(path)
    if platform == "windows":
        p = path.replace("'", "''")
        return f"Get-ChildItem -LiteralPath '{p}' -Force | Select-Object Name,Length,Mode,LastWriteTime | ConvertTo-Json -Compress"
    encoded = base64.b64encode(path.encode()).decode()
    return f"p=$(printf %s {encoded} | base64 -d); ls -la -- \"$p\""


def logs_tail_command(platform: str, path: str, lines: int) -> str:
    deny_sensitive_path(path)
    lines = max(1, min(int(lines), 1000))
    if platform == "windows":
        p = path.replace("'", "''")
        return f"Get-Content -LiteralPath '{p}' -Tail {lines} -ErrorAction Stop"
    encoded = base64.b64encode(path.encode()).decode()
    return f"p=$(printf %s {encoded} | base64 -d); tail -n {lines} -- \"$p\""


def processes_command(platform: str) -> str:
    if platform == "windows":
        return "Get-Process | Sort-Object CPU -Descending | Select-Object -First 100 Id,ProcessName,CPU,WorkingSet64 | ConvertTo-Json -Compress"
    return "ps -eo pid,user,pcpu,pmem,etime,comm --sort=-pcpu | head -n 101"


BROWSER_ALLOWED_HOSTS = {"chatgpt.com", "auth.openai.com", "openai.com", "accounts.google.com", "login.microsoftonline.com", "claude.ai"}
BROWSER_WORKER_MODULE = "/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs"
BROWSER_NODE_BIN = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_NODE_BIN", "/usr/local/bin/node")


def _safe_browser_token(value: str, label: str) -> str:
    allowed = set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.:#@()=[]-")
    if not value or len(value) > 240 or any(ch not in allowed for ch in value):
        raise ValueError(f"invalid_{label}")
    return value


def browser_tabs_command() -> str:
    return (
        "python3 - <<'PY'\n"
        "import json,urllib.request,urllib.parse\n"
        "with urllib.request.urlopen('http://127.0.0.1:9556/json',timeout=5) as r: a=json.load(r)\n"
        "out=[]\n"
        "allowed={'chatgpt.com','auth.openai.com','openai.com','accounts.google.com','login.microsoftonline.com','claude.ai'}\n"
        "for x in a:\n"
        " if x.get('type')!='page': continue\n"
        " u=urllib.parse.urlparse(x.get('url',''))\n"
        " if u.hostname not in allowed: continue\n"
        " out.append({'id':x.get('id'),'origin':u.scheme+'://'+u.netloc if u.netloc else ''})\n"
        "print(json.dumps({'tabs':out},separators=(',',':')))\n"
        "PY"
    )


def _browser_cdp_command(tab_id: str, expression: str) -> str:
    _safe_browser_token(tab_id, "tab_id")
    tid = base64.b64encode(tab_id.encode()).decode()
    expr = base64.b64encode(expression.encode()).decode()
    return (
        f"export SHOPVIVALIZ_TAB_ID_B64={tid} SHOPVIVALIZ_EXPR_B64={expr}; "
        "node --input-type=module <<'JS'\n"
        f"const mod='{BROWSER_WORKER_MODULE}'; const {{Cdp}}=await import('file://'+mod); "
        "const id=Buffer.from(process.env.SHOPVIVALIZ_TAB_ID_B64,'base64').toString(); "
        "const expression=Buffer.from(process.env.SHOPVIVALIZ_EXPR_B64,'base64').toString(); "
        "const tabs=await (await fetch('http://127.0.0.1:9556/json')).json(); "
        "const t=tabs.find(x=>x.id===id); if(!t) throw new Error('tab_not_found'); "
        "const allowed=new Set(['chatgpt.com','auth.openai.com','openai.com','accounts.google.com','login.microsoftonline.com','claude.ai']); "
        "const u=new URL(String(t.url||'')); if(!allowed.has(u.hostname)) throw new Error('tab_origin_not_allowlisted'); "
        "const ws=new WebSocket(t.webSocketDebuggerUrl); "
        "await Promise.race([new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});}),new Promise((_,reject)=>setTimeout(()=>reject(new Error('websocket_open_timeout')),2500))]); "
        "const c=new Cdp(ws); "
        "const r=await c.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); "
        "if(r.exceptionDetails) throw new Error('browser_runtime_exception'); "
        "if(c.close)c.close(); console.log(JSON.stringify(r.result?.value ?? null));\n"
        "JS"
    )


def browser_controls_expression() -> str:
    return r"""(()=>{const safeText=e=>{const v=(e.innerText||e.getAttribute('placeholder')||'').trim().slice(0,120);return /[A-Z0-9._%+-]+@[A-Z0-9.-]+.[A-Z]{2,}/i.test(v)?'[REDACTED_EMAIL]':v};return {origin:location.origin,path:location.pathname,readyState:document.readyState,controls:[...document.querySelectorAll('input,button,[role=button]')].slice(0,120).map((e,i)=>({i,tag:e.tagName.toLowerCase(),type:e.getAttribute('type')||'',name:e.getAttribute('name')||'',id:e.id||'',role:e.getAttribute('role')||'',aria:e.getAttribute('aria-label')||'',text:safeText(e),disabled:!!e.disabled}))}})()"""


def browser_controls_command(tab_id: str) -> str:
    return _browser_cdp_command(tab_id, browser_controls_expression())


def browser_navigate_command(tab_id: str, url: str) -> str:
    parsed = urlsplit(url)
    if parsed.scheme != "https" or parsed.hostname not in BROWSER_ALLOWED_HOSTS:
        raise ValueError("browser_url_not_allowlisted")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError("browser_url_query_not_allowed")
    expression = f"(()=>{{location.href={json.dumps(url)};return {{navigated:true}}}})()"
    return _browser_cdp_command(tab_id, expression)


def browser_focused_navigate_command(url: str) -> str:
    parsed = urlsplit(url)
    if parsed.scheme != "https" or parsed.hostname not in BROWSER_ALLOWED_HOSTS:
        raise ValueError("browser_url_not_allowlisted")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError("browser_url_query_not_allowed")
    encoded = base64.b64encode(url.encode()).decode()
    allowed = json.dumps(sorted(BROWSER_ALLOWED_HOSTS))
    return (
        f"export SHOPVIVALIZ_NAV_URL_B64={encoded}; "
        "node --input-type=module <<'JS'\n"
        f"const mod='{BROWSER_WORKER_MODULE}'; const {{Cdp}}=await import('file://'+mod); "
        "const url=Buffer.from(process.env.SHOPVIVALIZ_NAV_URL_B64,'base64').toString(); "
        "const tabs=await (await fetch('http://127.0.0.1:9556/json')).json(); "
        f"const allowed=new Set({allowed}); "
        "const focused=[]; "
        "for(const t of tabs){if(t?.type!=='page'||!t?.webSocketDebuggerUrl)continue; let u;try{u=new URL(String(t.url||''));}catch{continue;} if(!allowed.has(u.hostname))continue; "
        "let ws;let c;try{ws=new WebSocket(t.webSocketDebuggerUrl);await Promise.race([new Promise((res,rej)=>{ws.addEventListener('open',res,{once:true});ws.addEventListener('error',rej,{once:true});}),new Promise((_,rej)=>setTimeout(()=>rej(new Error('websocket_open_timeout')),2500))]);c=new Cdp(ws);const r=await c.send('Runtime.evaluate',{expression:'document.hasFocus()',returnByValue:true,awaitPromise:true});if(!r.exceptionDetails&&r.result?.value===true)focused.push(t);}finally{try{if(c?.close)c.close();}catch{} try{if(ws?.close)ws.close();}catch{}}} "
        "if(focused.length===0)throw new Error('focused_tab_not_found'); if(focused.length>1)throw new Error('focused_tab_ambiguous'); "
        "const t=focused[0]; const ws=new WebSocket(t.webSocketDebuggerUrl); await Promise.race([new Promise((res,rej)=>{ws.addEventListener('open',res,{once:true});ws.addEventListener('error',rej,{once:true});}),new Promise((_,rej)=>setTimeout(()=>rej(new Error('websocket_open_timeout')),2500))]); const c=new Cdp(ws); "
        "const expression='(()=>{location.href='+JSON.stringify(url)+';return {navigated:true}})()'; const r=await c.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true}); if(r.exceptionDetails)throw new Error('browser_runtime_exception'); if(c.close)c.close(); console.log(JSON.stringify(r.result?.value ?? null));\n"
        "JS"
    )


def browser_click_command(tab_id: str, selector: str) -> str:
    selector = _safe_browser_token(selector, "selector")
    expression = f"(()=>{{const e=document.querySelector({json.dumps(selector)});if(!e)throw new Error('selector_not_found');e.click();return {{clicked:true}}}})()"
    return _browser_cdp_command(tab_id, expression)


def browser_click_control_command(tab_id: str, index: int) -> str:
    _safe_browser_token(tab_id, "tab_id")
    try:
        idx = int(index)
    except (TypeError, ValueError) as exc:
        raise ValueError("invalid_control_index") from exc
    if idx < 0 or idx >= 120:
        raise ValueError("invalid_control_index")
    expression = (
        "(()=>{const controls=[...document.querySelectorAll('input,button,[role=button]')].slice(0,120);"
        f"const e=controls[{idx}];if(!e)throw new Error('control_index_not_found');"
        "if(typeof e.focus==='function')e.focus({preventScroll:true});"
        f"e.click();if(typeof e.focus==='function')e.focus({{preventScroll:true}});return {{clicked:true,index:{idx}}}}})()"
    )
    return _browser_cdp_command(tab_id, expression)


BROWSER_TYPE_NODE_SCRIPT = r"""
const mod='/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';
const {Cdp}=await import('file://'+mod);
const [id,selector,submitRaw]=process.argv.slice(1);
let secret='';
for await (const chunk of process.stdin) secret += chunk;
if(secret.length>4096) throw new Error('browser_text_too_long');
const tabs=await (await fetch('http://127.0.0.1:9556/json')).json();
const t=tabs.find(x=>x.id===id); if(!t) throw new Error('tab_not_found');
const allowed=new Set(['chatgpt.com','auth.openai.com','openai.com','accounts.google.com','login.microsoftonline.com','claude.ai']);
const u=new URL(String(t.url||'')); if(!allowed.has(u.hostname)) throw new Error('tab_origin_not_allowlisted');
const ws=new WebSocket(t.webSocketDebuggerUrl);
await Promise.race([
  new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});}),
  new Promise((_,reject)=>setTimeout(()=>reject(new Error('websocket_open_timeout')),2500))
]);
const c=new Cdp(ws);
const expression="(()=>{const e=document.querySelector("+JSON.stringify(selector)+");if(!e)throw new Error('selector_not_found');e.focus();const v="+JSON.stringify(secret)+";const p=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(e),'value');if(p&&p.set)p.set.call(e,v);else e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));const submitted="+String(submitRaw==='1')+";if(submitted)e.form?.requestSubmit?.();return {typed:true,submitted}})()";
const r=await c.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
if(r.exceptionDetails) throw new Error('browser_runtime_exception');
if(c.close)c.close();
console.log(JSON.stringify(r.result?.value ?? null));
"""


def browser_type_invocation(tab_id: str, selector: str, submit: bool) -> list[str]:
    _safe_browser_token(tab_id, "tab_id")
    _safe_browser_token(selector, "selector")
    return [BROWSER_NODE_BIN, "--input-type=module", "-e", BROWSER_TYPE_NODE_SCRIPT, tab_id, selector, "1" if submit else "0"]


BROWSER_FOCUSED_TYPE_NODE_SCRIPT = r"""
const mod='/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';
const {Cdp}=await import('file://'+mod);
const [submitRaw]=process.argv.slice(1);
let secret='';
for await (const chunk of process.stdin) secret += chunk;
if(secret.length>4096) throw new Error('browser_text_too_long');
const tabs=await (await fetch('http://127.0.0.1:9556/json')).json();
const allowed=new Set(['chatgpt.com','auth.openai.com','openai.com','accounts.google.com','login.microsoftonline.com','claude.ai']);
const candidates=[];
for(const t of tabs){
  if(t?.type!=='page'||!t?.webSocketDebuggerUrl) continue;
  let u; try{u=new URL(String(t.url||''));}catch{continue;}
  if(!allowed.has(u.hostname)) continue;
  let ws; let c;
  try{
    ws=new WebSocket(t.webSocketDebuggerUrl);
    await Promise.race([
      new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});}),
      new Promise((_,reject)=>setTimeout(()=>reject(new Error('websocket_open_timeout')),2500))
    ]);
    c=new Cdp(ws);
    const probe=await c.send('Runtime.evaluate',{
      expression:"(()=>{const e=document.activeElement;const editable=!!e&&((e.matches?.('input,textarea'))||e.isContentEditable);return {focused:document.hasFocus(),editable}})()",
      returnByValue:true,
      awaitPromise:true
    });
    if(!probe.exceptionDetails&&probe.result?.value?.editable){
      candidates.push({t,focused:probe.result.value.focused===true});
    }
  } finally {
    try{if(c?.close)c.close();}catch{}
    try{if(ws?.close)ws.close();}catch{}
  }
}
let chosen=null;
const focused=candidates.filter(x=>x.focused);
if(focused.length===1) chosen=focused[0];
else if(candidates.length===1) chosen=candidates[0];
else if(candidates.length===0) throw new Error('focused_editable_not_found');
else throw new Error('focused_editable_ambiguous');
const ws=new WebSocket(chosen.t.webSocketDebuggerUrl);
await Promise.race([
  new Promise((resolve,reject)=>{ws.addEventListener('open',resolve,{once:true});ws.addEventListener('error',reject,{once:true});}),
  new Promise((_,reject)=>setTimeout(()=>reject(new Error('websocket_open_timeout')),2500))
]);
const c=new Cdp(ws);
const expression="(()=>{const e=document.activeElement;if(!e)throw new Error('focused_editable_not_found');const editable=(e.matches?.('input,textarea'))||e.isContentEditable;if(!editable)throw new Error('focused_editable_not_found');const v="+JSON.stringify(secret)+";if(e.isContentEditable){e.textContent=v;}else{const p=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(e),'value');if(p&&p.set)p.set.call(e,v);else e.value=v;}e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));const submitted="+String(submitRaw==='1')+";if(submitted)e.form?.requestSubmit?.();return {typed:true,submitted,mode:'focused'}})()";
const r=await c.send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});
if(r.exceptionDetails) throw new Error('browser_runtime_exception');
if(c.close)c.close();
console.log(JSON.stringify(r.result?.value ?? null));
"""


def browser_focused_type_invocation(press_enter: bool) -> list[str]:
    return [
        BROWSER_NODE_BIN,
        "--input-type=module",
        "-e",
        BROWSER_FOCUSED_TYPE_NODE_SCRIPT,
        "1" if press_enter else "0",
    ]


def _browser_result(result: dict[str, Any]) -> dict[str, Any]:
    result["ok"] = result["exit_code"] == 0
    return result


MUTATING_RUNTIME_ACTIONS = {
    "controller_promote": "controller_promote",
    "browser_navigate": "browser_navigate",
    "browser_click": "browser_click",
    "browser_click_control": "browser_click_control",
    "browser_type": "browser_type",
    "service_action": "service_action",
}

def _durable_handoff_enabled() -> bool:
    return os.environ.get("SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF", "0").strip().lower() in {"1", "true", "yes", "on"}

def _assert_runtime_mutation(name: str, args: dict[str, Any]) -> None:
    action = MUTATING_RUNTIME_ACTIONS.get(name)
    if not action or not _durable_handoff_enabled():
        return
    lease_id = str(args.get("runtime_lease_id") or "").strip()
    token = args.get("runtime_fencing_token")
    if not lease_id or token is None:
        raise ValueError("runtime_lock_required")
    try:
        runtime_lock.assert_runtime_lock(lease_id, int(token), action)
    except (runtime_lock.RuntimeLockConflict, TypeError, ValueError) as exc:
        raise ValueError("runtime_lock_invalid") from exc

def execute_tool(
    name: str,
    args: dict[str, Any],
    cancel_check: Callable[[], bool] | None = None,
) -> dict[str, Any]:
    host = args.get("host")
    _assert_runtime_mutation(name, args)
    if name == "claude_remote_control_status":
        return claude_remote_control_status()
    if name == "claude_remote_control_reconcile":
        return claude_remote_control_reconcile(
            _validate_expected_sha(args.get("expected_sha")),
            timeout=validate_timeout(args.get("timeout", 240)),
        )
    if name == "controller_status":
        return controller_status()
    if name == "controller_promote":
        return controller_promote(
            _validate_expected_sha(args.get("expected_sha")),
            timeout=validate_timeout(args.get("timeout", 240)),
        )
    if name == "continuity_status":
        return continuity_status()
    if name == "continuity_e2e":
        return continuity_e2e(
            _validate_conversation_id(args.get("conversation_id")),
            timeout_seconds=int(args.get("timeout_seconds", 240)),
        )
    if name == "browser_tabs":
        return _browser_result(run_host_command(CONTROLLER_BACKEND_HOST, browser_tabs_command(), DEFAULT_TIMEOUT, cancel_check))
    if name == "browser_controls":
        return _browser_result(run_host_command(
            CONTROLLER_BACKEND_HOST,
            browser_controls_command(str(args.get("tab_id") or "")),
            DEFAULT_TIMEOUT,
            cancel_check,
        ))
    if name == "browser_navigate":
        tab_id = str(args.get("tab_id") or "")
        url = str(args.get("url") or "")
        command = browser_navigate_command(tab_id, url) if tab_id else browser_focused_navigate_command(url)
        return _browser_result(run_host_command(
            CONTROLLER_BACKEND_HOST,
            command,
            DEFAULT_TIMEOUT,
            cancel_check,
        ))
    if name == "browser_click":
        return _browser_result(run_host_command(
            CONTROLLER_BACKEND_HOST,
            browser_click_command(str(args.get("tab_id") or ""), str(args.get("selector") or "")),
            DEFAULT_TIMEOUT,
            cancel_check,
        ))
    if name == "browser_click_control":
        return _browser_result(run_host_command(
            CONTROLLER_BACKEND_HOST,
            browser_click_control_command(str(args.get("tab_id") or ""), args.get("index")),
            DEFAULT_TIMEOUT,
            cancel_check,
        ))
    if name == "browser_type":
        text_value = str(args.get("text") or "")
        if not text_value:
            raise ValueError("browser_text_required")
        if len(text_value) > 4096:
            raise ValueError("browser_text_too_long")
        tab_id = str(args.get("tab_id") or "")
        selector = str(args.get("selector") or "")
        if tab_id or selector:
            invocation = browser_type_invocation(
                tab_id,
                selector,
                bool(args.get("submit", False)),
            )
        else:
            invocation = browser_focused_type_invocation(bool(args.get("press_enter", False)))
        return _browser_result(run_local_command_with_stdin(
            invocation,
            text_value,
            DEFAULT_TIMEOUT,
            cancel_check,
        ))
    if name == "hosts_list":
        return {"hosts": [{"name": n, **cfg} for n, cfg in HOSTS.items()]}
    if name == "audit_recent":
        limit = max(1, min(int(args.get("limit", 25)), 200))
        with db_conn() as db:
            rows = db.execute(
                "SELECT id,ts,tool,host,args_json,ok,result_summary FROM audit ORDER BY ts DESC LIMIT ?",
                (limit,),
            ).fetchall()
        return {"events": [dict(r) for r in rows]}
    if name == "task_status":
        tid = str(args.get("task_id") or "")
        row = load_task(tid)
        if row["state"] in ACTIVE_STATES:
            reconcile_task(row)
            row = load_task(tid)
        result = dict(row)
        if result["state"] == "queued":
            result.update(queued_task_context(tid))
        heartbeat = result.get("heartbeat_at")
        if heartbeat:
            try:
                result["heartbeat_age_seconds"] = max(0.0, (datetime.now(timezone.utc) - datetime.fromisoformat(heartbeat)).total_seconds())
            except ValueError:
                result["heartbeat_age_seconds"] = None
        else:
            result["heartbeat_age_seconds"] = None
        result_dir = result.get("result_dir")
        if result_dir and result["state"] in ACTIVE_STATES:
            path = Path(result_dir)
            result["stdout"] = read_capped_text(path / "stdout.log") or result.get("stdout", "")
            result["stderr"] = read_capped_text(path / "stderr.log") or result.get("stderr", "")
        return result
    if name == "task_wait":
        tid = str(args.get("task_id") or "")
        wait_seconds = max(0, min(int(args.get("wait_seconds", 20)), TASK_WAIT_MAX_SECONDS))
        deadline = time.monotonic() + wait_seconds
        while True:
            result = execute_tool("task_status", {"task_id": tid})
            if result["state"] in TERMINAL_STATES or time.monotonic() >= deadline:
                return result
            if result["state"] == "queued" and (
                result.get("blocked_by_active") or int(result.get("queue_position", 1)) > 1
            ):
                result["detached"] = True
                return result
            if cancel_check and cancel_check():
                return {"id": tid, "state": result["state"], "detached": True}
            time.sleep(0.25)
    if name == "task_cancel":
        tid = str(args.get("task_id") or "")
        with db_conn() as db:
            row = db.execute("SELECT state,execution_unit FROM tasks WHERE id=?", (tid,)).fetchone()
            if not row:
                raise ValueError("task_not_found")
            if row["state"] in TERMINAL_STATES:
                return {"task_id": tid, "state": row["state"]}
            if row["state"] == "queued":
                db.execute("UPDATE tasks SET state='cancelled',finished_at=?,heartbeat_at=?,progress='cancelled_before_start' WHERE id=?", (now(), now(), tid))
                return {"task_id": tid, "state": "cancelled"}
            db.execute("UPDATE tasks SET state='cancel_requested',heartbeat_at=?,progress='cancellation_requested' WHERE id=?", (now(), tid))
        unit = str(row["execution_unit"] or task_unit_name(tid))
        stop_task_unit(unit)
        return {"task_id": tid, "state": "cancel_requested"}
    if name == "task_submit":
        cfg = validate_host(str(host))
        command = str(args.get("command") or "")
        if not command.strip():
            raise ValueError("command_required")
        validate_admin_command_policy(str(host), command)
        timeout = validate_timeout(args.get("timeout", 300))
        digest = hashlib.sha256(command.encode()).hexdigest()
        request_id = str(args.get("request_id") or "").strip() or None
        if request_id and len(request_id) > 200:
            raise ValueError("request_id_too_long")
        with db_conn() as db:
            if request_id:
                row = db.execute("SELECT id,host,command_sha256,timeout,state FROM tasks WHERE request_id=?", (request_id,)).fetchone()
                if row:
                    if row["host"] != host or row["command_sha256"] != digest or int(row["timeout"]) != timeout:
                        raise ValueError("request_id_conflict")
                    return {"task_id": row["id"], "host": row["host"], "state": row["state"], "platform": cfg["platform"], "deduplicated": True}
            existing = db.execute("SELECT id,state FROM tasks WHERE host=? AND command_sha256=? AND timeout=? AND state IN ('queued','starting','running','cancel_requested') ORDER BY created_at DESC LIMIT 1", (host, digest, timeout)).fetchone()
            if existing:
                return {"task_id": existing["id"], "host": host, "state": existing["state"], "platform": cfg["platform"], "deduplicated": True}
            tid = str(uuid.uuid4())
            db.execute(
                "INSERT INTO tasks(id,host,command,command_sha256,request_id,state,created_at,timeout) VALUES(?,?,?,?,?,?,?,?)",
                (tid, host, command, digest, request_id, "queued", now(), timeout),
            )
        return {"task_id": tid, "host": host, "state": "queued", "platform": cfg["platform"], "deduplicated": False}

    cfg = validate_host(str(host))
    platform = str(cfg["platform"])
    timeout = validate_timeout(args.get("timeout"))
    if name == "host_health":
        result = run_host_command(str(host), health_command(platform), timeout, cancel_check)
    elif name == "processes_list":
        result = run_host_command(str(host), processes_command(platform), timeout, cancel_check)
    elif name == "service_status":
        result = run_host_command(
            str(host),
            service_command(
                platform,
                str(args.get("service") or ""),
                "status",
                str(cfg.get("service_user_owner") or "") or None,
            ),
            timeout,
            cancel_check,
        )
    elif name == "service_action":
        action = str(args.get("action") or "")
        if action not in {"start", "stop", "restart"}:
            raise ValueError("invalid_service_action")
        result = run_host_command(
            str(host),
            service_command(
                platform,
                str(args.get("service") or ""),
                action,
                str(cfg.get("service_user_owner") or "") or None,
            ),
            timeout,
            cancel_check,
        )
    elif name == "file_read":
        result = run_host_command(str(host), file_read_command(platform, str(args.get("path") or ""), int(args.get("max_bytes", 65536))), timeout, cancel_check)
    elif name == "file_list":
        result = run_host_command(str(host), file_list_command(platform, str(args.get("path") or "")), timeout, cancel_check)
    elif name == "logs_tail":
        result = run_host_command(str(host), logs_tail_command(platform, str(args.get("path") or ""), int(args.get("lines", 100))), timeout, cancel_check)
    elif name == "admin_command_run":
        command = str(args.get("command") or "")
        if not command.strip():
            raise ValueError("command_required")
        validate_admin_command_policy(str(host), command)
        if bool(args.get("durable", False)):
            durable = execute_tool("task_submit", {"host": host, "command": command, "timeout": timeout, "request_id": args.get("request_id")}, None)
            durable["durable"] = True
            return durable
        result = run_host_command(str(host), command, timeout, cancel_check)
    else:
        raise ValueError("unknown_tool")
    result["ok"] = result["exit_code"] == 0
    return result


TOOLS = [
    ("claude_remote_control_status", "Inspect sanitized Claude Remote Control service, session pointer identity and session-recovery health on the canonical backend.", {}, True, False),
    ("claude_remote_control_reconcile", "Reconcile Claude Remote Control from exactly the expected origin/main SHA using the canonical installer and durable execution.", {"expected_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"}, "timeout": {"type": "integer", "minimum": 30, "maximum": MAX_TIMEOUT}}, False, True),
    ("controller_status", "Inspect the active 24x7 continuity controller release and sanitized runtime state on the canonical backend.", {}, True, False),
    ("controller_promote", "Promote exactly the expected origin/main SHA to the canonical 24x7 controller using a clean detached worktree and canonical installer.", {"expected_sha": {"type": "string", "pattern": "^[0-9a-f]{40}$"}, "timeout": {"type": "integer", "minimum": 30, "maximum": MAX_TIMEOUT}}, False, True),
    ("continuity_status", "Read aggregated sanitized ChatGPT, Claude Remote Control and dispatcher continuity health from the canonical backend.", {}, True, False),
    ("continuity_e2e", "Run the canonical detached continuity E2E probe for an explicitly bound ChatGPT conversation.", {"conversation_id": {"type": "string", "pattern": "^[A-Za-z0-9_-]{8,160}$"}, "timeout_seconds": {"type": "integer", "minimum": 30, "maximum": 600}}, False, True),
    ("browser_tabs", "List allowlisted tabs in the canonical backend Chrome session without exposing titles or full URLs.", {}, True, False),
    ("browser_controls", "Inspect sanitized controls on an allowlisted canonical backend browser tab; input values are never returned.", {"tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"}}, True, False),
    ("browser_navigate", "Navigate an allowlisted canonical backend browser tab to an allowlisted HTTPS URL without query or fragment.", {"tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"}, "url": {"type": "string", "maxLength": 2048}}, False, True),
    ("browser_click", "Click an explicit constrained CSS selector in an allowlisted canonical backend browser tab.", {"tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"}, "selector": {"type": "string", "maxLength": 240}}, False, True),
    ("browser_click_control", "Click exactly one sanitized control by its browser_controls index in an allowlisted canonical backend tab.", {"tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"}, "index": {"type": "integer", "minimum": 0, "maximum": 119}}, False, True),
    ("browser_type", "Type into an explicit constrained CSS selector in the canonical backend browser. Text is sent only over stdin and hashed in audit records.", {"tab_id": {"type": "string", "pattern": "^[A-Za-z0-9_.:-]{1,240}$"}, "selector": {"type": "string", "maxLength": 240}, "text": {"type": "string", "maxLength": 4096}, "submit": {"type": "boolean"}}, False, True),
    ("hosts_list", "List the four canonical ShopVivaliz hosts and transport roles.", {}, True, False),
    ("host_health", "Check live identity, privilege and reachability for a named host.", {"host": {"type": "string", "enum": list(HOSTS)}}, True, False),
    ("processes_list", "List top processes on a named host.", {"host": {"type": "string", "enum": list(HOSTS)}}, True, False),
    ("service_status", "Inspect a service on a named host.", {"host": {"type": "string", "enum": list(HOSTS)}, "service": {"type": "string"}}, True, False),
    ("service_action", "Start, stop or restart a service with administrative privilege.", {"host": {"type": "string", "enum": list(HOSTS)}, "service": {"type": "string"}, "action": {"type": "string", "enum": ["start", "stop", "restart"]}}, False, True),
    ("file_read", "Read a non-sensitive file from a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}, "max_bytes": {"type": "integer", "minimum": 1, "maximum": 262144}}, True, False),
    ("file_list", "List a non-sensitive directory on a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}}, True, False),
    ("logs_tail", "Tail a non-sensitive log file on a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}, "lines": {"type": "integer", "minimum": 1, "maximum": 1000}}, True, False),
    ("admin_command_run", "Run a bounded administrative shell or PowerShell command on a named host. Use durable=true for work that must survive client disconnects.", {"host": {"type": "string", "enum": list(HOSTS)}, "command": {"type": "string"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}, "durable": {"type": "boolean"}, "request_id": {"type": "string", "maxLength": 200}}, False, True),
    ("task_submit", "Queue a durable administrative command that continues independently of the chat.", {"host": {"type": "string", "enum": list(HOSTS)}, "command": {"type": "string"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}, "request_id": {"type": "string", "maxLength": 200}}, False, True),
    ("task_wait", "Wait briefly for a durable task while preserving it across client disconnects.", {"task_id": {"type": "string"}, "wait_seconds": {"type": "integer", "minimum": 0, "maximum": 25}}, True, False),
    ("task_status", "Read persisted status/output for a durable task.", {"task_id": {"type": "string"}}, True, False),
    ("task_cancel", "Cancel a queued or running durable task.", {"task_id": {"type": "string"}}, False, True),
    ("audit_recent", "Read recent redacted control-plane audit events.", {"limit": {"type": "integer", "minimum": 1, "maximum": 200}}, True, False),
]


def tool_specs() -> list[dict[str, Any]]:
    specs = []
    for name, desc, props, readonly, destructive in TOOLS:
        optional = {"timeout", "max_bytes", "lines", "limit", "request_id", "wait_seconds", "durable", "timeout_seconds", "submit"}
        if name == "browser_navigate":
            optional.add("tab_id")
        schema_props = dict(props)
        if name in MUTATING_RUNTIME_ACTIONS:
            schema_props.update({
                "runtime_lease_id": {"type": "string", "maxLength": 200},
                "runtime_fencing_token": {"type": "integer", "minimum": 1},
            })
        specs.append({
            "name": name,
            "description": desc,
            "inputSchema": {
                "type": "object", "properties": schema_props,
                "required": [k for k in props if k not in optional],
                "additionalProperties": False,
            },
            "annotations": {
                "readOnlyHint": readonly,
                "openWorldHint": False,
                "destructiveHint": destructive,
            },
        })
    return specs


def task_worker() -> None:
    while not STOP_EVENT.wait(0.2):
        tid = None
        try:
            reconcile_tasks()
            with db_conn() as db:
                active_rows = db.execute(
                    "SELECT host,COUNT(*) AS count FROM tasks "
                    "WHERE state IN ('starting','running','cancel_requested') GROUP BY host"
                ).fetchall()
                active_counts = {str(item["host"]): int(item["count"]) for item in active_rows}
                candidates = db.execute(
                    "SELECT id,host,timeout FROM tasks WHERE state='queued' ORDER BY created_at"
                ).fetchall()
                row = next(
                    (
                        item for item in candidates
                        if active_counts.get(str(item["host"]), 0)
                        < HOST_DURABLE_LIMITS.get(str(item["host"]), 1)
                    ),
                    None,
                )
                if not row:
                    continue
                result_dir = str(ensure_task_result_dir(str(row["id"])))
                unit = task_unit_name(str(row["id"]))
                changed = db.execute(
                    "UPDATE tasks SET state='starting',execution_unit=?,started_at=COALESCE(started_at,?),"
                    "heartbeat_at=?,progress='launching',attempt=attempt+1,result_dir=? WHERE id=? AND state='queued'",
                    (unit, now(), now(), result_dir, row["id"]),
                ).rowcount
            if not changed:
                continue
            tid = str(row["id"])
            if launch_claimed_task(tid, int(row["timeout"])):
                audit("task_worker", str(row["host"]), {"task_id": tid}, True, "durable_task_service_launched")
        except Exception as exc:
            try:
                if tid:
                    with db_conn() as db:
                        db.execute(
                            "UPDATE tasks SET recovery_note=?,reconciled_at=? WHERE id=? AND state='starting'",
                            (redact_text(str(exc)), now(), tid),
                        )
            except Exception:
                pass


def durable_health_summary() -> dict[str, Any]:
    with db_conn() as db:
        rows = db.execute(
            "SELECT state,COUNT(*) AS count FROM tasks WHERE state IN ('queued','starting','running','cancel_requested','indeterminate') GROUP BY state"
        ).fetchall()
        heartbeat = db.execute(
            "SELECT heartbeat_at FROM tasks WHERE state IN ('starting','running','cancel_requested') AND heartbeat_at IS NOT NULL ORDER BY heartbeat_at LIMIT 1"
        ).fetchone()
    summary = {state: 0 for state in ("queued", "starting", "running", "cancel_requested", "indeterminate")}
    summary.update({str(row["state"]): int(row["count"]) for row in rows})
    age: float | None = None
    if heartbeat:
        try:
            age = max(0.0, (datetime.now(timezone.utc) - datetime.fromisoformat(heartbeat["heartbeat_at"])).total_seconds())
        except ValueError:
            age = None
    summary["oldest_heartbeat_age_seconds"] = age
    summary["degraded"] = age is not None and age > 10
    return summary


class Handler(BaseHTTPRequestHandler):
    server_version = "ShopVivalizRemoteControlMCP/" + VERSION

    def log_message(self, fmt: str, *args: Any) -> None:
        return

    def _client_disconnected(self) -> bool:
        try:
            data = self.connection.recv(1, socket.MSG_PEEK | socket.MSG_DONTWAIT)
            return data == b""
        except BlockingIOError:
            return False
        except (ConnectionResetError, OSError):
            return True

    def _json(self, status: int, payload: Any) -> bool:
        raw = json.dumps(payload, ensure_ascii=False).encode()
        try:
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(raw)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(raw)
            return True
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True
            return False

    def do_GET(self) -> None:
        if self.path == "/health":
            self._json(200, {
                "ok": True, "endpoint": "shopvivaliz-remote-control-mcp",
                "version": VERSION, "hosts": list(HOSTS), "timestamp": now(),
                "durable": durable_health_summary(),
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
        if not is_authorized(self.headers.get("Authorization", "")):
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
                    "protocolVersion": PROTOCOL_VERSION,
                    "capabilities": {"tools": {"listChanged": False}},
                    "serverInfo": {"name": "shopvivaliz-remote-control", "version": VERSION},
                }
            elif method == "tools/list":
                result = {"tools": tool_specs()}
            elif method == "tools/call":
                params = req.get("params") or {}
                name = str(params.get("name") or "")
                args = params.get("arguments") or {}
                host = args.get("host")
                try:
                    output = execute_tool(name, args, cancel_check=self._client_disconnected)
                    ok = not (isinstance(output, dict) and output.get("ok") is False)
                    aid = audit(name, host, args, ok, "ok" if ok else "command_failed")
                    if isinstance(output, dict):
                        output["audit_id"] = aid
                    result = {
                        "content": [{"type": "text", "text": json.dumps(output, ensure_ascii=False)}],
                        "structuredContent": output,
                        "isError": not ok,
                    }
                except ClientDisconnected:
                    audit(name, host, args, False, "client_disconnected_command_cancelled")
                    raise
                except Exception as exc:
                    aid = audit(name, host, args, False, str(exc))
                    output = {"error": str(exc), "audit_id": aid}
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
        except ClientDisconnected:
            self.close_connection = True
            return
        except Exception as exc:
            self._json(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": redact_text(str(exc))}})


def main() -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    init_db()
    reconcile_tasks()
    worker = threading.Thread(target=task_worker, name="task-worker", daemon=True)
    worker.start()
    server = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler)
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        STOP_EVENT.set()
        server.server_close()


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "--run-task":
        raise SystemExit(run_task_entrypoint(sys.argv[2]))
    if len(sys.argv) == 4 and sys.argv[1] == "--controller-promote-run":
        try:
            result = _controller_promote_sync(_validate_expected_sha(sys.argv[2]), timeout=int(sys.argv[3]))
            print(json.dumps(result, ensure_ascii=False))
            raise SystemExit(0 if result.get("ok") else 1)
        except Exception as exc:
            print(json.dumps({"ok": False, "error": redact_text(str(exc))}, ensure_ascii=False))
            raise SystemExit(1)
    if len(sys.argv) == 4 and sys.argv[1] == "--claude-reconcile-run":
        try:
            result = _claude_remote_control_reconcile_sync(_validate_expected_sha(sys.argv[2]), timeout=int(sys.argv[3]))
            print(json.dumps(result, ensure_ascii=False))
            raise SystemExit(0 if result.get("ok") else 1)
        except Exception as exc:
            print(json.dumps({"ok": False, "error": redact_text(str(exc))}, ensure_ascii=False))
            raise SystemExit(1)
    if len(sys.argv) == 4 and sys.argv[1] == "--continuity-e2e-run":
        try:
            result = _continuity_e2e_sync(_validate_conversation_id(sys.argv[2]), timeout_seconds=int(sys.argv[3]))
            print(json.dumps(result, ensure_ascii=False))
            raise SystemExit(0 if result.get("ok") else 1)
        except Exception as exc:
            print(json.dumps({"ok": False, "error": redact_text(str(exc))}, ensure_ascii=False))
            raise SystemExit(1)
    main()
