#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash scripts/install-catalog-sync-service.sh" >&2
  exit 1
fi

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
token_unit_source="${repo_dir}/deploy/systemd/shopvivaliz-token-renewer.service"
token_unit_target="/etc/systemd/system/shopvivaliz-token-renewer.service"
shopee_unit_source="${repo_dir}/deploy/systemd/shopvivaliz-shopee-token-renewer.service"
shopee_unit_target="/etc/systemd/system/shopvivaliz-shopee-token-renewer.service"
ml_unit_source="${repo_dir}/deploy/systemd/shopvivaliz-mercadolivre-token-renewer.service"
ml_unit_target="/etc/systemd/system/shopvivaliz-mercadolivre-token-renewer.service"
reconcile_service_source="${repo_dir}/deploy/systemd/shopvivaliz-catalog-reconcile.service"
reconcile_service_target="/etc/systemd/system/shopvivaliz-catalog-reconcile.service"
reconcile_timer_source="${repo_dir}/deploy/systemd/shopvivaliz-catalog-reconcile.timer"
reconcile_timer_target="/etc/systemd/system/shopvivaliz-catalog-reconcile.timer"
shared_env=/home/ubuntu/shopvivaliz-deploy/shared/.env

if [[ ${repo_dir} != /home/ubuntu/shopvivaliz-deploy/* ]]; then
  echo "Diretório de produção inesperado: ${repo_dir}" >&2
  exit 2
fi

if [[ -f "$shared_env" ]]; then
  chown ubuntu:www-data "$shared_env"
  chmod 0640 "$shared_env"
fi
install -o root -g root -m 0644 "${token_unit_source}" "${token_unit_target}"
install -o root -g root -m 0644 "${shopee_unit_source}" "${shopee_unit_target}"
install -o root -g root -m 0644 "${ml_unit_source}" "${ml_unit_target}"
install -o root -g root -m 0644 "${reconcile_service_source}" "${reconcile_service_target}"
install -o root -g root -m 0644 "${reconcile_timer_source}" "${reconcile_timer_target}"
systemd-analyze verify "${token_unit_target}" "${shopee_unit_target}" "${ml_unit_target}" "${reconcile_service_target}" "${reconcile_timer_target}"
systemctl daemon-reload
systemctl enable shopvivaliz-token-renewer.service
systemctl enable shopvivaliz-shopee-token-renewer.service
systemctl enable shopvivaliz-mercadolivre-token-renewer.service
systemctl restart shopvivaliz-token-renewer.service
systemctl restart shopvivaliz-shopee-token-renewer.service
systemctl restart shopvivaliz-mercadolivre-token-renewer.service
systemctl enable --now shopvivaliz-catalog-reconcile.timer
if systemctl list-unit-files shopvivaliz-sync-products.service --no-legend 2>/dev/null | grep -q '^shopvivaliz-sync-products\.service'; then
  systemctl disable --now shopvivaliz-sync-products.service
fi
systemctl is-active --quiet shopvivaliz-token-renewer.service
systemctl is-active --quiet shopvivaliz-shopee-token-renewer.service
systemctl is-active --quiet shopvivaliz-mercadolivre-token-renewer.service
systemctl is-active --quiet shopvivaliz-catalog-reconcile.timer
systemctl is-enabled --quiet shopvivaliz-catalog-reconcile.timer
echo "servicos OAuth ativos; reconciliacao automatica de catalogo habilitada; daemon legado desabilitado"
