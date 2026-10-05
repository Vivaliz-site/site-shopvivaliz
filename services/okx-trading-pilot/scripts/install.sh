#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot
ENV_FILE=/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot.env
UNIT_DIR=/etc/systemd/system

install -d -m 0700 -o ubuntu -g ubuntu "$STATE_DIR"
if [ ! -f "$ENV_FILE" ]; then
  install -m 0600 -o ubuntu -g ubuntu /dev/null "$ENV_FILE"
fi
install -m 0644 "$ROOT/systemd/shopvivaliz-okx-mcp.service" "$UNIT_DIR/shopvivaliz-okx-mcp.service"
install -m 0644 "$ROOT/systemd/shopvivaliz-okx-pilot.service" "$UNIT_DIR/shopvivaliz-okx-pilot.service"
systemctl daemon-reload
systemctl enable shopvivaliz-okx-mcp.service shopvivaliz-okx-pilot.service
