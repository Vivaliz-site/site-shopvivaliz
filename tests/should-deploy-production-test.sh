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
check true index.php
check true scripts/runtime-worker.php
check true README.md api/health/version.php
check true

echo 'OK production deploy path classifier'
