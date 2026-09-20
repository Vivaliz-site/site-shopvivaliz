#!/usr/bin/env bash
set -Eeuo pipefail

EXPECTED_HOST=shopvivaliz-free-a1
MLRR_REPO=/home/ubuntu/mercadolivre-returns-recovery
STATE_DIR="$HOME/.local/state/shopvivaliz"
KEY_FILE="$HOME/.config/shopvivaliz/execution-provenance.key"

test "$(hostname)" = "$EXPECTED_HOST"
test -d "$MLRR_REPO/.git"
test -s "$KEY_FILE"

export CHAT_CLI_SESSION_ID="${CHAT_CLI_SESSION_ID:-remote-mlrr-preflight-rollout}"
export EXECUTION_AGENT="${EXECUTION_AGENT:-chatgpt-gpt-5.6-sol}"
export EXECUTION_TARGET="$EXPECTED_HOST"
export EXECUTION_ORIGIN_TYPE=github-remote-access
export EXECUTION_SESSION_ID="$CHAT_CLI_SESSION_ID"
export EXECUTION_REPOSITORY=Vivaliz-site/mercadolivre-returns-recovery
export EXECUTION_REF=refs/heads/main
export EXECUTION_PROVENANCE_HMAC_KEY="$(cat "$KEY_FILE")"
export EXECUTION_PROVENANCE_KEY_ID=shopvivaliz-host-provenance-v1

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

cd "$MLRR_REPO"
test -z "$(git status --porcelain)"
git fetch origin main --quiet
git switch main
git merge --ff-only origin/main
sha="$(git rev-parse HEAD)"
test "$sha" = "$(git rev-parse origin/main)"

export GITHUB_WORKSPACE="$MLRR_REPO"
export GITHUB_SHA="$sha"
export GITHUB_REF_NAME=main
export EXECUTION_SHA="$sha"

provenance() {
  local result="$1" code="$2"
  EXECUTION_RESULT="$result" EXECUTION_EXIT_CODE="$code" \
    python3 "$MLRR_REPO/scripts/emit-execution-provenance.py" \
      mlrr_remote_preflight_rollout --target "$EXPECTED_HOST" \
      >> "$STATE_DIR/execution-provenance.jsonl"
}

cleanup() {
  local rc=$?
  if [ "$rc" -eq 0 ]; then
    provenance COMPLETED 0
  else
    provenance FAILED "$rc" || true
  fi
  unset EXECUTION_PROVENANCE_HMAC_KEY
  exit "$rc"
}
trap cleanup EXIT

echo "REMOTE_MLRR_PREFLIGHT_ROLLOUT=START"
echo "SUPERPOWERS_UNAVAILABLE=true"
echo "mlrr_sha=$sha"
provenance STARTED ""

test -x scripts/mlrr-production-ops.sh
scripts/mlrr-production-ops.sh prepare
scripts/mlrr-production-ops.sh shadow
scripts/mlrr-production-ops.sh validate
scripts/mlrr-production-ops.sh preflight

trap - EXIT
provenance COMPLETED 0
unset EXECUTION_PROVENANCE_HMAC_KEY
echo "REMOTE_MLRR_PREFLIGHT_ROLLOUT=PASS"
