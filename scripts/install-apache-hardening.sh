#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash scripts/install-apache-hardening.sh" >&2
  exit 1
fi

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [[ ${repo_dir} != "/home/ubuntu/site-shopvivaliz" ]]; then
  echo "Diretório de produção inesperado: ${repo_dir}" >&2
  exit 2
fi

install -o root -g root -m 0644 \
  "${repo_dir}/deploy/apache/shopvivaliz-private-paths.conf" \
  /etc/apache2/conf-available/shopvivaliz-private-paths.conf
a2enmod headers expires >/dev/null
a2enconf shopvivaliz-private-paths >/dev/null
apache2ctl configtest
systemctl reload apache2
systemctl is-active --quiet apache2
echo "Apache hardening ativo"
