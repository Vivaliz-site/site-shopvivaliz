#!/bin/bash
# Install ShopVivaliz 24/7 Audit Monitor on VM
set -e

echo "📦 Installing 24/7 Audit Monitor..."

REPO_PATH="/home/ubuntu/site-shopvivaliz"
SYSTEMD_PATH="/etc/systemd/system"

# 1. Instalar Python dependencies
echo "  → Instalando Python dependencies..."
pip3 install -q requests || pip install -q requests

# 2. Copiar systemd files
echo "  → Copiando systemd files..."
sudo cp "$REPO_PATH/deploy/systemd/shopvivaliz-audit-monitor.service" "$SYSTEMD_PATH/"
sudo cp "$REPO_PATH/deploy/systemd/shopvivaliz-audit-monitor.timer" "$SYSTEMD_PATH/"

# 3. Recarregar systemd
echo "  → Recarregando systemd..."
sudo systemctl daemon-reload

# 4. Ativar timer
echo "  → Ativando timer (inicia em 5 min, depois a cada 30 min)..."
sudo systemctl enable shopvivaliz-audit-monitor.timer
sudo systemctl start shopvivaliz-audit-monitor.timer

# 5. Status
echo ""
echo "✅ Audit Monitor instalado!"
echo ""
sudo systemctl status shopvivaliz-audit-monitor.timer --no-pager || true
echo ""
echo "📊 Logs: tail -f $REPO_PATH/logs/audit-monitor-service.log"
echo "📋 Health: cat $REPO_PATH/logs/health-status-latest.json"
echo "🔍 Audit log: tail -f $REPO_PATH/logs/audit-24-7-\$(date +%Y-%m-%d).log"
