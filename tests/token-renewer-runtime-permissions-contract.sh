#!/usr/bin/env bash
set -euo pipefail
installer='scripts/install-catalog-sync-service.sh'
unit='deploy/systemd/shopvivaliz-token-renewer.service'
grep -Fq 'shared_env=/home/ubuntu/shopvivaliz-deploy/shared/.env' "$installer"
grep -Fq 'chown ubuntu:www-data "$shared_env"' "$installer"
grep -Fq 'chmod 0640 "$shared_env"' "$installer"
grep -Fq 'systemctl restart shopvivaliz-token-renewer.service' "$installer"
grep -Fq 'systemctl restart shopvivaliz-shopee-token-renewer.service' "$installer"
grep -Fq 'User=ubuntu' "$unit"
grep -Fq 'Group=www-data' "$unit"
echo 'token-renewer-runtime-permissions-contract: ok'
