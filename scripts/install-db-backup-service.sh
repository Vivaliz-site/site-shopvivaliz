#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install -d -o ubuntu -g ubuntu -m 0755 /home/ubuntu/.local/bin
install -o ubuntu -g ubuntu -m 0755 "$ROOT/ops/db_backup.py" /home/ubuntu/.local/bin/shopvivaliz-db-backup.py
install -o root -g root -m 0755 "$ROOT/ops/host/shopvivaliz-db-backup" /usr/local/sbin/shopvivaliz-db-backup
install -o root -g root -m 0644 "$ROOT/deploy/systemd/shopvivaliz-db-backup.service" /etc/systemd/system/shopvivaliz-db-backup.service
install -o root -g root -m 0644 "$ROOT/deploy/systemd/shopvivaliz-db-backup.timer" /etc/systemd/system/shopvivaliz-db-backup.timer
systemctl daemon-reload
systemctl enable --now shopvivaliz-db-backup.timer
