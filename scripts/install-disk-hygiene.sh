#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

install -o root -g root -m 0755 "$ROOT/ops/host/shopvivaliz-disk-guard" /usr/local/sbin/shopvivaliz-disk-guard
install -o root -g root -m 0755 "$ROOT/ops/host/shopvivaliz-workspace-housekeeper" /usr/local/sbin/shopvivaliz-workspace-housekeeper

for unit in \
  shopvivaliz-disk-guard.service \
  shopvivaliz-disk-guard.timer \
  shopvivaliz-workspace-housekeeper.service \
  shopvivaliz-workspace-housekeeper.timer
do
  install -o root -g root -m 0644 "$ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"
done

bash -n /usr/local/sbin/shopvivaliz-workspace-housekeeper
sh -n /usr/local/sbin/shopvivaliz-disk-guard
systemd-analyze verify \
  /etc/systemd/system/shopvivaliz-disk-guard.service \
  /etc/systemd/system/shopvivaliz-disk-guard.timer \
  /etc/systemd/system/shopvivaliz-workspace-housekeeper.service \
  /etc/systemd/system/shopvivaliz-workspace-housekeeper.timer

systemctl daemon-reload
systemctl enable --now shopvivaliz-disk-guard.timer shopvivaliz-workspace-housekeeper.timer
systemctl is-active --quiet shopvivaliz-disk-guard.timer
systemctl is-active --quiet shopvivaliz-workspace-housekeeper.timer
