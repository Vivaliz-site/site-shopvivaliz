#!/usr/bin/env bash
set -Eeuo pipefail
SRC="${1:-remote-control-mcp/controller.py}"
install -d -m 755 /opt/shopvivaliz-remote
install -m 755 "$SRC" /opt/shopvivaliz-remote/controller.py
install -d -m 700 /var/lib/shopvivaliz-remote
cat >/etc/systemd/system/shopvivaliz-remote-control.service <<'EOF'
[Unit]
Description=ShopVivaliz private remote control API
After=network-online.target
[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/shopvivaliz-remote/controller.py
Restart=always
RestartSec=2
User=root
NoNewPrivileges=false
[Install]
WantedBy=multi-user.target
EOF
cat >/etc/systemd/system/shopvivaliz-remote-worker.service <<'EOF'
[Unit]
Description=ShopVivaliz durable remote task worker
After=network-online.target shopvivaliz-remote-control.service
[Service]
Type=simple
ExecStart=/usr/bin/python3 /opt/shopvivaliz-remote/controller.py --worker
Restart=always
RestartSec=2
User=root
NoNewPrivileges=false
[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable --now shopvivaliz-remote-control.service shopvivaliz-remote-worker.service
curl -fsS http://127.0.0.1:5560/health
