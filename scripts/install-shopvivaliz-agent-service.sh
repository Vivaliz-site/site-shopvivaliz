#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${SHOPVIVALIZ_PROJECT_DIR:-/home/ubuntu/site-shopvivaliz}"
SERVICE_NAME="shopvivaliz-agent.service"
SERVICE_SOURCE="$PROJECT_DIR/deploy/systemd/$SERVICE_NAME"
SERVICE_TARGET="/etc/systemd/system/$SERVICE_NAME"

if [ ! -d "$PROJECT_DIR" ]; then
  echo "ERROR: project dir not found: $PROJECT_DIR" >&2
  exit 1
fi

if [ ! -f "$PROJECT_DIR/scripts/autonomous-agent-loop.sh" ]; then
  echo "ERROR: missing $PROJECT_DIR/scripts/autonomous-agent-loop.sh" >&2
  exit 1
fi

if [ ! -f "$SERVICE_SOURCE" ]; then
  echo "ERROR: missing $SERVICE_SOURCE" >&2
  exit 1
fi

sudo chown ubuntu:ubuntu "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"
sudo chmod 0755 "$PROJECT_DIR/scripts/autonomous-agent-loop.sh"
sudo install -o root -g root -m 0644 "$SERVICE_SOURCE" "$SERVICE_TARGET"

sudo systemctl daemon-reload
sudo systemctl enable shopvivaliz-agent
sudo systemctl start shopvivaliz-agent

sudo systemctl status shopvivaliz-agent --no-pager
tail -n 100 "$PROJECT_DIR/logs/autonomous-agent.log"
