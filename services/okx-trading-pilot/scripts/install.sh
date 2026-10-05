#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$ROOT/../.." && pwd)"
BRIDGE_SRC="$REPO_ROOT/ops/okx-trading-pilot"
STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot
RUNTIME_DIR="$STATE_DIR/runtime"
ENV_FILE=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot.env
UNIT_DIR=/etc/systemd/system

install -d -m 0700 -o ubuntu -g ubuntu "$STATE_DIR" "$RUNTIME_DIR"
if [ ! -f "$ENV_FILE" ]; then
  install -m 0600 -o ubuntu -g ubuntu /dev/null "$ENV_FILE"
fi
install -m 0644 -o ubuntu -g ubuntu "$BRIDGE_SRC/mcp-bridge.mjs" "$RUNTIME_DIR/mcp-bridge.mjs"
install -m 0644 -o ubuntu -g ubuntu "$BRIDGE_SRC/tool-policy.mjs" "$RUNTIME_DIR/tool-policy.mjs"
install -m 0644 -o ubuntu -g ubuntu "$BRIDGE_SRC/package.json" "$RUNTIME_DIR/package.json"
runuser -u ubuntu -- env HOME=/home/ubuntu npm install --omit=dev --no-audit --no-fund --prefix "$RUNTIME_DIR"
install -m 0644 "$ROOT/systemd/shopvivaliz-okx-mcp.service" "$UNIT_DIR/shopvivaliz-okx-mcp.service"
install -m 0644 "$ROOT/systemd/shopvivaliz-okx-pilot.service" "$UNIT_DIR/shopvivaliz-okx-pilot.service"
systemctl daemon-reload
systemctl enable shopvivaliz-okx-mcp.service shopvivaliz-okx-pilot.service
