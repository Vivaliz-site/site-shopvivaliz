#!/usr/bin/env bash
set -Eeuo pipefail
REPO=/home/ubuntu/shopvivaliz-deploy/repo
SCRIPT="$REPO/scripts/desktop-commander-four-host-sentinel.sh"
SERVICE="$REPO/ops/systemd/shopvivaliz-dc-four-host-sentinel.service"
TIMER="$REPO/ops/systemd/shopvivaliz-dc-four-host-sentinel.timer"

test -f "$SCRIPT"
test -f "$SERVICE"
test -f "$TIMER"
test -f /home/ubuntu/.ssh/shopvivaliz-free-a1-monitor
test -f /home/ubuntu/.ssh/known_hosts

sudo install -d -m 755 /usr/local/lib/shopvivaliz
sudo install -m 755 "$SCRIPT" /usr/local/lib/shopvivaliz/desktop-commander-four-host-sentinel.sh
sudo install -m 644 "$SERVICE" /etc/systemd/system/shopvivaliz-dc-four-host-sentinel.service
sudo install -m 644 "$TIMER" /etc/systemd/system/shopvivaliz-dc-four-host-sentinel.timer
sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-dc-four-host-sentinel.timer
sudo systemctl start shopvivaliz-dc-four-host-sentinel.service
test "$(systemctl is-active shopvivaliz-dc-four-host-sentinel.timer)" = active
test "$(systemctl is-enabled shopvivaliz-dc-four-host-sentinel.timer)" = enabled
test "$(systemctl show -p Result --value shopvivaliz-dc-four-host-sentinel.service)" = success
echo FOUR_HOST_SENTINEL_INSTALLED=true
