#!/usr/bin/env python3
"""Private four-host control plane for ShopVivaliz.

Runtime command transport never depends on GitHub:
- backend: local root execution
- production site: private VCN SSH using a backend-held dedicated key
- Windows hosts: existing loopback-only reverse MCP relays on the backend
"""
from __future__ import annotations

import argparse
import json
import os
import re
import shlex
import sqlite3
import subprocess
import time
import urllib.request
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any

DB = Path(os.getenv("SHOPVIVALIZ_REMOTE_DB", "/var/lib/shopvivaliz-remote/control.db"))
MAX_OUTPUT = 200_000
TASK_TIMEOUT = 1800
SITE_KEY = "/home/ubuntu/.ssh/shopvivaliz_remote_control_ed25519"
SITE_KNOWN_HOSTS = "/home/ubuntu/.ssh/shopvivaliz_remote_control_known_hosts"
HOSTS: dict[str, dict[str, str]] = {
    "always-free-arm-1787907847-26": {"kind": "local"},
    "shopvivaliz-free-a1": {
        "kind": "ssh-admin",
        "target": "ubuntu@10.0.1.112",
        "key": SITE_KEY,
        "known_hosts": SITE_KNOWN_HOSTS,
    },
    "fred-win": {"kind": "relay", "url": "http://127.0.0.1:5557"},
    "kocepsv": {"kind": "relay", "url": "http://127.0.0.1:5558"},
}
TERMINAL_STATES = {"succeeded", "failed", "cancelled"}
SECRET_PATH_PATTERNS = (
    "/.ssh/", "\\.ssh\\", "/etc/shadow", ".env", "credentials", "cookie",
    "token", "secret", "id_ed25519", "id_rsa",
)
SERVICE_RE = re.compile(r"^[A-Za-z0-9_.@-]{1,120}$")


def _db() -> sqlite3.Connection:
    DB.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB, timeout=30)
    conn.row_factory = sqlite3.Row
    conn.execute("pragma journal_mode=wal")
    conn.execute(
        """create table if not exists tasks(
            id text primary key, host text not null, command text not null,
            state text not null, created real not null, started real, finished real,
            exit_code integer, stdout text, stderr text, unit_name text
        )"""
    )
    conn.execute(
        """create table if not exists audit(
            id text primary key, ts real not null, host text not null,
            action text not null, status text not null, detail text
        )"""
    )
    conn.commit()
    return conn


def _redact(value: str) -> str:
    text = str(value)
    patterns = [
        r"(?i)(authorization\s*[:=]\s*bearer\s+)[^\s]+",
        r"(?i)((?:api[_-]?key|token|secret|password|cookie)\s*[:=]\s*)[^\s;&]+",
        r"(?i)(-----BEGIN [A-Z ]*PRIVATE KEY-----).*?(-----END [A-Z ]*PRIVATE KEY-----)",
    ]
    for pattern in patterns:
        text = re.sub(pattern, lambda m: m.group(1) + "[REDACTED]" + (m.group(2) if m.lastindex and m.lastindex > 1 else ""), text, flags=re.S)
    return text[-MAX_OUTPUT:]


def audit(host: str, action: str, status: str, detail: str = "") -> str:
    audit_id = str(uuid.uuid4())
    conn = _db()
    conn.execute(
        "insert into audit(id,ts,host,action,status,detail) values(?,?,?,?,?,?)",
        (audit_id, time.time(), host, action, status, _redact(detail)[:4000]),
    )
    conn.commit()
    conn.close()
    return audit_id


def _relay_call(url: str, command: str, timeout: int) -> tuple[int, str, str]:
    body = json.dumps({"params": {"command": command, "timeout": timeout}}).encode()
    request = urllib.request.Request(
        url + "/mcp/tool/execute_command",
        data=body,
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    with urllib.request.urlopen(request, timeout=timeout + 10) as response:
        data = json.loads(response.read().decode("utf-8"))
    result = data.get("result") or {}
    return (
        0 if result.get("success") is True else 1,
        _redact(str(result.get("output") or "")),
        _redact(str(result.get("error") or "")),
    )


def run_host(host: str, command: str, timeout: int = 300) -> tuple[int, str, str]:
    if host not in HOSTS:
        raise ValueError("unknown host")
    timeout = max(1, min(int(timeout), TASK_TIMEOUT))
    cfg = HOSTS[host]
    kind = cfg["kind"]
    if kind == "local":
        proc = subprocess.run(
            ["/bin/bash", "-lc", command],
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        return proc.returncode, _redact(proc.stdout), _redact(proc.stderr)
    if kind == "ssh-admin":
        remote = "sudo -n /bin/bash -lc " + shlex.quote(command)
        proc = subprocess.run(
            [
                "ssh", "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
                "-o", "StrictHostKeyChecking=yes", "-o", "ConnectTimeout=10",
                "-o", f"UserKnownHostsFile={cfg['known_hosts']}",
                "-i", cfg["key"], cfg["target"], remote,
            ],
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        return proc.returncode, _redact(proc.stdout), _redact(proc.stderr)
    return _relay_call(cfg["url"], command, timeout)


def _windows_admin_probe() -> str:
    return (
        "powershell -NoProfile -NonInteractive -Command "
        "\"$p=[Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent();"
        "Write-Output ('__HOST__=' + $env:COMPUTERNAME);"
        "Write-Output ('__USER__=' + [Security.Principal.WindowsIdentity]::GetCurrent().Name);"
        "Write-Output ('__ADMIN__=' + $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator))\""
    )


def host_health(host: str) -> dict[str, Any]:
    command = _windows_admin_probe() if host in {"fred-win", "kocepsv"} else (
        "echo __HOST__=$(hostname); echo __USER__=$(whoami); echo __UID__=$(id -u); uptime"
    )
    try:
        rc, out, err = run_host(host, command, 30)
        privileged = "__ADMIN__=True" in out or "__UID__=0" in out
        status = {"host": host, "ok": rc == 0, "privileged": privileged, "output": out, "error": err}
        audit(host, "health", "ok" if status["ok"] and privileged else "failed", out + "\n" + err)
        return status
    except Exception as exc:
        audit(host, "health", "failed", str(exc))
        return {"host": host, "ok": False, "privileged": False, "error": _redact(str(exc)), "output": ""}


def hosts_list() -> dict[str, Any]:
    return {"hosts": [host_health(host) for host in HOSTS]}


def admin_command_run(host: str, command: str, timeout: int = 300) -> dict[str, Any]:
    if not str(command).strip():
        raise ValueError("command is required")
    rc, out, err = run_host(host, command, timeout)
    aid = audit(host, "admin_command_run", "ok" if rc == 0 else "failed", f"command={command}\n{out}\n{err}")
    return {"ok": rc == 0, "host": host, "exit_code": rc, "stdout": out, "stderr": err, "audit_id": aid}


def _validate_service(service: str) -> str:
    value = str(service).strip()
    if not SERVICE_RE.fullmatch(value):
        raise ValueError("invalid service name")
    return value


def processes_list(host: str) -> dict[str, Any]:
    command = (
        "powershell -NoProfile -NonInteractive -Command "
        "\"Get-Process | Sort-Object CPU -Descending | Select-Object -First 100 Id,ProcessName,CPU,WorkingSet64 | ConvertTo-Json -Compress\""
        if host in {"fred-win", "kocepsv"}
        else "ps -eo pid,user,comm,%cpu,%mem --sort=-%cpu | head -n 101"
    )
    return admin_command_run(host, command, 60)


def service_status(host: str, service: str) -> dict[str, Any]:
    service = _validate_service(service)
    command = (
        f"powershell -NoProfile -NonInteractive -Command \"Get-Service -Name '{service}' | Select-Object Name,Status,StartType | ConvertTo-Json -Compress\""
        if host in {"fred-win", "kocepsv"}
        else f"systemctl status {shlex.quote(service)} --no-pager -l"
    )
    return admin_command_run(host, command, 60)


def service_action(host: str, service: str, action: str) -> dict[str, Any]:
    service = _validate_service(service)
    action = str(action).strip().lower()
    if action not in {"start", "stop", "restart"}:
        raise ValueError("action must be start, stop or restart")
    if host in {"fred-win", "kocepsv"}:
        verb = {"start": "Start-Service", "stop": "Stop-Service", "restart": "Restart-Service"}[action]
        command = f"powershell -NoProfile -NonInteractive -Command \"{verb} -Name '{service}' -ErrorAction Stop; Get-Service -Name '{service}' | Select-Object Name,Status | ConvertTo-Json -Compress\""
    else:
        command = f"systemctl {action} {shlex.quote(service)} && systemctl is-active {shlex.quote(service)}"
    return admin_command_run(host, command, 120)


def _guard_path(path: str) -> str:
    value = str(path).strip()
    if not value:
        raise ValueError("path is required")
    lower = value.lower()
    if any(pattern.lower() in lower for pattern in SECRET_PATH_PATTERNS):
        raise ValueError("secret-bearing path is not readable through MCP")
    return value


def file_list(host: str, path: str) -> dict[str, Any]:
    path = _guard_path(path)
    if host in {"fred-win", "kocepsv"}:
        escaped = path.replace("'", "''")
        command = f"powershell -NoProfile -NonInteractive -Command \"Get-ChildItem -LiteralPath '{escaped}' -Force | Select-Object -First 250 Name,Length,Mode,LastWriteTime | ConvertTo-Json -Compress\""
    else:
        command = f"find {shlex.quote(path)} -maxdepth 1 -mindepth 1 -printf '%f\t%s\t%TY-%Tm-%TdT%TH:%TM:%TS\n' | head -n 250"
    return admin_command_run(host, command, 60)


def file_read(host: str, path: str, max_bytes: int = 100_000) -> dict[str, Any]:
    path = _guard_path(path)
    limit = max(1, min(int(max_bytes), 200_000))
    if host in {"fred-win", "kocepsv"}:
        escaped = path.replace("'", "''")
        command = (
            "powershell -NoProfile -NonInteractive -Command "
            f"\"$s=Get-Content -Raw -LiteralPath '{escaped}'; if($s.Length -gt {limit}){{$s=$s.Substring(0,{limit})}}; Write-Output $s\""
        )
    else:
        command = f"head -c {limit} -- {shlex.quote(path)}"
    return admin_command_run(host, command, 60)


def logs_tail(host: str, service: str, lines: int = 100) -> dict[str, Any]:
    service = _validate_service(service)
    count = max(1, min(int(lines), 500))
    if host in {"fred-win", "kocepsv"}:
        command = (
            "powershell -NoProfile -NonInteractive -Command "
            f"\"Get-WinEvent -FilterHashtable @{{LogName='System';ProviderName='Service Control Manager'}} -MaxEvents {count} "
            "| Select-Object TimeCreated,Id,LevelDisplayName,Message | ConvertTo-Json -Compress\""
        )
    else:
        command = f"journalctl -u {shlex.quote(service)} -n {count} --no-pager -o short-iso"
    return admin_command_run(host, command, 60)


def _task_unit(task_id: str) -> str:
    return "shopvivaliz-remote-task-" + task_id.replace("-", "")[:20]


def task_submit(host: str, command: str) -> dict[str, Any]:
    if host not in HOSTS or not str(command).strip():
        raise ValueError("valid host and command are required")
    task_id = str(uuid.uuid4())
    unit = _task_unit(task_id)
    conn = _db()
    conn.execute(
        "insert into tasks(id,host,command,state,created,unit_name) values(?,?,?,?,?,?)",
        (task_id, host, command, "queued", time.time(), unit),
    )
    conn.commit()
    conn.close()
    proc = subprocess.run(
        [
            "systemd-run", "--quiet", "--collect", f"--unit={unit}",
            f"--property=RuntimeMaxSec={TASK_TIMEOUT + 60}",
            "/usr/bin/python3", "/opt/shopvivaliz-remote/controller.py", "--run-task", task_id,
        ],
        capture_output=True,
        text=True,
    )
    if proc.returncode != 0:
        conn = _db()
        conn.execute(
            "update tasks set state='failed',finished=?,exit_code=?,stderr=? where id=?",
            (time.time(), proc.returncode, _redact(proc.stderr), task_id),
        )
        conn.commit()
        conn.close()
        audit(host, "task_submit", "failed", proc.stderr)
        raise RuntimeError("failed to launch durable task")
    aid = audit(host, "task_submit", "queued", task_id)
    return {"task_id": task_id, "state": "queued", "unit": unit, "audit_id": aid}


def run_task(task_id: str) -> int:
    conn = _db()
    row = conn.execute("select * from tasks where id=?", (task_id,)).fetchone()
    if row is None:
        conn.close()
        return 2
    if row["state"] in TERMINAL_STATES:
        conn.close()
        return 0
    conn.execute("update tasks set state='running',started=? where id=?", (time.time(), task_id))
    conn.commit()
    conn.close()
    try:
        rc, out, err = run_host(row["host"], row["command"], TASK_TIMEOUT)
        state = "succeeded" if rc == 0 else "failed"
    except Exception as exc:
        rc, out, err, state = 255, "", _redact(str(exc)), "failed"
    conn = _db()
    conn.execute(
        "update tasks set state=?,finished=?,exit_code=?,stdout=?,stderr=? where id=?",
        (state, time.time(), rc, out, err, task_id),
    )
    conn.commit()
    conn.close()
    audit(row["host"], "task:" + task_id, state, out + "\n" + err)
    return 0 if state == "succeeded" else 1


def task_status(task_id: str) -> dict[str, Any]:
    conn = _db()
    row = conn.execute("select * from tasks where id=?", (str(task_id),)).fetchone()
    conn.close()
    if row is None:
        raise ValueError("task not found")
    data = dict(row)
    data["command"] = _redact(data["command"])
    data["stdout"] = _redact(data.get("stdout") or "")
    data["stderr"] = _redact(data.get("stderr") or "")
    return data


def task_cancel(task_id: str) -> dict[str, Any]:
    data = task_status(task_id)
    if data["state"] in TERMINAL_STATES:
        return data
    subprocess.run(["systemctl", "stop", data["unit_name"]], capture_output=True, text=True)
    conn = _db()
    conn.execute(
        "update tasks set state='cancelled',finished=?,exit_code=? where id=?",
        (time.time(), 143, task_id),
    )
    conn.commit()
    conn.close()
    audit(data["host"], "task_cancel", "cancelled", task_id)
    return task_status(task_id)


def audit_recent(limit: int = 50) -> dict[str, Any]:
    count = max(1, min(int(limit), 200))
    conn = _db()
    rows = conn.execute("select * from audit order by ts desc limit ?", (count,)).fetchall()
    conn.close()
    return {"events": [dict(row) for row in rows]}


class Handler(BaseHTTPRequestHandler):
    def _send(self, code: int, payload: Any) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def _body(self) -> dict[str, Any]:
        size = int(self.headers.get("Content-Length", "0"))
        return json.loads(self.rfile.read(size) or b"{}")

    def do_GET(self) -> None:
        if self.path == "/health":
            self._send(200, {"ok": True, "service": "shopvivaliz-remote-control", "version": "1.0.0", "hosts": list(HOSTS)})
            return
        if self.path == "/hosts":
            self._send(200, hosts_list())
            return
        if self.path.startswith("/task/"):
            try:
                self._send(200, task_status(self.path.rsplit("/", 1)[-1]))
            except ValueError as exc:
                self._send(404, {"error": str(exc)})
            return
        self._send(404, {"error": "not found"})

    def do_POST(self) -> None:
        data = self._body()
        try:
            if self.path == "/exec":
                self._send(200, admin_command_run(data.get("host", ""), data.get("command", ""), data.get("timeout", 300)))
                return
            if self.path == "/task":
                self._send(202, task_submit(data.get("host", ""), data.get("command", "")))
                return
            if self.path == "/task/cancel":
                self._send(200, task_cancel(data.get("task_id", "")))
                return
        except Exception as exc:
            self._send(500, {"ok": False, "error": _redact(str(exc))})
            return
        self._send(404, {"error": "not found"})

    def log_message(self, *_args: Any) -> None:
        return


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-task", default="")
    args = parser.parse_args()
    if args.run_task:
        return run_task(args.run_task)
    _db().close()
    ThreadingHTTPServer(("127.0.0.1", 5560), Handler).serve_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
