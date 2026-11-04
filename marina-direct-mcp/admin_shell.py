"""Owner-approved admin shell for the Marina MCP, bound to grant PR 3616.

Runs only as the unprivileged service account, never as root, and only when
the shipped grant file matches the merged owner grant byte-for-byte in fields.
Disabled unless the service is started with --enable-admin-shell and with
--access-config (Cloudflare Access JWT verified at origin).
"""
from __future__ import annotations

import hashlib
import json
import os
import pwd
import subprocess
import time
from pathlib import Path

GRANT_PR = 3616
GRANT_MERGE = "4c4177fda62162554ab3da5e10c00b1c8d89adea"
SERVICE_USER = "shopvivaliz-marina-mcp"
GRANT_FILE = Path(__file__).with_name("admin-shell-grant-3616.json")
AUDIT_FILE = Path("/var/lib/shopvivaliz-marina-mcp/state/admin-shell-audit.log")
MAX_OUTPUT_BYTES = 65536
DEFAULT_TIMEOUT = 60
MAX_TIMEOUT = 300

EXPECTED_GRANT = {
    "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
    "repository": "Vivaliz-site/site-shopvivaliz",
    "pr_number": GRANT_PR,
    "resource": "mcp:marina",
    "principal": "marina-mcp-service",
    "target_repository": "Vivaliz-site/site-shopvivaliz",
    "host": "marina-a1-4c24g",
    "capabilities": ["admin_shell"],
    "operation": "admin_shell_execute",
    "run_as_user": SERVICE_USER,
    "arbitrary_shell_allowed": True,
    "root_or_shell_allowed": False,
    "sudo_allowed": False,
    "root_http_daemon_allowed": False,
    "external_jwt_admin_allowed": False,
    "requires_access_jwt": True,
}

ADMIN_SHELL_TOOL = {
    "name": "marina_admin_shell",
    "description": "Run one bash command on Marina as the unprivileged shopvivaliz-marina-mcp service account. Owner grant PR 3616. No root, no sudo. Output is truncated and every call is audited by command hash.",
    "inputSchema": {
        "type": "object",
        "properties": {
            "command": {"type": "string", "minLength": 1, "maxLength": 4096},
            "timeout_seconds": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT},
        },
        "required": ["command"],
        "additionalProperties": False,
    },
}


def load_grant(path: Path = GRANT_FILE) -> dict:
    """Fail closed unless the shipped grant matches the pinned owner approval."""
    data = json.loads(path.read_text(encoding="utf-8"))
    if data != EXPECTED_GRANT:
        raise RuntimeError("admin_shell_grant_mismatch")
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


def run_admin_shell(arguments: dict, *, home: str | None = None, audit=_audit, runner=subprocess.run) -> dict:
    if set(arguments) - {"command", "timeout_seconds"} or "command" not in arguments:
        return {"ok": False, "reason": "invalid_arguments"}
    command = arguments["command"]
    timeout = arguments.get("timeout_seconds", DEFAULT_TIMEOUT)
    if (not isinstance(command, str) or not 1 <= len(command) <= 4096
            or type(timeout) is not int or not 1 <= timeout <= MAX_TIMEOUT):
        return {"ok": False, "reason": "invalid_arguments"}
    if not _service_identity_ok():
        return {"ok": False, "reason": "service_identity_required"}
    digest = hashlib.sha256(command.encode("utf-8")).hexdigest()
    started = time.time()
    if not audit({"event": "start", "command_sha256": digest, "at": int(started)}):
        return {"ok": False, "reason": "audit_unavailable"}
    workdir = home or str(Path.home())
    try:
        proc = runner(["/bin/bash", "--noprofile", "--norc", "-c", command], cwd=workdir,
                      env={"PATH": "/usr/local/bin:/usr/bin:/bin", "HOME": workdir, "LANG": "C.UTF-8"},
                      capture_output=True, timeout=timeout, check=False)
        exit_code, out, err, timed_out = proc.returncode, proc.stdout, proc.stderr, False
    except subprocess.TimeoutExpired as exc:
        exit_code, out, err, timed_out = None, exc.stdout or b"", exc.stderr or b"", True
    audit({"event": "end", "command_sha256": digest, "exit_code": exit_code,
           "timed_out": timed_out, "duration_seconds": round(time.time() - started, 3)})
    return {
        "ok": (not timed_out) and exit_code == 0,
        "exit_code": exit_code,
        "timed_out": timed_out,
        "stdout": out[:MAX_OUTPUT_BYTES].decode("utf-8", "replace"),
        "stderr": err[:MAX_OUTPUT_BYTES].decode("utf-8", "replace"),
        "truncated": len(out) > MAX_OUTPUT_BYTES or len(err) > MAX_OUTPUT_BYTES,
    }


def bootstrap_admin_shell(grant_file: Path = GRANT_FILE):
    load_grant(grant_file)
    if not _service_identity_ok():
        raise RuntimeError("admin_shell_requires_service_identity")
    return run_admin_shell
