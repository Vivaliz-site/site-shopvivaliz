#!/usr/bin/env bash
# Installs the hourly read-only Marina MCP edge smoke timer from the deployed release.
# Idempotent: re-running reinstalls the same units. Requires sudo for /etc/systemd/system only.
set -euo pipefail

RELEASE="${SHOPVIVALIZ_RELEASE:-/home/ubuntu/shopvivaliz-deploy/current}"
UNIT_DIR=/etc/systemd/system
UNITS=(shopvivaliz-marina-edge-smoke.service shopvivaliz-marina-edge-smoke.timer)

for unit in "${UNITS[@]}"; do
  test -f "$RELEASE/deploy/systemd/$unit" || { echo "missing $unit in $RELEASE" >&2; exit 2; }
  sudo install -m 644 "$RELEASE/deploy/systemd/$unit" "$UNIT_DIR/$unit"
done
test -f "$RELEASE/scripts/marina_mcp_edge_smoke.py" || { echo "missing smoke script in $RELEASE" >&2; exit 2; }

sudo systemctl daemon-reload
sudo systemctl enable --now shopvivaliz-marina-edge-smoke.timer
systemctl is-enabled shopvivaliz-marina-edge-smoke.timer
systemctl list-timers shopvivaliz-marina-edge-smoke.timer --no-legend | head -n 1
