#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_SERVER="${1:-remote-control-browser-mcp/server.py}"
SOURCE_UNIT="${2:-deploy/systemd/shopvivaliz-remote-control-browser-mcp.service}"
INSTALL_DIR="/opt/shopvivaliz-remote-control-browser"
UNIT_PATH="/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service"

test "$(id -u)" = 0 || { echo "ERROR=root_required" >&2; exit 2; }
test -f "$SOURCE_SERVER" || { echo "ERROR=server_source_missing" >&2; exit 3; }
test -f "$SOURCE_UNIT" || { echo "ERROR=unit_source_missing" >&2; exit 4; }
test -f /opt/shopvivaliz-remote-control/server.py || { echo "ERROR=base_remote_control_missing" >&2; exit 5; }
test -f /var/lib/shopvivaliz-remote-control/service.env || { echo "ERROR=base_service_env_missing" >&2; exit 6; }

export DEBIAN_FRONTEND=noninteractive
missing=()
for bin in xdotool xclip scrot; do
  command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
if [ "${#missing[@]}" -gt 0 ]; then
  apt-get update -qq
  apt-get install -y --no-install-recommends xdotool xclip scrot
fi

install -d -m 0755 "$INSTALL_DIR"
install -m 0755 "$SOURCE_SERVER" "$INSTALL_DIR/server.py"
install -m 0644 "$SOURCE_UNIT" "$UNIT_PATH"
python3 -m py_compile "$INSTALL_DIR/server.py"

systemctl daemon-reload
systemctl enable shopvivaliz-remote-control-browser-mcp.service
systemctl restart shopvivaliz-remote-control-browser-mcp.service

for _ in $(seq 1 20); do
  if curl -fsS http://127.0.0.1:5581/health > /tmp/shopvivaliz-browser-mcp-health.json; then
    python3 - <<'PY'
import json
p=json.load(open('/tmp/shopvivaliz-browser-mcp-health.json', encoding='utf-8'))
assert p.get('ok') is True
assert p.get('endpoint') == 'shopvivaliz-remote-control-browser-mcp'
deps=p.get('dependencies') or {}
assert deps.get('xdotool') is True
assert deps.get('xclip') is True
assert deps.get('scrot') is True
print('REMOTE_CONTROL_BROWSER_MCP_HEALTH=PASS')
PY
    rm -f /tmp/shopvivaliz-browser-mcp-health.json
    REMOTE_CONTROL_BROWSER_MCP_READY=1
    break
  fi
  sleep 1
done
if [ "${REMOTE_CONTROL_BROWSER_MCP_READY:-0}" != "1" ]; then
  systemctl status shopvivaliz-remote-control-browser-mcp.service --no-pager -l
  journalctl -u shopvivaliz-remote-control-browser-mcp.service -n 100 --no-pager
  exit 7
fi
