#!/usr/bin/env bash
# Instala o auto-sync no systemd somente apos materializacao e validacao.

set -euo pipefail
umask 077

if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
  echo "ERRO: execute como root com sudo." >&2
  exit 1
fi

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_NAME="shopvivaliz-sync"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
RUN_USER="${SUDO_USER:-shopvivaliz}"

echo "Setup Auto-Sync ShopVivaliz"
echo "==========================="
echo "Repositorio: $REPO_DIR"
echo "Usuario: $RUN_USER"

apt-get update -qq
apt-get install -y -qq python3 python3-pip git curl

if ! id "$RUN_USER" >/dev/null 2>&1; then
  useradd -m -s /bin/bash "$RUN_USER"
fi

chmod 755 "$REPO_DIR/scripts/auto-sincronizar.sh"
chmod 755 "$REPO_DIR/scripts/sincronizar_secrets_github.sh"

cd "$REPO_DIR"
echo "Materializando valores presentes no ambiente..."
python3 scripts/sincronizar_secrets_github.py

if [[ -f .env.local ]]; then
  chown "$RUN_USER:$RUN_USER" .env.local
  chmod 600 .env.local
fi
mkdir -p "$REPO_DIR/logs"
chown -R "$RUN_USER:$RUN_USER" "$REPO_DIR/logs"

echo "Validando secrets como usuario do servico..."
sudo -u "$RUN_USER" -H bash -c "cd '$REPO_DIR' && python3 scripts/validar_secrets.py --quick"

cat >"$SERVICE_FILE" <<EOF
[Unit]
Description=ShopVivaliz Auto Sync
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$RUN_USER
WorkingDirectory=$REPO_DIR
Environment="PATH=/usr/local/bin:/usr/bin:/bin"
ExecStart=/bin/bash $REPO_DIR/scripts/auto-sincronizar.sh
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now "$SERVICE_NAME"

if ! systemctl is-active --quiet "$SERVICE_NAME"; then
  systemctl status "$SERVICE_NAME" --no-pager || true
  echo "ERRO: servico nao ficou ativo." >&2
  exit 1
fi

echo "Servico instalado e ativo."
systemctl status "$SERVICE_NAME" --no-pager
