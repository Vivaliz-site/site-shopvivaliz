#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
installer="$root/ops/ai-squad/install-codex-bridge-user-service.sh"

test -f "$installer"
bash -n "$installer"

grep -Fq 'shopvivaliz-squad-codex-bridge.service' "$installer"
grep -Fq '127.0.0.1' "$root/ops/ai-squad/codex-bridge.mjs"
grep -Fq '/health' "$installer"
grep -Fq 'systemctl --user daemon-reload' "$installer"
grep -Fq 'systemctl --user enable --now' "$installer"
grep -Fq 'AI_SQUAD_CODEX_REAL=' "$installer"
grep -Fq 'AI_SQUAD_CODEX_BUSINESS_HOME=' "$installer"
grep -Fq 'ExecStart=' "$installer"

if grep -Eq 'auth\.json|OPENAI_API_KEY|CODEX_API_KEY' "$installer"; then
  echo "FAIL: installer must not copy or expose auth/key material" >&2
  exit 1
fi

echo 'AI_SQUAD_CODEX_BRIDGE_INSTALL_TEST=PASS'
