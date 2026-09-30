#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
remote="$root/.github/workflows/shopvivaliz-remote-access.yml"
direct="$root/.github/workflows/chatgpt-account-diagnostic-direct.yml"
diag="$root/scripts/chatgpt-account-diagnostic.mjs"
agents="$root/AGENTS.md"

test -f "$remote"
test -f "$diag"
test -f "$agents"
test ! -f "$direct"

if grep -Fq 'CHATGPT_WEB_AUTOMATION_RISK_GUARD_V1' "$agents"; then
  echo "temporary ChatGPT Web automation risk guard must be removed" >&2
  exit 1
fi

if grep -Fq 'chatgpt_account_diag' "$remote"; then
  echo "remote access must not expose automated ChatGPT Web diagnostics" >&2
  exit 1
fi

# The canonical issue #1586 control plane must expose a generation-aware,
# read-only checkpoint readback for the freeze investigation. It may print
# only the allowlisted summary, never the raw durable JSON/evidence payload.
grep -Fq 'chatgpt_freeze_task_state' "$remote"
grep -Fq '/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state' "$remote"
grep -Fq 'TASK_STATE_STATUS=' "$remote"
grep -Fq 'chatgpt-freeze-root-cause-20260928-g*.json' "$remote"
grep -Fq 'print(max(candidates)[1])' "$remote"
if grep -Fq 'canonical_task_id="chatgpt-freeze-root-cause-20260928-g2"' "$remote"; then
  echo "freeze task readback must follow the latest generation" >&2
  exit 1
fi
grep -Fq 'agent_task_state.py show --task "$canonical_task_id"' "$remote"
grep -Fq 'agent_task_state.py terminal --task "$canonical_task_id"' "$remote"
if grep -Fq 'agent_task_state.py show --task chatgpt-freeze-root-cause-20260927' "$remote"; then
  echo "freeze task readback must not be hardcoded to the historical predecessor" >&2
  exit 1
fi
grep -Fq 'TASK_TERMINAL_GATE=PASS' "$remote"
grep -Fq 'TASK_TERMINAL_GATE=NONTERMINAL' "$remote"

# The read-only freeze checkpoint action must expose the highest valid gN
# generation. Historical terminal generations remain immutable lineage.
grep -Fq 'CHATGPT_FREEZE_CANONICAL_STATE=' "$remote"
grep -Fq 'chatgpt-freeze-root-cause-20260928-g*.json' "$remote"
grep -Fq 'canonical_generation' "$remote"

# A privileged writer can create a 0600 checkpoint owned by root while the
# canonical watchdog/dispatcher runs as ubuntu. The control plane must expose a
# narrow repair action that changes only ownership/mode, verifies content hash
# stability, and proves the latest generation is readable by ubuntu.
grep -Fq 'chatgpt_freeze_state_owner_repair' "$remote"
grep -Fq 'CHATGPT_FREEZE_STATE_OWNER_REPAIR=PASS' "$remote"
grep -Fq 'CHATGPT_FREEZE_STATE_OWNER_CONTENT_UNCHANGED=true' "$remote"
grep -Fq 'sudo -n chown "$runtime_owner" "$path"' "$remote"
grep -Fq 'sudo -u ubuntu test -r "$state_dir/$latest_task_id.json"' "$remote"

# Fresh evidence after a historical CONCLUIDO checkpoint must use a distinct
# successor generation; never mutate/reopen the completed predecessor.
grep -Fq 'chatgpt_freeze_task_successor' "$remote"
grep -Fq 'ChatGPT freeze task actions are restricted to the site VM' "$remote"
grep -Fq 'agent_task_state.py successor --task chatgpt-freeze-root-cause-20260928-g2 --predecessor chatgpt-freeze-root-cause-20260927' "$remote"
grep -Fq 'agent_task_state.py progress --task chatgpt-freeze-root-cause-20260928-g2' "$remote"
grep -Fq 'CHATGPT_FREEZE_SUCCESSOR_STATUS=' "$remote"
grep -Fq 'predecessor_task_id' "$remote"
if grep -Fq 'cat "$state_file"' "$remote"; then
  echo "task-state readback must not print the raw durable checkpoint" >&2
  exit 1
fi
python3 - "$remote" <<'PY'
from pathlib import Path
import sys

lines = Path(sys.argv[1]).read_text(encoding="utf-8").splitlines()
needle = 'python3 - "$state_file" "$canonical_task_id" <<\'PY\''
start = next((i for i, line in enumerate(lines) if needle in line), None)
if start is None:
    raise SystemExit("task-state sanitizer heredoc command missing")
command_indent = len(lines[start]) - len(lines[start].lstrip())
first_body = next((i for i in range(start + 1, len(lines)) if lines[i].strip()), None)
end = next((i for i in range(start + 1, len(lines)) if lines[i].strip() == "PY"), None)
if first_body is None or end is None:
    raise SystemExit("task-state sanitizer heredoc body/terminator missing")
body_indent = len(lines[first_body]) - len(lines[first_body].lstrip())
end_indent = len(lines[end]) - len(lines[end].lstrip())
if not (body_indent < command_indent and end_indent == body_indent):
    raise SystemExit(
        f"task-state sanitizer heredoc must dedent body/terminator below shell command: "
        f"command={command_indent} body={body_indent} terminator={end_indent}"
    )
PY
if grep -Fq 'readlink -f /home/ubuntu/.local/bin/shopvivaliz-browser-chromium' "$remote"; then
  echo "diagnostic browser export must not derive a directory from a mutable symlink target" >&2
  exit 1
fi

grep -Fq "mode: 'passive_only'" "$diag"
grep -Fq 'automated_prompt_submission: false' "$diag"
grep -Fq 'blocker: null' "$diag"
grep -Fq 'ok: true' "$diag"

if grep -Eq 'fillAndSend|Responda apenas: TESTE-OK|composer\.press\(.Enter.|send-button|connectOverCDP|launchPersistentContext' "$diag"; then
  echo "diagnostic script must remain passive; continuity automation is handled by the dedicated bridge" >&2
  exit 1
fi
if grep -Eq 'mkdtemp|profile-[A-Za-z0-9]|chromium\.launch\(' "$diag"; then
  echo "diagnostic must reuse only the canonical persistent ChatGPT profile" >&2
  exit 1
fi
if grep -Eiq 'authorization|set-cookie|document\.cookie|localStorage|sessionStorage' "$diag"; then
  echo "diagnostic must not read or emit auth/session secrets" >&2
  exit 1
fi

echo "CHATGPT_ACCOUNT_DIAGNOSTIC_CONTRACT=PASS"
