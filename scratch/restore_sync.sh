#!/usr/bin/env bash
set -euo pipefail

cd /home/ubuntu/site-shopvivaliz

echo "Stopping existing auto-sync service/timer..."
sudo systemctl stop shopvivaliz-auto-sync.service 2>/dev/null || true
sudo systemctl stop shopvivaliz-auto-sync.timer 2>/dev/null || true

echo "Creating runtime backup..."
mkdir -p ~/shopvivaliz-runtime-backup
git diff > ~/shopvivaliz-runtime-backup/local-changes.patch || true
git status --short > ~/shopvivaliz-runtime-backup/status.txt || true

echo "Stashing runtime changes..."
git stash push -u -m "runtime VM antes de restaurar auto-sync" || true

echo "Fetching and resetting to origin/main..."
git fetch origin main
git checkout main
git reset --hard origin/main

echo "Creating new shopvivaliz-auto-sync.sh..."
sudo tee /usr/local/bin/shopvivaliz-auto-sync.sh >/dev/null <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

REPO="/home/ubuntu/site-shopvivaliz"
LOG="/var/log/shopvivaliz-auto-sync.log"

cd "$REPO"

echo "[$(date '+%F %T')] iniciando" >> "$LOG"

git fetch origin main >> "$LOG" 2>&1

LOCAL="$(git rev-parse HEAD)"
REMOTE="$(git rev-parse origin/main)"

if [ "$LOCAL" = "$REMOTE" ]; then
    echo "[$(date '+%F %T')] já atualizado: $LOCAL" >> "$LOG"
    exit 0
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "[$(date '+%F %T')] bloqueado: working tree suja" >> "$LOG"
    exit 1
fi

git merge --ff-only origin/main >> "$LOG" 2>&1
echo "[$(date '+%F %T')] atualizado para $REMOTE" >> "$LOG"
EOF

sudo chmod +x /usr/local/bin/shopvivaliz-auto-sync.sh

echo "Creating service and timer..."
sudo tee /etc/systemd/system/shopvivaliz-auto-sync.service >/dev/null <<'EOF'
[Unit]
Description=Sincronização segura ShopVivaliz
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=ubuntu
WorkingDirectory=/home/ubuntu/site-shopvivaliz
ExecStart=/usr/local/bin/shopvivaliz-auto-sync.sh
EOF

sudo tee /etc/systemd/system/shopvivaliz-auto-sync.timer >/dev/null <<'EOF'
[Unit]
Description=Executa sincronização ShopVivaliz a cada 2 minutos

[Timer]
OnBootSec=30s
OnUnitActiveSec=2min
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
EOF

echo "Reloading systemd, enabling and starting services..."
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-auto-sync.timer
# Initialize log file
sudo touch /var/log/shopvivaliz-auto-sync.log
sudo chmod 666 /var/log/shopvivaliz-auto-sync.log
sudo systemctl start shopvivaliz-auto-sync.service || true

echo "Verification:"
systemctl status shopvivaliz-auto-sync.timer --no-pager
systemctl status shopvivaliz-auto-sync.service --no-pager
tail -50 /var/log/shopvivaliz-auto-sync.log
git rev-parse HEAD
git rev-parse origin/main
