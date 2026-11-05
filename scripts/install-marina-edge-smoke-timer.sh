#!/usr/bin/env bash
# Installs the hourly read-only Marina MCP edge smoke timer.
# - Default: units and smoke script come from the deployed release (SHOPVIVALIZ_RELEASE).
# - SHOPVIVALIZ_SMOKE_DIR: when the release does not carry the smoke script yet, point the
#   service at a verified checkout instead; a systemd drop-in is written for that path.
# Idempotent. Requires sudo for the unit directory only; tests override SHOPVIVALIZ_SUDO,
# SHOPVIVALIZ_UNIT_DIR and SHOPVIVALIZ_SKIP_SYSTEMCTL.
set -euo pipefail

RELEASE="${SHOPVIVALIZ_RELEASE:-/home/ubuntu/shopvivaliz-deploy/current}"
SMOKE_DIR="${SHOPVIVALIZ_SMOKE_DIR:-}"
UNIT_DIR="${SHOPVIVALIZ_UNIT_DIR:-/etc/systemd/system}"
SUDO="${SHOPVIVALIZ_SUDO-sudo}"
UNITS=(shopvivaliz-marina-edge-smoke.service shopvivaliz-marina-edge-smoke.timer)
SERVICE=shopvivaliz-marina-edge-smoke.service

for unit in "${UNITS[@]}"; do
  test -f "$RELEASE/deploy/systemd/$unit" || { echo "missing $unit in $RELEASE" >&2; exit 2; }
  $SUDO install -m 644 "$RELEASE/deploy/systemd/$unit" "$UNIT_DIR/$unit"
done

SMOKE_ROOT="$RELEASE"
if [ -n "$SMOKE_DIR" ]; then
  SMOKE_ROOT="$SMOKE_DIR"
  SMOKE_PATH="$SMOKE_ROOT/scripts/marina_mcp_edge_smoke.py"
  test -f "$SMOKE_PATH" || { echo "missing smoke script in $SMOKE_DIR" >&2; exit 2; }
  DROPIN_DIR="$UNIT_DIR/$SERVICE.d"
  $SUDO mkdir -p "$DROPIN_DIR"
  printf '%s\n' '[Service]' 'WorkingDirectory=' "WorkingDirectory=$SMOKE_ROOT" \
    'ExecStart=' "ExecStart=/usr/bin/python3 $SMOKE_PATH" \
    | $SUDO tee "$DROPIN_DIR/override.conf" >/dev/null
else
  test -f "$SMOKE_ROOT/scripts/marina_mcp_edge_smoke.py" || { echo "missing smoke script in $SMOKE_ROOT" >&2; exit 2; }
fi

if [ -n "${SHOPVIVALIZ_SKIP_SYSTEMCTL:-}" ]; then
  echo "systemctl skipped"
  exit 0
fi
$SUDO systemctl daemon-reload
$SUDO systemctl enable --now shopvivaliz-marina-edge-smoke.timer
systemctl is-enabled shopvivaliz-marina-edge-smoke.timer
systemctl list-timers shopvivaliz-marina-edge-smoke.timer --no-legend | head -n 1
