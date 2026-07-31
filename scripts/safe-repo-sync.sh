#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
PYTHON_BIN="${PYTHON_BIN:-/usr/bin/python3}"
RUNNER_PATH="${SYNC_RUNNER_PATH:-$ROOT/git-auto-sync.py}"
REPO_STATUS="$ROOT/logs/tri-environment-sync.json"
SHARED_LOG_DIR="$SHARED_ROOT/logs"
SHARED_STATUS="$SHARED_LOG_DIR/tri-environment-sync.json"
SHARED_LEGACY_STATUS="$SHARED_LOG_DIR/autonomous-sync.json"
TMP_OUTPUT="$(mktemp)"

cleanup() {
  rm -f -- "$TMP_OUTPUT"
}
trap cleanup EXIT

if [ ! -d "$ROOT/.git" ]; then
  echo "Repositorio Git nao encontrado: $ROOT" >&2
  exit 2
fi

if [ ! -f "$RUNNER_PATH" ]; then
  echo "Runner de sync nao encontrado: $RUNNER_PATH" >&2
  exit 3
fi

mkdir -p "$ROOT/logs" "$SHARED_LOG_DIR"
cd "$ROOT"

set +e
SHOPVIVALIZ_REPO_DIR="$ROOT" "$PYTHON_BIN" "$RUNNER_PATH" >"$TMP_OUTPUT" 2>&1
exit_code=$?
set -e

cat "$TMP_OUTPUT"

if [ -f "$REPO_STATUS" ]; then
  cp -f -- "$REPO_STATUS" "$SHARED_STATUS"
  cp -f -- "$REPO_STATUS" "$SHARED_LEGACY_STATUS"
fi

exit "$exit_code"
