#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/master-production-pipeline.yml"

restart_line='sudo systemctl restart shopvivaliz-token-renewer.service shopvivaliz-shopee-token-renewer.service shopvivaliz-mercadolivre-token-renewer.service shopvivaliz-queue-worker.service'
count="$(awk -v needle="$restart_line" 'index($0, needle) { count++ } END { print count + 0 }' "$WORKFLOW")"
if [ "$count" -lt 2 ]; then
  echo "Master production deploy must restart all long-running runtime services after activation and rollback; found $count restart points" >&2
  exit 1
fi

for service in shopvivaliz-token-renewer.service shopvivaliz-shopee-token-renewer.service shopvivaliz-mercadolivre-token-renewer.service shopvivaliz-queue-worker.service; do
  grep -Fq "sudo systemctl is-active --quiet $service" "$WORKFLOW" || {
    echo "Missing post-restart health assertion for $service" >&2
    exit 1
  }
done

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


grep -Fq "php -r 'exit(extension_loaded(\"pdo_sqlite\") ? 0 : 1);'" "$WORKFLOW" || {
  echo 'Production activation must probe pdo_sqlite without a pipefail/SIGPIPE-prone grep -q pipeline' >&2
  exit 1
}
if grep -Fq 'php -m | grep -Fxq pdo_sqlite' "$WORKFLOW"; then
  echo 'Production activation must not use php -m | grep -q under pipefail' >&2
  exit 1
fi

echo MASTER_PRODUCTION_RUNTIME_RESTART_OK
