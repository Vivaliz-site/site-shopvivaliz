#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/master-production-pipeline.yml"

unsafe_restart='sudo systemctl restart shopvivaliz-token-renewer.service shopvivaliz-shopee-token-renewer.service shopvivaliz-mercadolivre-token-renewer.service shopvivaliz-queue-worker.service'
if grep -Fq "$unsafe_restart" "$WORKFLOW"; then
  echo "Master production deploy must not restart the legacy Mercado Livre renewer unconditionally" >&2
  exit 1
fi

for marker in \
  'shared_ml_token_owner()' \
  'restart_runtime_services()' \
  'systemctl disable --now "$ml_service"' \
  'if [ "$owner" = "legacy" ]; then' \
  'services+=("$ml_service")' \
  'if ! restart_runtime_services; then' \
  'restart_runtime_services || fail=1'; do
  grep -Fq "$marker" "$WORKFLOW" || {
    echo "Missing ownership-aware runtime marker: $marker" >&2
    exit 1
  }
done

grep -Fq 'if [ "$owner" = "mlrr" ]; then' "$WORKFLOW" || {
  echo "MLRR ownership branch missing from master production deployment" >&2
  exit 1
}
grep -Fq '! sudo systemctl is-active --quiet "$ml_service"' "$WORKFLOW" || {
  echo "MLRR ownership must assert the legacy renewer is inactive" >&2
  exit 1
}
grep -Fq '! sudo systemctl is-enabled --quiet "$ml_service"' "$WORKFLOW" || {
  echo "MLRR ownership must assert the legacy renewer is disabled" >&2
  exit 1
}

grep -Fq 'cmp -s "$release/deploy/systemd/shopvivaliz-mercadolivre-token-renewer.service" "$previous/deploy/systemd/shopvivaliz-mercadolivre-token-renewer.service"' "$WORKFLOW" || {
  echo "Mercado Livre unit changes are not part of catalog service reconciliation" >&2
  exit 1
}

activation="$(sed -n '/      - name: Activate release atomically/,/^  monitor:/p' "$WORKFLOW")"
grep -Fq "bash -s -- \"\$DEPLOY_SHA\" \"\$release_dir\" <<'REMOTE'" <<<"$activation" || {
  echo 'Production activation must execute locally on the A1 runner' >&2
  exit 1
}
if grep -Fq 'ubuntu@127.0.0.1' <<<"$activation"; then
  echo 'Production activation must not depend on localhost SSH' >&2
  exit 1
fi

for marker in \
  'exec 8>"$shared/locks/repo-sync.lock"' \
  'if ! flock -w 120 8; then' \
  'SAFE_SYNC_RUN_ON_INSTALL=false SOURCE_ROOT="$current" SEED_REPO="$root/repo" SYNC_ROOT="$root/sync-repo"'; do
  grep -Fq "$marker" "$WORKFLOW" || {
    echo "Canonical production deploy must serialize against Safe Sync: $marker" >&2
    exit 1
  }
done

grep -Fq "php -r 'exit(extension_loaded(\"pdo_sqlite\") ? 0 : 1);'" "$WORKFLOW" || {
  echo 'Production activation must probe pdo_sqlite without a pipefail/SIGPIPE-prone grep -q pipeline' >&2
  exit 1
}
if grep -Fq 'php -m | grep -Fxq pdo_sqlite' "$WORKFLOW"; then
  echo 'Production activation must not use php -m | grep -q under pipefail' >&2
  exit 1
fi

echo MASTER_PRODUCTION_RUNTIME_RESTART_OK
