#!/usr/bin/env bash
set -euo pipefail
installer='scripts/install-catalog-sync-service.sh'
unit='deploy/systemd/shopvivaliz-token-renewer.service'
grep -Fq 'install -o ubuntu -g www-data -m 0640 "$shared_env" "$shared_env"' "$installer"
grep -Fq 'systemctl restart shopvivaliz-token-renewer.service' "$installer"
grep -Fq 'systemctl restart shopvivaliz-shopee-token-renewer.service' "$installer"
grep -Fq 'User=ubuntu' "$unit"
grep -Fq 'Group=www-data' "$unit"
echo 'token-renewer-runtime-permissions-contract: ok'
