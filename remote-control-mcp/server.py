#!/usr/bin/env python3
"""ShopVivaliz private remote-control MCP.

Stateless MCP-over-HTTP endpoint bound to backend loopback. It controls four
canonical hosts through local execution or private/reverse SSH and persists
durable tasks/audit state in SQLite. No GitHub API is used at runtime.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import re
import signal
import sqlite3
import subprocess
import threading
import time
import uuid
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

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

HOSTS = {
    "always-free-arm-1787907847-26": {
        "platform": "linux", "transport": "local", "role": "backend/control/browser"
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
ACTIVE_PROCS: dict[str, subprocess.Popen[str]] = {}
ACTIVE_LOCK = threading.Lock()
STOP_EVENT = threading.Event()


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


def db_conn() -> sqlite3.Connection:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    return conn


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
        db.execute(
            "UPDATE tasks SET state='queued', started_at=NULL, heartbeat_at=NULL "
            "WHERE state='running'"
        )


def audit(tool: str, host: str | None, args: dict[str, Any], ok: bool, summary: str) -> str:
    aid = str(uuid.uuid4())
    safe_args = dict(args)
    if "command" in safe_args:
        command = str(safe_args.pop("command"))
        safe_args["command_sha256"] = hashlib.sha256(command.encode()).hexdigest()
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
        return ["bash", "-lc", command]
    address = str(cfg["address"])
    port = int(cfg.get("port", 22))
    base = ssh_base(address, str(cfg["user"]), port)
    if platform == "linux":
        payload = base64.b64encode(command.encode()).decode()
        remote = f"printf %s {payload} | base64 -d | sudo -n bash"
        return base + [remote]
    encoded = base64.b64encode(command.encode("utf-16le")).decode()
    return base + ["powershell.exe", "-NoLogo", "-NoProfile", "-NonInteractive", "-EncodedCommand", encoded]


def run_host_command(host: str, command: str, timeout: int = DEFAULT_TIMEOUT) -> dict[str, Any]:
    timeout = validate_timeout(timeout)
    args = remote_invocation(host, command)
    started = time.monotonic()
    cp = subprocess.run(args, capture_output=True, timeout=timeout)
    return {
        "host": host,
        "exit_code": cp.returncode,
        "stdout": redact_text(cp.stdout.decode("utf-8", errors="replace")),
        "stderr": redact_text(cp.stderr.decode("utf-8", errors="replace")),
        "duration_ms": int((time.monotonic() - started) * 1000),
    }


def health_command(platform: str) -> str:
    if platform == "windows":
        return (
            "$p=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent();"
            "$a=$p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator);"
            "[pscustomobject]@{hostname=$env:COMPUTERNAME;user=[Environment]::UserName;"
            "administrator=$a;ps=$PSVersionTable.PSVersion.ToString()}|ConvertTo-Json -Compress"
        )
    return "printf 'hostname='; hostname; printf 'user='; id -un; printf 'uid='; id -u; uptime -p || true"


def service_command(platform: str, service: str, action: str) -> str:
    if not SERVICE_RE.fullmatch(service):
        raise ValueError("invalid_service_name")
    if platform == "windows":
        q = service.replace("'", "''")
        if action == "status":
            return f"Get-Service -Name '{q}' | Select-Object Name,Status,StartType | ConvertTo-Json -Compress"
        verb = {"start": "Start-Service", "stop": "Stop-Service", "restart": "Restart-Service"}[action]
        return f"{verb} -Name '{q}' -ErrorAction Stop; Get-Service -Name '{q}' | Select-Object Name,Status,StartType | ConvertTo-Json -Compress"
    if action == "status":
        return f"systemctl --no-pager --full status {service} || true; systemctl is-active {service} || true"
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


def execute_tool(name: str, args: dict[str, Any]) -> dict[str, Any]:
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
        with db_conn() as db:
            row = db.execute(
                "SELECT id,host,state,created_at,started_at,finished_at,heartbeat_at,timeout,exit_code,stdout,stderr,command_sha256 FROM tasks WHERE id=?",
                (tid,),
            ).fetchone()
        if not row:
            raise ValueError("task_not_found")
        return dict(row)
    if name == "task_cancel":
        tid = str(args.get("task_id") or "")
        with db_conn() as db:
            row = db.execute("SELECT state FROM tasks WHERE id=?", (tid,)).fetchone()
            if not row:
                raise ValueError("task_not_found")
            if row["state"] in {"succeeded", "failed", "cancelled", "expired"}:
                return {"task_id": tid, "state": row["state"]}
            db.execute("UPDATE tasks SET state='cancelled',finished_at=? WHERE id=?", (now(), tid))
        with ACTIVE_LOCK:
            proc = ACTIVE_PROCS.get(tid)
            if proc and proc.poll() is None:
                proc.terminate()
        return {"task_id": tid, "state": "cancelled"}
    if name == "task_submit":
        cfg = validate_host(str(host))
        command = str(args.get("command") or "")
        if not command.strip():
            raise ValueError("command_required")
        timeout = validate_timeout(args.get("timeout", 300))
        tid = str(uuid.uuid4())
        with db_conn() as db:
            db.execute(
                "INSERT INTO tasks(id,host,command,command_sha256,state,created_at,timeout) VALUES(?,?,?,?,?,?,?)",
                (tid, host, command, hashlib.sha256(command.encode()).hexdigest(), "queued", now(), timeout),
            )
        return {"task_id": tid, "host": host, "state": "queued", "platform": cfg["platform"]}

    cfg = validate_host(str(host))
    platform = str(cfg["platform"])
    timeout = validate_timeout(args.get("timeout"))
    if name == "host_health":
        result = run_host_command(str(host), health_command(platform), timeout)
    elif name == "processes_list":
        result = run_host_command(str(host), processes_command(platform), timeout)
    elif name == "service_status":
        result = run_host_command(str(host), service_command(platform, str(args.get("service") or ""), "status"), timeout)
    elif name == "service_action":
        action = str(args.get("action") or "")
        if action not in {"start", "stop", "restart"}:
            raise ValueError("invalid_service_action")
        result = run_host_command(str(host), service_command(platform, str(args.get("service") or ""), action), timeout)
    elif name == "file_read":
        result = run_host_command(str(host), file_read_command(platform, str(args.get("path") or ""), int(args.get("max_bytes", 65536))), timeout)
    elif name == "file_list":
        result = run_host_command(str(host), file_list_command(platform, str(args.get("path") or "")), timeout)
    elif name == "logs_tail":
        result = run_host_command(str(host), logs_tail_command(platform, str(args.get("path") or ""), int(args.get("lines", 100))), timeout)
    elif name == "admin_command_run":
        command = str(args.get("command") or "")
        if not command.strip():
            raise ValueError("command_required")
        result = run_host_command(str(host), command, timeout)
    else:
        raise ValueError("unknown_tool")
    result["ok"] = result["exit_code"] == 0
    return result


TOOLS = [
    ("hosts_list", "List the four canonical ShopVivaliz hosts and transport roles.", {}, True, False),
    ("host_health", "Check live identity, privilege and reachability for a named host.", {"host": {"type": "string", "enum": list(HOSTS)}}, True, False),
    ("processes_list", "List top processes on a named host.", {"host": {"type": "string", "enum": list(HOSTS)}}, True, False),
    ("service_status", "Inspect a service on a named host.", {"host": {"type": "string", "enum": list(HOSTS)}, "service": {"type": "string"}}, True, False),
    ("service_action", "Start, stop or restart a service with administrative privilege.", {"host": {"type": "string", "enum": list(HOSTS)}, "service": {"type": "string"}, "action": {"type": "string", "enum": ["start", "stop", "restart"]}}, False, True),
    ("file_read", "Read a non-sensitive file from a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}, "max_bytes": {"type": "integer", "minimum": 1, "maximum": 262144}}, True, False),
    ("file_list", "List a non-sensitive directory on a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}}, True, False),
    ("logs_tail", "Tail a non-sensitive log file on a host.", {"host": {"type": "string", "enum": list(HOSTS)}, "path": {"type": "string"}, "lines": {"type": "integer", "minimum": 1, "maximum": 1000}}, True, False),
    ("admin_command_run", "Run a bounded administrative shell or PowerShell command on a named host. Fully audited.", {"host": {"type": "string", "enum": list(HOSTS)}, "command": {"type": "string"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}}, False, True),
    ("task_submit", "Queue a durable administrative command that continues independently of the chat.", {"host": {"type": "string", "enum": list(HOSTS)}, "command": {"type": "string"}, "timeout": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT}}, False, True),
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
                "required": [k for k in props if k not in {"timeout", "max_bytes", "lines", "limit"}],
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
    while not STOP_EVENT.wait(1):
        tid = None
        try:
            with db_conn() as db:
                row = db.execute(
                    "SELECT id,host,command,timeout FROM tasks WHERE state='queued' ORDER BY created_at LIMIT 1"
                ).fetchone()
                if not row:
                    continue
                changed = db.execute(
                    "UPDATE tasks SET state='running',started_at=?,heartbeat_at=? WHERE id=? AND state='queued'",
                    (now(), now(), row["id"]),
                ).rowcount
            if not changed:
                continue
            tid, host, command, timeout = row["id"], row["host"], row["command"], int(row["timeout"])
            proc = subprocess.Popen(
                remote_invocation(host, command), stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                text=True, start_new_session=True
            )
            with ACTIVE_LOCK:
                ACTIVE_PROCS[tid] = proc
            deadline = time.monotonic() + timeout
            state = "running"
            while proc.poll() is None:
                with db_conn() as db:
                    current = db.execute("SELECT state FROM tasks WHERE id=?", (tid,)).fetchone()
                    if current and current["state"] == "cancelled":
                        proc.terminate()
                        state = "cancelled"
                        break
                    db.execute("UPDATE tasks SET heartbeat_at=? WHERE id=?", (now(), tid))
                if time.monotonic() >= deadline:
                    proc.kill()
                    state = "expired"
                    break
                time.sleep(2)
            stdout, stderr = proc.communicate(timeout=10)
            rc = proc.returncode
            if state == "running":
                state = "succeeded" if rc == 0 else "failed"
            with db_conn() as db:
                db.execute(
                    "UPDATE tasks SET state=?,finished_at=?,heartbeat_at=?,exit_code=?,stdout=?,stderr=? WHERE id=?",
                    (state, now(), now(), rc, redact_text(stdout), redact_text(stderr), tid),
                )
            audit("task_worker", host, {"task_id": tid}, state == "succeeded", f"task {state} rc={rc}")
        except Exception as exc:
            time.sleep(1)
            try:
                if tid:
                    with db_conn() as db:
                        db.execute(
                            "UPDATE tasks SET state='failed',finished_at=?,stderr=? WHERE id=?",
                            (now(), redact_text(str(exc)), tid),
                        )
            except Exception:
                pass
        finally:
            if tid:
                with ACTIVE_LOCK:
                    ACTIVE_PROCS.pop(tid, None)


class Handler(BaseHTTPRequestHandler):
    server_version = "ShopVivalizRemoteControlMCP/" + VERSION

    def log_message(self, fmt: str, *args: Any) -> None:
        return

    def _json(self, status: int, payload: Any) -> None:
        raw = json.dumps(payload, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self) -> None:
        if self.path == "/health":
            self._json(200, {
                "ok": True, "endpoint": "shopvivaliz-remote-control-mcp",
                "version": VERSION, "hosts": list(HOSTS), "timestamp": now()
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
                    output = execute_tool(name, args)
                    ok = not (isinstance(output, dict) and output.get("ok") is False)
                    aid = audit(name, host, args, ok, "ok" if ok else "command_failed")
                    if isinstance(output, dict):
                        output["audit_id"] = aid
                    result = {
                        "content": [{"type": "text", "text": json.dumps(output, ensure_ascii=False)}],
                        "structuredContent": output,
                        "isError": not ok,
                    }
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
        except Exception as exc:
            self._json(400, {"jsonrpc": "2.0", "id": None, "error": {"code": -32600, "message": redact_text(str(exc))}})


def main() -> None:
    STATE_DIR.mkdir(parents=True, exist_ok=True)
    init_db()
    worker = threading.Thread(target=task_worker, name="task-worker", daemon=True)
    worker.start()
    server = ThreadingHTTPServer((LISTEN_HOST, LISTEN_PORT), Handler)
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        STOP_EVENT.set()
        server.server_close()


if __name__ == "__main__":
    main()
