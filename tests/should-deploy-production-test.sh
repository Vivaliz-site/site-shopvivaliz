#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
script="$root/scripts/should-deploy-production.sh"

check() {
  local expected="$1"
  shift
  local actual
  actual="$(printf '%s\n' "$@" | bash "$script")"
  if [ "$actual" != "$expected" ]; then
    echo "expected=$expected actual=$actual paths=$*" >&2
    exit 1
  fi
}

# Every merged main revision must advance the immutable production release,
# including policy/docs/workflow/test-only commits. Runtime rsync exclusions
# are independent from release-SHA parity.
check true AGENTS.md
check true REGRAS-AGENTES-CENTRALIZADAS.md
check true CLAUDE.md
check true GEMINI.md
check true README.md
check true docs/VM-SSH-ACCESS.md
check true docs/knowledge/agent-rules.md
check true .codex/config.toml
check true .github/workflows/foo.yml
check true tests/unit-test.php
check true index.php
check true scripts/runtime-worker.php
check true README.md api/health/version.php
check true

echo 'OK production deploy parity classifier'
