#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
CANONICAL_RUNNER="$ROOT/scripts/safe-repo-sync.sh"

if [ ! -x "$CANONICAL_RUNNER" ]; then
  echo "Runner canonico de sync ausente ou sem permissao: $CANONICAL_RUNNER" >&2
  exit 2
fi

export ROOT
export SHARED_ROOT
exec "$CANONICAL_RUNNER"
