#!/usr/bin/env bash
# Instalador fail-closed para Ubuntu/Cloud.

set -euo pipefail
umask 077

echo "ShopVivaliz Setup Seguro"
echo "========================"

if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  OS="$ID"
else
  echo "ERRO: nao foi possivel detectar o sistema operacional." >&2
  exit 1
fi

echo "Sistema detectado: $OS"

if [[ ! -d site-shopvivaliz ]]; then
  git clone https://github.com/Vivaliz-site/site-shopvivaliz.git
  cd site-shopvivaliz
else
  cd site-shopvivaliz
  git pull --ff-only origin main
fi

case "$OS" in
  ubuntu|debian)
    sudo apt-get update -qq
    sudo apt-get install -y -qq python3 python3-pip git curl
    ;;
  centos|rhel|fedora)
    sudo yum install -y -q python3 python3-pip git curl
    ;;
  alpine)
    sudo apk add --no-cache python3 py3-pip git curl
    ;;
  *)
    echo "ERRO: sistema operacional nao suportado automaticamente: $OS" >&2
    exit 1
    ;;
esac

echo "Materializando e validando secrets..."
bash scripts/bootstrap.sh

if ! command -v systemctl >/dev/null 2>&1; then
  echo "ERRO: systemd nao esta disponivel; configure o auto-sync manualmente apos a validacao." >&2
  exit 1
fi

echo "Instalando servico de auto-sync..."
sudo bash scripts/setup-auto-sync-linux.sh

if ! systemctl is-active --quiet shopvivaliz-sync; then
  echo "ERRO: shopvivaliz-sync nao ficou ativo apos a instalacao." >&2
  exit 1
fi

echo "Setup concluido com configuracao validada e servico ativo."
systemctl status shopvivaliz-sync --no-pager
