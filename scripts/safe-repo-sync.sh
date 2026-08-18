#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SHARED_ROOT="${SHARED_ROOT:-/home/ubuntu/shopvivaliz-deploy/shared}"
PYTHON_BIN="${PYTHON_BIN:-/usr/bin/python3}"
RUNNER_PATH="${SYNC_RUNNER_PATH:-$ROOT/git-auto-sync.py}"
REPO_STATUS="$ROOT/logs/tri-environment-sync.json"
SHARED_LOG_DIR="$SHARED_ROOT/logs"
SHARED_LOCK_DIR="$SHARED_ROOT/locks"
SHARED_STATUS="$SHARED_LOG_DIR/tri-environment-sync.json"
SHARED_LEGACY_STATUS="$SHARED_LOG_DIR/autonomous-sync.json"
CURRENT_ROOT="${CURRENT_ROOT:-/home/ubuntu/shopvivaliz-deploy/current}"
DEPLOY_RUNNER="$ROOT/scripts/deploy-production.sh"
SYNC_LOCK="$SHARED_LOCK_DIR/repo-sync.lock"
INDEX_LOCK="$ROOT/.git/index.lock"
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

mkdir -p "$ROOT/logs" "$SHARED_LOG_DIR" "$SHARED_LOCK_DIR"
exec 9>"$SYNC_LOCK"
if ! flock -w 120 9; then
  echo "Timeout aguardando lock exclusivo de sincronizacao: $SYNC_LOCK" >&2
  exit 4
fi

if [ -e "$INDEX_LOCK" ]; then
  now="$(date +%s)"
  modified="$(stat -c %Y "$INDEX_LOCK")"
  age=$((now - modified))

  if [ "$age" -lt 120 ]; then
    echo "Git index lock recente detectado (${age}s); sincronizacao abortada com seguranca" >&2
    exit 5
  fi

  if ! command -v fuser >/dev/null 2>&1; then
    echo "fuser indisponivel; recusando remover Git index lock sem verificar uso ativo" >&2
    exit 6
  fi

  if fuser "$INDEX_LOCK" >/dev/null 2>&1; then
    echo "Git index lock esta em uso por processo ativo; sincronizacao abortada" >&2
    exit 7
  fi

  rm -f -- "$INDEX_LOCK"
  echo "Git index lock obsoleto removido com seguranca (${age}s): $INDEX_LOCK"
fi

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

if [ "$exit_code" -ne 0 ]; then
  exit "$exit_code"
fi

repo_sha="$(git -C "$ROOT" rev-parse HEAD)"
active_sha="$(cat "$CURRENT_ROOT/.release-sha" 2>/dev/null || true)"
if [ "$repo_sha" = "$active_sha" ]; then
  echo "Release ativa ja corresponde ao clone sincronizado: $repo_sha"
  exit 0
fi

if [ ! -x "$DEPLOY_RUNNER" ]; then
  echo "Runner de deploy ausente ou nao executavel: $DEPLOY_RUNNER" >&2
  exit 8
fi

echo "Clone sincronizado em $repo_sha; publicando release imutavel correspondente"
exec "$DEPLOY_RUNNER" main
