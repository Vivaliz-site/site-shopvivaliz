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

VERSION = "1.0.0"
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
TASK_WAIT_MAX_SECONDS = 25
MAX_INLINE_COMMANDS = max(1, int(os.environ.get("SHOPVIVALIZ_REMOTE_MCP_MAX_INLINE_COMMANDS", "4")))
INLINE_COMMAND_SLOTS = threading.BoundedSemaphore(MAX_INLINE_COMMANDS)
SYSTEMD_RUN = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_SYSTEMD_RUN", "/usr/bin/systemd-run")
SYSTEMCTL = os.environ.get("SHOPVIVALIZ_REMOTE_MCP_SYSTEMCTL", "/usr/bin/systemctl")
TASK_UNIT_PREFIX = "shopvivaliz-remote-task-"
TASKS_DIR = STATE_DIR / "tasks"
TERMINAL_STATES = {"succeeded", "failed", "expired", "cancelled", "indeterminate"}
ACTIVE_STATES = {"starting", "running", "cancel_requested"}
STARTING_UNIT_VISIBILITY_GRACE_SECONDS = 15

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
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    try:
        with conn:
            yield conn
    finally:
        conn.close()


def init_db() -> None:
    with db_conn() as db:
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
        "--property=CPUWeight=50", "--property=IOWeight=50", "--property=KillMode=control-group",
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


def audit(tool: str, host: str | None, args: dict[str, Any], ok: bool, summary: str) -> str:
    aid = str(uuid.uuid4())
    safe_args = dict(args)
    if "command" in safe_args:
        command = str(safe_args.pop("command"))
        safe_args["command_sha256"] = hashlib.sha256(command.encode()).hexdigest()
    for secret_key in ("text", "otp", "secret"):
        if secret_key in safe_args:
            secret_value = str(safe_args.pop(secret_key))
            safe_args[f"{secret_key}_sha256"] = hashlib.sha256(secret_value.encode()).hexdigest()
            safe_args[f"{secret_key}_length"] = len(secret_value)
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
    if action == "status":
        # Fail closed. Check the system manager first, then the configured
        # non-root user's manager when this host owns user-scoped services.
        user_probe = ""
        if service_user_owner:
            if not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_-]{0,31}", service_user_owner):
                raise ValueError("invalid_service_user_owner")
            user_probe = (
                f"uid=$(id -u {service_user_owner} 2>/dev/null) || exit 4; "
                f"load=$(runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
                f"systemctl --user show -p LoadState --value {service} 2>/dev/null || true); "
                f"if [ \"$load\" = loaded ]; then "
                f"runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
                f"systemctl --user --no-pager --full status {service}; "
                f"runuser -u {service_user_owner} -- env XDG_RUNTIME_DIR=/run/user/$uid "
                f"systemctl --user is-active {service}; exit $?; fi; "
            )
        return (
            f"load=$(systemctl show -p LoadState --value {service} 2>/dev/null || true); "
            f"if [ \"$load\" = loaded ]; then "
            f"systemctl --no-pager --full status {service}; "
            f"systemctl is-active {service}; exit $?; fi; "
            f"{user_probe}"
            f"printf '%s\\n' 'service_not_found' >&2; exit 4"
        )
    return f"systemctl {action} {service} && systemctl is-active {service}"


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
    return "ps -eo pid,user,pcpu,pmem,etime,comm,args --sort=-pcpu | head -n 101"



BACKEND_HOST = "always-free-arm-1787907847-26"
CONTROLLER_SERVICE = "shopvivaliz-gemini-24x7-controller.service"
CLAUDE_REMOTE_SERVICE = "shopvivaliz-claude-remote-control.service"
REPO_DIR = "/home/ubuntu/shopvivaliz-deploy/repo"
CONTROLLER_INSTALLER = "scripts/install-gemini-24x7-controller.sh"
CLAUDE_INSTALLER = "scripts/setup-claude-remote-control.sh"


def require_backend_host(host: str) -> None:
    if host != BACKEND_HOST:
        raise ValueError("backend_host_required")


def controller_status_command() -> str:
    return (
        "systemctl show " + CONTROLLER_SERVICE + " -p ActiveState -p SubState -p MainPID --no-pager; "
        "pid=$(systemctl show " + CONTROLLER_SERVICE + " -p MainPID --value); "
        "if [ -n \"$pid\" ] && [ \"$pid\" != 0 ]; then tr '\\0' ' ' < /proc/$pid/cmdline; echo; fi; "
        "python3 - <<'PY'\n"
        "import json,pathlib\n"
        "p=pathlib.Path('/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_gemini-24x7-controller-state.json')\n"
        "d=json.loads(p.read_text()) if p.exists() else {}\n"
        "print(json.dumps({k:d.get(k) for k in ('ok','liveness_ok','continuity_ready','degraded','degraded_reasons','generated_at','claude_remote_control')},separators=(',',':')))\n"
        "PY"
    )


def controller_promote_command(ref: str) -> str:
    if not re.fullmatch(r"[0-9a-fA-F]{40}", ref):
        raise ValueError("full_commit_sha_required")
    wt = f"/home/ubuntu/worktrees/remote-control-promote-{ref[:12]}"
    return (
        f"set -Eeuo pipefail; repo={REPO_DIR}; sha={ref}; wt={wt}; "
        "sudo -u ubuntu git -C \"$repo\" fetch origin main --quiet; "
        "sudo -u ubuntu git -C \"$repo\" cat-file -e \"$sha^{commit}\"; "
        "sudo -u ubuntu git -C \"$repo\" merge-base --is-ancestor \"$sha\" origin/main; "
        "if [ ! -d \"$wt\" ]; then sudo -u ubuntu git -C \"$repo\" worktree add --detach \"$wt\" \"$sha\"; fi; "
        "test \"$(sudo -u ubuntu git -C \"$wt\" rev-parse HEAD)\" = \"$sha\"; "
        "test -z \"$(sudo -u ubuntu git -C \"$wt\" status --porcelain)\"; "
        f"sudo -u ubuntu -H bash \"$wt/{CONTROLLER_INSTALLER}\" \"$wt\" \"$sha\"; "
        "systemctl is-active " + CONTROLLER_SERVICE + "; "
        "pid=$(systemctl show " + CONTROLLER_SERVICE + " -p MainPID --value); "
        "tr '\\0' ' ' < /proc/$pid/cmdline | grep -F -- \"$sha\" >/dev/null; "
        "echo CONTROLLER_PROMOTE=PASS"
    )


def claude_remote_control_status_command() -> str:
    return (
        "systemctl show " + CLAUDE_REMOTE_SERVICE + " -p ActiveState -p SubState -p MainPID --no-pager; "
        "systemctl cat " + CLAUDE_REMOTE_SERVICE + " --no-pager | "
        "grep -E '^(ExecStart|Restart|StandardOutput|StandardError)=' || true"
    )


def claude_remote_control_install_command(ref: str) -> str:
    if not re.fullmatch(r"[0-9a-fA-F]{40}", ref):
        raise ValueError("full_commit_sha_required")
    wt = f"/home/ubuntu/worktrees/remote-control-claude-{ref[:12]}"
    return (
        f"set -Eeuo pipefail; repo={REPO_DIR}; sha={ref}; wt={wt}; "
        "sudo -u ubuntu git -C \"$repo\" fetch origin main --quiet; "
        "sudo -u ubuntu git -C \"$repo\" cat-file -e \"$sha^{commit}\"; "
        "sudo -u ubuntu git -C \"$repo\" merge-base --is-ancestor \"$sha\" origin/main; "
        "if [ ! -d \"$wt\" ]; then sudo -u ubuntu git -C \"$repo\" worktree add --detach \"$wt\" \"$sha\"; fi; "
        "test \"$(sudo -u ubuntu git -C \"$wt\" rev-parse HEAD)\" = \"$sha\"; "
        f"bash \"$wt/{CLAUDE_INSTALLER}\" install "
        "\"$wt/scripts/claude-remote-control-mcp-stdio.py\" "
        "\"$wt/deploy/systemd/shopvivaliz-claude-remote-control.service\" "
        "\"$wt/scripts/claude_workspace_trust_bootstrap.py\"; "
        "systemctl is-active " + CLAUDE_REMOTE_SERVICE + "; "
        "echo CLAUDE_REMOTE_CONTROL_INSTALL=PASS"
    )


def execute_tool(
    name: str,
    args: dict[str, Any],
    cancel_check: Callable[[], bool] | None = None,
) -> dict[str, Any]:
    host = args.get("host")
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
    elif name == "controller_status":
        require_backend_host(str(host))
        result = run_host_command(str(host), controller_status_command(), timeout, cancel_check)
    elif name == "controller_promote":
        require_backend_host(str(host))
        result = run_host_command(str(host), controller_promote_command(str(args.get("ref") or "")), timeout, cancel_check)
    elif name == "claude_remote_control_status":
        require_backend_host(str(host))
        result = run_host_command(str(host), claude_remote_control_status_command(), timeout, cancel_check)
    elif name == "claude_remote_control_install":
        require_backend_host(str(host))
        result = run_host_command(str(host), claude_remote_control_install_command(str(args.get("ref") or "")), timeout, cancel_check)
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
        result = run_host_command(str(host), service_command(platform, str(args.get("service") or ""), action), timeout, cancel_check)
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
    ("hosts_list", "List the four canonical ShopVivaliz hosts and transport roles.", {}, True, False),
    ("host_health", "Check live identity, privilege and reachability for a named host.", {"host": {"type": "string", "enum": list(HOSTS)}}, True, False),
    ("controller_status", "Inspect the ShopVivaliz 24x7 continuity controller release and sanitized readiness state on the backend.", {"host": {"type": "string", "enum": [BACKEND_HOST]}}, True, False),
    ("controller_promote", "Promote an exact main-ancestor commit to the backend 24x7 continuity controller and verify the active process uses that commit.", {"host": {"type": "string", "enum": [BACKEND_HOST]}, "ref": {"type": "string", "pattern": "^[0-9a-fA-F]{40}$"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}}, False, True),
    ("claude_remote_control_status", "Inspect the canonical Claude Remote Control service on the backend.", {"host": {"type": "string", "enum": [BACKEND_HOST]}}, True, False),
    ("claude_remote_control_install", "Install the canonical Claude Remote Control service from an exact main-ancestor commit and verify it is active.", {"host": {"type": "string", "enum": [BACKEND_HOST]}, "ref": {"type": "string", "pattern": "^[0-9a-fA-F]{40}$"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}}, False, True),
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
        specs.append({
            "name": name,
            "description": desc,
            "inputSchema": {
                "type": "object", "properties": props,
                "required": [k for k in props if k not in {"timeout", "max_bytes", "lines", "limit", "request_id", "wait_seconds", "durable"}],
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
                active = db.execute(
                    "SELECT 1 FROM tasks WHERE state IN ('starting','running','cancel_requested') LIMIT 1"
                ).fetchone()
                if active:
                    continue
                row = db.execute(
                    "SELECT id,timeout FROM tasks WHERE state='queued' ORDER BY created_at LIMIT 1"
                ).fetchone()
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
                audit("task_worker", None, {"task_id": tid}, True, "durable_task_service_launched")
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
    main()
