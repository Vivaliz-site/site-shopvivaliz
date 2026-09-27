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

grep -Fq 'CHATGPT_WEB_AUTOMATION_RISK_GUARD_V1' "$agents"
grep -Fq 'automated ChatGPT Web prompt submission is prohibited' "$agents"
grep -Fq 'temporary usage restriction' "$agents"

if grep -Fq 'chatgpt_account_diag' "$remote"; then
  echo "remote access must not expose automated ChatGPT Web diagnostics" >&2
  exit 1
fi
if grep -Fq 'readlink -f /home/ubuntu/.local/bin/shopvivaliz-browser-chromium' "$remote"; then
  echo "diagnostic browser export must not derive a directory from a mutable symlink target" >&2
  exit 1
fi

grep -Fq "mode: 'passive_only'" "$diag"
grep -Fq 'automated_prompt_submission: false' "$diag"
grep -Fq "blocker: 'chatgpt_web_automation_risk_guard_active'" "$diag"

if grep -Eq 'fillAndSend|Responda apenas: TESTE-OK|composer\.press\(.Enter.|send-button|connectOverCDP|launchPersistentContext' "$diag"; then
  echo "diagnostic script must not submit or prepare automated ChatGPT Web turns" >&2
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
