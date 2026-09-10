#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKFLOW="$ROOT/.github/workflows/master-production-pipeline.yml"

restart_line='sudo systemctl restart shopvivaliz-token-renewer.service shopvivaliz-shopee-token-renewer.service shopvivaliz-mercadolivre-token-renewer.service shopvivaliz-queue-worker.service'
count="$(grep -Fc "$restart_line" "$WORKFLOW" || true)"
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

echo MASTER_PRODUCTION_RUNTIME_RESTART_OK
