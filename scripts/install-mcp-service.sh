#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash scripts/install-mcp-service.sh" >&2
  exit 1
fi

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
unit_source="${repo_dir}/deploy/systemd/shopvivaliz-mcp.service"
unit_target="/etc/systemd/system/shopvivaliz-mcp.service"

case "${repo_dir}" in
  /home/ubuntu/shopvivaliz-deploy/*) ;;
  *)
    echo "repo_dir inesperado para producao: ${repo_dir}" >&2
    exit 2
    ;;
esac

install -o root -g root -m 0644 "${unit_source}" "${unit_target}"
systemd-analyze verify "${unit_target}"
systemctl daemon-reload

if systemctl is-active --quiet mcp-universal.service 2>/dev/null; then
  systemctl disable --now shopvivaliz-mcp.service
  systemctl reset-failed shopvivaliz-mcp.service
  systemctl is-active --quiet mcp-universal.service
  echo "mcp-universal.service ativo; shopvivaliz-mcp.service mantido desligado para evitar conflito de porta"
  exit 0
fi

systemctl enable --now shopvivaliz-mcp.service
systemctl is-active --quiet shopvivaliz-mcp.service
echo "shopvivaliz-mcp.service ativo"
