#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
install -o root -g root -m 0755 "$ROOT/ops/host/shopvivaliz-zram-extra" /usr/local/sbin/shopvivaliz-zram-extra
install -o root -g root -m 0644 "$ROOT/deploy/systemd/shopvivaliz-zram-extra.service" /etc/systemd/system/shopvivaliz-zram-extra.service
bash -n /usr/local/sbin/shopvivaliz-zram-extra
systemd-analyze verify /etc/systemd/system/shopvivaliz-zram-extra.service
systemctl daemon-reload
systemctl enable --now shopvivaliz-zram-extra.service
systemctl is-active --quiet shopvivaliz-zram-extra.service
grep -Eq '^/dev/zram1[[:space:]]' /proc/swaps
echo "ZRAM_EXTRA_INSTALL=PASS"
