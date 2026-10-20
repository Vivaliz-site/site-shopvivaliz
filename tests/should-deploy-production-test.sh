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
# CI policy code is NOT a deployed storefront/worker payload. Actual PHP,
# scripts/deploy-production.sh and any unknown script still demand deployment.
check false scripts/check_pr_completion_policy.py .github/workflows/pr-completion-enforcer.yml tests/test_pr_completion_enforcer_policy.py REGRA-PR-FALHOU-CORRIGIR-NA-HORA.md
check false scripts/should-deploy-production.sh tests/should-deploy-production-test.sh .github/workflows/master-production-pipeline.yml
check false scripts/check_pr_completion_policy.py scripts/should-deploy-production.sh
check true scripts/check_pr_completion_policy.py api/orders/create-validated.php
check true scripts/check_pr_completion_policy.py scripts/runtime-worker.php
check true scripts/should-deploy-production.sh scripts/deploy-production.sh
check true scripts/check_pr_completion_policy.py scripts/unrecognized-agent.py
check true index.php
check true scripts/runtime-worker.php
check true README.md api/health/version.php
check true

# Only the exact Orquestrador backend MCP patch can skip the web release.
check false \
  remote-control-mcp/server.py \
  scripts/continuity/checkpoint_update_atomic.py \
  remote-control-mcp/SPEC.md \
  tests/test_orquestrador_atomic_checkpoint.py \
  tests/remote-control-mcp-test.py \
  scripts/remote_mcp_bootstrap_scope.py \
  tests/test_remote_mcp_bootstrap_scope.py \
  .github/workflows/remote-control-mcp-bootstrap.yml \
  scripts/should-deploy-production.sh \
  scripts/setup-remote-control-access.sh \
  tests/should-deploy-production-test.sh
check true remote-control-mcp/server.py
check true scripts/continuity/checkpoint_update_atomic.py
check true remote-control-mcp/server.py scripts/continuity/checkpoint_update_atomic.py api/orders/index.php
check true remote-control-mcp/server.py scripts/continuity/checkpoint_update_atomic.py scripts/unknown-worker.py
check true remote-control-mcp/server.py scripts/continuity/checkpoint_update_atomic.py scripts/setup-remote-control-windows.ps1

echo 'OK production deploy path classifier'
