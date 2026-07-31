#!/bin/bash
set -e
echo "📦 Installing 24/7 Audit Monitor..."
pip3 install -q requests
sudo cp /home/ubuntu/site-shopvivaliz/deploy/systemd/shopvivaliz-audit-monitor.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable shopvivaliz-audit-monitor.timer
sudo systemctl start shopvivaliz-audit-monitor.timer
echo "✅ Audit Monitor installed!"
