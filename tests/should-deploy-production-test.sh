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

check false README.md docs/VM-SSH-ACCESS.md .codex/config.toml .github/workflows/foo.yml tests/unit-test.php
check false docs/runbook.md
check false \
  scripts/check_pr_completion_policy.py \
  scripts/pr_gate_replay.py \
  scripts/pr_gate_scope.py \
  scripts/repository-governance-validate.sh \
  scripts/validate-global-audit-policy-authenticated.py \
  scripts/validate-task-continuity-enforcement.py \
  scripts/maintenance/validate_current_history_lineage.py
check true index.php
check true scripts/runtime-worker.php
check true README.md api/health/version.php
check true

echo 'OK production deploy path classifier'
