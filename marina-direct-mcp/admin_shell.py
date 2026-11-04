"""Owner-approved admin shell for the Marina MCP, bound to grant PR 3620.

The HTTP process runs as the unprivileged service account. A command runs as
that account by default; with as_root=true it runs as root through sudo (the
sudoers rule is installed by the release script, never by this module). The
shipped grant file must match the pinned owner approval field for field.
Disabled unless the service is started with --enable-admin-shell and with
--access-config (Cloudflare Access JWT verified at origin).
"""
from __future__ import annotations

import hashlib
import json
import os
import pwd
import resource
import subprocess
import tempfile
import time
from pathlib import Path

GRANT_PR = 3620
SERVICE_USER = "shopvivaliz-marina-mcp"
GRANT_FILE = Path(__file__).with_name("mcp-admin-grant.json")
AUDIT_FILE = Path("/var/lib/shopvivaliz-marina-mcp/state/admin-shell-audit.log")
SUDO = "/usr/bin/sudo"
TIMEOUT_BIN = "/usr/bin/timeout"
MAX_OUTPUT_BYTES = 65536
DEFAULT_TIMEOUT = 60
MAX_TIMEOUT = 300

# Keep identical to gui_browser.py and security/owner-approvals/3620.json.
EXPECTED_GRANT = {
    "schema": "SHOPVIVALIZ_OWNER_PR_GRANT_V1",
    "repository": "Vivaliz-site/site-shopvivaliz",
    "pr_number": GRANT_PR,
    "resource": "mcp:marina",
    "principal": "marina-mcp-service",
    "target_repository": "Vivaliz-site/site-shopvivaliz",
    "host": "marina-a1-4c24g",
    "capabilities": ["admin_shell", "admin_shell_root", "gui_session", "browser_automation"],
    "operation": "marina_admin_execute",
    "run_as_user": SERVICE_USER,
    "arbitrary_shell_allowed": True,
    "root_via_sudo_allowed": True,
    "root_or_shell_allowed": True,
    "sudo_allowed": True,
    "root_http_daemon_allowed": False,
    "external_jwt_admin_allowed": False,
    "requires_access_jwt": True,
    "display": ":99",
    "browser_profile": "/var/lib/shopvivaliz-marina-mcp/browser-profile",
    "stored_credentials_allowed": False,
}

ADMIN_SHELL_TOOL = {
    "name": "marina_admin_shell",
    "description": "Run one bash command on Marina. By default it runs as the shopvivaliz-marina-mcp service account; with as_root=true it runs as root through sudo. Owner grant PR 3620. Output is truncated and every call is audited by command hash.",
    "inputSchema": {
        "type": "object",
        "properties": {
            "command": {"type": "string", "minLength": 1, "maxLength": 4096},
            "timeout_seconds": {"type": "integer", "minimum": 1, "maximum": MAX_TIMEOUT},
            "as_root": {"type": "boolean"},
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
    # The HTTP process itself must stay unprivileged; root is reached only via sudo.
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


def _limit_output_size() -> None:
    # One byte over the cap is enough to prove truncation; the child is then killed by SIGXFSZ.
    limit = MAX_OUTPUT_BYTES + 1
    resource.setrlimit(resource.RLIMIT_FSIZE, (limit, limit))


def _read_bounded(stream) -> tuple[bytes, bool]:
    stream.seek(0)
    data = stream.read(MAX_OUTPUT_BYTES + 1)
    return data[:MAX_OUTPUT_BYTES], len(data) > MAX_OUTPUT_BYTES


def _argv(command: str, timeout: int, as_root: bool) -> list[str]:
    shell = ["/bin/bash", "--noprofile", "--norc", "-c", command]
    if not as_root:
        return shell
    # coreutils timeout runs as root inside sudo, so a timed-out root command is
    # killed by root instead of being orphaned when the unprivileged sudo is killed.
    return [SUDO, "-n", "-H", "--", TIMEOUT_BIN, "-k", "5", str(timeout)] + shell


def run_admin_shell(arguments: dict, *, home: str | None = None, audit=_audit, runner=subprocess.run) -> dict:
    if set(arguments) - {"command", "timeout_seconds", "as_root"} or "command" not in arguments:
        return {"ok": False, "reason": "invalid_arguments"}
    command = arguments["command"]
    timeout = arguments.get("timeout_seconds", DEFAULT_TIMEOUT)
    as_root = arguments.get("as_root", False)
    if (not isinstance(command, str) or not 1 <= len(command) <= 4096
            or type(timeout) is not int or not 1 <= timeout <= MAX_TIMEOUT
            or type(as_root) is not bool):
        return {"ok": False, "reason": "invalid_arguments"}
    if not _service_identity_ok():
        return {"ok": False, "reason": "service_identity_required"}
    digest = hashlib.sha256(command.encode("utf-8")).hexdigest()
    started = time.time()
    if not audit({"event": "start", "command_sha256": digest, "as_root": as_root, "at": int(started)}):
        return {"ok": False, "reason": "audit_unavailable"}
    workdir = home or str(Path.home())
    path = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" if as_root else "/usr/local/bin:/usr/bin:/bin"
    # Output goes to bounded temporary files, never to memory: RLIMIT_FSIZE makes
    # the kernel stop a flooding child while the timeout is still running.
    with tempfile.TemporaryFile() as out_file, tempfile.TemporaryFile() as err_file:
        try:
            proc = runner(_argv(command, timeout, as_root), cwd=workdir,
                          env={"PATH": path, "HOME": workdir, "LANG": "C.UTF-8"},
                          stdout=out_file, stderr=err_file, timeout=timeout + (10 if as_root else 0),
                          check=False, preexec_fn=_limit_output_size)
            exit_code, timed_out = proc.returncode, False
        except subprocess.TimeoutExpired:
            exit_code, timed_out = None, True
        out, out_over = _read_bounded(out_file)
        err, err_over = _read_bounded(err_file)
    # coreutils timeout exits 124 when it had to stop the command.
    timed_out = timed_out or (as_root and exit_code == 124)
    audit({"event": "end", "command_sha256": digest, "as_root": as_root, "exit_code": exit_code,
           "timed_out": timed_out, "duration_seconds": round(time.time() - started, 3)})
    return {
        "ok": (not timed_out) and exit_code == 0,
        "exit_code": exit_code,
        "timed_out": timed_out,
        "stdout": out.decode("utf-8", "replace"),
        "stderr": err.decode("utf-8", "replace"),
        "truncated": out_over or err_over,
    }


def bootstrap_admin_shell(grant_file: Path = GRANT_FILE):
    load_grant(grant_file)
    if not _service_identity_ok():
        raise RuntimeError("admin_shell_requires_service_identity")
    return run_admin_shell
