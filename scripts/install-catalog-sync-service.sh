#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash scripts/install-catalog-sync-service.sh" >&2
  exit 1
fi

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
unit_source="${repo_dir}/deploy/systemd/shopvivaliz-sync-products.service"
unit_target="/etc/systemd/system/shopvivaliz-sync-products.service"
token_unit_source="${repo_dir}/deploy/systemd/shopvivaliz-token-renewer.service"
token_unit_target="/etc/systemd/system/shopvivaliz-token-renewer.service"

if [[ ${repo_dir} != "/home/ubuntu/site-shopvivaliz" ]]; then
  echo "Diretório de produção inesperado: ${repo_dir}" >&2
  exit 2
fi

install -o root -g root -m 0644 "${unit_source}" "${unit_target}"
install -o root -g root -m 0644 "${token_unit_source}" "${token_unit_target}"
systemctl daemon-reload
systemctl enable --now shopvivaliz-token-renewer.service
systemctl enable --now shopvivaliz-sync-products.service
systemctl is-active --quiet shopvivaliz-token-renewer.service
systemctl is-active --quiet shopvivaliz-sync-products.service
echo "serviços de token e catálogo ativos"
