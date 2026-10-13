#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILE="$ROOT/ops/host/shopvivaliz-zram-extra"
UNIT="$ROOT/deploy/systemd/shopvivaliz-zram-extra.service"
INSTALLER="$ROOT/scripts/install-zram-extra.sh"
bash -n "$FILE"
bash -n "$INSTALLER"
check() { grep -Fq -- "$1" "$2" || { echo "FAIL: expected $1 in $2"; exit 1; }; }
check 'DEVICE=/dev/zram1' "$FILE"
check 'SIZE_BYTES=2147483648' "$FILE"
check 'PRIORITY=80' "$FILE"
check 'ZRAM_EXTRA=already_active' "$FILE"
check 'refusing to overwrite' "$FILE"
check "echo lz4 > /sys/block/zram1/comp_algorithm" "$FILE"
check 'After=shopvivaliz-zram.service' "$UNIT"
check 'Requires=shopvivaliz-zram.service' "$UNIT"
check 'TimeoutStartSec=30s' "$UNIT"
check 'systemctl enable --now shopvivaliz-zram-extra.service' "$INSTALLER"
if grep -Eq 'swapoff|/dev/zram0|echo 1 > /sys/block/zram1/reset' "$FILE" "$INSTALLER" "$UNIT"; then
  echo 'FAIL: extra zram must never stop, reset or modify pre-existing swap'; exit 1
fi
echo 'ZRAM_EXTRA_CONTRACT=PASS'
