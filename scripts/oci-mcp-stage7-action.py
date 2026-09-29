#!/usr/bin/env python3
"""Bounded Stage 7 validation through the loopback ShopVivaliz Remote Control MCP."""

from __future__ import annotations

import argparse
import json
import re
import shlex
import sys
import urllib.request
from pathlib import Path

ENDPOINT = "http://127.0.0.1:5580/mcp"
TOKEN_FILE = Path("/var/lib/shopvivaliz-remote-control/mcp-token")
BACKEND = "always-free-arm-1787907847-26"
SITE = "shopvivaliz-free-a1"


def call_admin(host: str, command: str, timeout: int) -> tuple[bool, str, str, str, int | None]:
    token = TOKEN_FILE.read_text(encoding="utf-8").strip()
    body = json.dumps(
        {
            "jsonrpc": "2.0",
            "id": 1,
            "method": "tools/call",
            "params": {
                "name": "admin_command_run",
                "arguments": {"host": host, "command": command, "timeout": timeout},
            },
        }
    ).encode()
    req = urllib.request.Request(
        ENDPOINT,
        data=body,
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + token},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout + 50) as response:
        payload = json.load(response)
    result = (payload.get("result") or {}).get("structuredContent") or {}
    error = str(result.get("error") or "")
    exit_code_raw = result.get("exit_code")
    exit_code = exit_code_raw if isinstance(exit_code_raw, int) else None
    failed = bool(
        (payload.get("result") or {}).get("isError")
        or result.get("ok") is False
        or error
    )
    return (
        not failed,
        str(result.get("stdout") or ""),
        str(result.get("stderr") or ""),
        error,
        exit_code,
    )


def classify_remote_failure(stdout: str, stderr: str, error: str = "") -> str:
    text = (stdout + "\n" + stderr + "\n" + error).lower()
    checks = (
        ("storage", ("no space left", "disk full", "insufficient_free_space")),
        ("trust", ("workspace not trusted", "trust_bootstrap", "plain_prompt_missing", "trust_not_persisted")),
        ("auth", ("not logged in", "claude_not_logged_in", "claude_auth_status_invalid", "authentication", "unauthorized", "login required")),
        ("timeout", ("timed out", "timeout", "deadline exceeded")),
        ("permission", ("permission denied", "operation not permitted")),
        ("missing", ("no such file", "not found", "command not found")),
        ("service", ("service_not_enabled", "systemctl", "unit ", "service ")),
        ("mcp", ("mcp", "jsonrpc", "tools/list")),
    )
    for label, needles in checks:
        if any(needle in text for needle in needles):
            return label
    return "remote_command"


def print_failure_envelope(
    stdout: str,
    stderr: str,
    error: str,
    exit_code: int | None,
    safe_count: int,
) -> None:
    print("CLAUDE_OCI_RESULT_EXIT_CODE=" + (str(exit_code) if exit_code is not None else "none"))
    print("CLAUDE_OCI_STDOUT_BYTES=" + str(len(stdout.encode("utf-8", errors="replace"))))
    print("CLAUDE_OCI_STDERR_BYTES=" + str(len(stderr.encode("utf-8", errors="replace"))))
    print("CLAUDE_OCI_ERROR_PRESENT=" + ("true" if error else "false"))
    print("CLAUDE_OCI_SAFE_MARKER_COUNT=" + str(safe_count))


def safe_markers(stdout: str, prefixes: tuple[str, ...]) -> list[str]:
    return [line for line in stdout.splitlines() if line.startswith(prefixes)]


def require_exact(lines: list[str], required: set[str], label: str) -> None:
    if not required.issubset(set(lines)):
        if lines:
            print("\n".join(lines))
        raise SystemExit(f"missing {label} PASS markers")


def claude_install(stage_dir: str) -> None:
    if not re.fullmatch(r"/tmp/shopvivaliz-claude-oci-[A-Za-z0-9._-]+", stage_dir):
        raise SystemExit("invalid Claude staging directory")
    q = shlex.quote
    setup = stage_dir + "/setup-claude-remote-control.sh"
    bridge = stage_dir + "/claude-remote-control-mcp-stdio.py"
    trust = stage_dir + "/claude_workspace_trust_bootstrap.py"
    unit = stage_dir + "/shopvivaliz-claude-remote-control.service"
    command = f"""set -Eeuo pipefail
trap 'rm -rf {q(stage_dir)}' EXIT
if [ ! -f {q(setup)} ] || [ ! -f {q(bridge)} ] || [ ! -f {q(trust)} ] || [ ! -f {q(unit)} ]; then
  echo "CLAUDE_OCI_PREFLIGHT=FAIL class=staging"
  exit 61
fi
echo "CLAUDE_OCI_PREFLIGHT=PASS"
chmod 700 {q(setup)}
chmod 600 {q(bridge)} {q(trust)} {q(unit)}
rc=0
out="$(bash {q(setup)} install {q(bridge)} {q(unit)} {q(trust)} 2>&1)" || rc=$?
printf '%s\n' "$out" | awk '/^CLAUDE_[A-Z0-9_]+=/{print}'
exit "$rc"
"""
    ok, stdout, stderr, error, exit_code = call_admin(BACKEND, command, 180)
    safe = safe_markers(stdout, ("CLAUDE_",))
    if not ok:
        if safe:
            print("\n".join(safe))
        print_failure_envelope(stdout, stderr, error, exit_code, len(safe))
        print("CLAUDE_OCI_FAILURE_CLASS=" + classify_remote_failure(stdout, stderr, error))
        raise SystemExit("Remote Control MCP Claude install action failed")
    required = {
        "CLAUDE_REMOTE_CONTROL_ELIGIBLE=PASS",
        "CLAUDE_MCP_BRIDGE_INSTALL=PASS",
        "CLAUDE_WORKSPACE=PASS",
        "CLAUDE_MCP_CONFIG=PASS",
        "CLAUDE_PRIVATE_MCP_BRIDGE=PASS",
        "CLAUDE_WORKSPACE_TRUST_BOOTSTRAP=PASS",
        "CLAUDE_REMOTE_CONTROL_CONSENT=PASS",
        "CLAUDE_REMOTE_CONTROL_SERVICE=PASS",
        "CLAUDE_REMOTE_CONTROL_INSTALL=PASS",
    }
    require_exact(safe, required, "Claude install")
    print("\n".join(safe))
    print("OCI_MCP_CLAUDE_INSTALL=PASS")


def claude_status() -> None:
    command = r"""set -Eeuo pipefail
rc=0
out="$(/usr/local/sbin/shopvivaliz-setup-claude-remote-control status 2>&1)" || rc=$?
printf '%s\n' "$out" | awk '/^CLAUDE_[A-Z0-9_]+=/{print}'
exit "$rc"
"""
    ok, stdout, stderr, error, exit_code = call_admin(BACKEND, command, 45)
    safe = safe_markers(stdout, ("CLAUDE_",))
    if not ok:
        if safe:
            print("\n".join(safe))
        print_failure_envelope(stdout, stderr, error, exit_code, len(safe))
        print("CLAUDE_OCI_FAILURE_CLASS=" + classify_remote_failure(stdout, stderr, error))
        raise SystemExit("Remote Control MCP Claude status action failed")
    required = {
        "CLAUDE_REMOTE_CONTROL_ELIGIBLE=PASS",
        "CLAUDE_PRIVATE_MCP_BRIDGE=PASS",
        "CLAUDE_REMOTE_CONTROL_STATUS=PASS",
    }
    require_exact(safe, required, "Claude status")
    print("\n".join(safe))
    print("OCI_MCP_CLAUDE_STATUS=PASS")


def freeze_state() -> None:
    command = r"""set -Eeuo pipefail
sudo -u ubuntu -H python3 - <<'INNER'
import json
import re
from pathlib import Path

root = Path('/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state')
pattern = re.compile(r'chatgpt-freeze-root-cause-20260928-g([2-9]|[1-9][0-9]+)\.json')
candidates = []
if root.is_dir():
    for path in root.glob('chatgpt-freeze-root-cause-20260928-g*.json'):
        match = pattern.fullmatch(path.name)
        if match:
            candidates.append((int(match.group(1)), path))
if not candidates:
    raise SystemExit('no freeze generation found')
_, path = max(candidates, key=lambda row: row[0])
try:
    payload = json.loads(path.read_text(encoding='utf-8'))
except Exception as exc:
    raise SystemExit('latest freeze generation unreadable') from exc
if not isinstance(payload, dict):
    raise SystemExit('latest freeze generation invalid')
task_id = str(payload.get('task_id') or '').strip()
status = str(payload.get('status') or '').strip()
if not re.fullmatch(r'chatgpt-freeze-root-cause-20260928-g(?:[2-9]|[1-9][0-9]+)', task_id):
    raise SystemExit('latest freeze task id invalid')
if status not in {'RUNNING','READY_TO_COMPLETE','CONCLUIDO','BLOCKED_EXTERNAL'}:
    raise SystemExit('latest freeze status invalid')
evidence = payload.get('evidence')
summary = {
    'task_id': task_id,
    'status': status,
    'updated_at': str(payload.get('updated_at') or '')[:64],
    'evidence_count': len(evidence) if isinstance(evidence, list) else 0,
    'verification_present': bool(str(payload.get('verification') or '').strip()),
    'latest_generation': True,
}
print('CHATGPT_FREEZE_LATEST_STATE=' + json.dumps(summary, ensure_ascii=False, sort_keys=True))
print('TASK_TERMINAL_GATE=' + ('PASS' if status in {'CONCLUIDO','BLOCKED_EXTERNAL'} else 'NONTERMINAL'))
INNER
"""
    ok, stdout, _stderr, _error, _exit_code = call_admin(SITE, command, 45)
    safe = safe_markers(stdout, ("CHATGPT_FREEZE_LATEST_STATE=", "TASK_TERMINAL_GATE="))
    if not ok:
        raise SystemExit("Remote Control MCP freeze-state action failed")
    keys = {line.split("=", 1)[0] for line in safe if "=" in line}
    if {"CHATGPT_FREEZE_LATEST_STATE", "TASK_TERMINAL_GATE"} - keys:
        raise SystemExit("missing freeze-state markers")
    print("\n".join(safe))
    print("OCI_MCP_CHATGPT_FREEZE_STATE=PASS")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("claude-install", "claude-status", "freeze-state"))
    parser.add_argument("--stage-dir", default="")
    args = parser.parse_args()

    if args.action == "claude-install":
        claude_install(args.stage_dir)
    elif args.action == "claude-status":
        claude_status()
    else:
        freeze_state()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
