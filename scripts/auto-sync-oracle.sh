#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CANONICAL_RUNNER="$SCRIPT_ROOT/scripts/safe-repo-sync.sh"

if [ ! -r "$CANONICAL_RUNNER" ]; then
  echo "Runner canonico de sync ausente ou ilegivel: $CANONICAL_RUNNER" >&2
  exit 2
fi

export ROOT
export SHARED_ROOT
# Releases imutaveis nao possuem .git. O runner Python precisa viver no clone
# operacional em $ROOT para executar git fetch/status contra o repositorio real.
export SYNC_RUNNER_PATH="${SYNC_RUNNER_PATH:-$ROOT/git-auto-sync.py}"
exec /usr/bin/bash "$CANONICAL_RUNNER"
