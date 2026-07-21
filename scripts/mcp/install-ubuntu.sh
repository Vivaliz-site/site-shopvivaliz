#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="${SHOPVIVALIZ_ROOT:-/var/www/shopvivaliz}"
REPO="${SHOPVIVALIZ_REPO:-https://github.com/Vivaliz-site/site-shopvivaliz.git}"
USER_NAME="${SHOPVIVALIZ_USER:-shopvivaliz}"
SERVICE_FILE="/etc/systemd/system/shopvivaliz-mcp.service"
SUDOERS_FILE="/etc/sudoers.d/shopvivaliz-mcp"

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute como root: sudo bash scripts/mcp/install-ubuntu.sh" >&2
  exit 1
fi

apt-get update
apt-get install -y git python3 curl ca-certificates openssh-server

if ! id "$USER_NAME" >/dev/null 2>&1; then
  useradd -m -s /bin/bash "$USER_NAME"
fi

if [[ ! -d "$ROOT/.git" ]]; then
  install -d -o "$USER_NAME" -g "$USER_NAME" "$(dirname "$ROOT")"
  sudo -u "$USER_NAME" git clone "$REPO" "$ROOT"
else
  sudo -u "$USER_NAME" git -C "$ROOT" fetch origin main
  sudo -u "$USER_NAME" git -C "$ROOT" pull --ff-only origin main
fi

chmod +x "$ROOT/scripts/mcp/shopvivaliz_mcp_server.py"
chown -R "$USER_NAME:$USER_NAME" "$ROOT"

cat > "$SUDOERS_FILE" <<EOF
$USER_NAME ALL=(root) NOPASSWD: /bin/systemctl status php8.3-fpm, /bin/systemctl restart php8.3-fpm, /bin/systemctl status nginx, /bin/systemctl restart nginx, /bin/systemctl status apache2, /bin/systemctl restart apache2
EOF
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE"

cat > "$SERVICE_FILE" <<EOF
[Unit]
Description=ShopVivaliz MCP stdio server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$USER_NAME
Group=$USER_NAME
WorkingDirectory=$ROOT
Environment=SHOPVIVALIZ_ROOT=$ROOT
Environment=SHOPVIVALIZ_ALLOWED_SERVICES=php8.3-fpm,nginx,apache2
ExecStart=/usr/bin/python3 $ROOT/scripts/mcp/shopvivaliz_mcp_server.py
Restart=on-failure
RestartSec=3
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true
ReadWritePaths=$ROOT

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable ssh
systemctl restart ssh
systemctl enable shopvivaliz-mcp
systemctl restart shopvivaliz-mcp

printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | sudo -u "$USER_NAME" env SHOPVIVALIZ_ROOT="$ROOT" python3 "$ROOT/scripts/mcp/shopvivaliz_mcp_server.py"
printf '%s\n' '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | sudo -u "$USER_NAME" env SHOPVIVALIZ_ROOT="$ROOT" python3 "$ROOT/scripts/mcp/shopvivaliz_mcp_server.py"

echo "MCP preparado. Use stdio por SSH a partir do Windows."
