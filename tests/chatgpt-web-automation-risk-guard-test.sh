#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
agents="$root/AGENTS.md"
remote="$root/.github/workflows/shopvivaliz-remote-access.yml"
direct="$root/.github/workflows/chatgpt-account-diagnostic-direct.yml"
diag="$root/scripts/chatgpt-account-diagnostic.mjs"

grep -Fq 'CHATGPT_WEB_AUTOMATION_RISK_GUARD_V1' "$agents"
grep -Fq 'automated ChatGPT Web prompt submission is prohibited' "$agents"
grep -Fq 'Codex remains an explicit last option' "$agents"
grep -Fq 'OpenAI Support Case #15426555' "$agents"
grep -Fq 'temporary usage restriction' "$agents"

if grep -Fq 'chatgpt_account_diag' "$remote"; then
  echo "remote access must not expose automated ChatGPT Web diagnostics" >&2
  exit 1
fi

if [ -f "$direct" ] && grep -Eq 'issue_comment|Responda apenas: TESTE-OK|chatgpt-account-diagnostic\.mjs' "$direct"; then
  echo "direct workflow must not auto-submit ChatGPT Web turns" >&2
  exit 1
fi

if [ -f "$diag" ] && grep -Eq 'fillAndSend|Responda apenas: TESTE-OK|composer\.press\(.Enter.|send-button' "$diag"; then
  echo "diagnostic script must not auto-submit ChatGPT Web turns" >&2
  exit 1
fi

echo "CHATGPT_WEB_AUTOMATION_RISK_GUARD=PASS"
