#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_SERVER="${1:-remote-control-browser-mcp/server.py}"
SOURCE_UNIT="${2:-deploy/systemd/shopvivaliz-remote-control-browser-mcp.service}"
INSTALL_DIR="/opt/shopvivaliz-remote-control-browser"
UNIVERSAL_SERVICE="shopvivaliz-browser-universal-mcp.service"
UNIVERSAL_SOURCE_DIR="$(dirname "$SOURCE_SERVER")/universal"
UNIVERSAL_RUNTIME_DIR="/home/ubuntu/shopvivaliz-deploy/shared/browser-universal"
UNIVERSAL_UNIT_SOURCE="deploy/systemd/shopvivaliz-browser-universal-mcp.service"

UNIT_PATH="/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service"
DESKTOP_ENV="/var/lib/shopvivaliz-remote-control/desktop.env"
UNIT_DROPIN_DIR="/etc/systemd/system/shopvivaliz-remote-control-browser-mcp.service.d"
CONFLICTING_SESSION_DROPIN="$UNIT_DROPIN_DIR/40-authenticated-session.conf"
CONFLICTING_SESSION_BACKUP="$UNIT_DROPIN_DIR/40-authenticated-session.conf.disabled"
CONFLICTING_SESSION_DAYBREAK_BACKUP="$UNIT_DROPIN_DIR/40-authenticated-session.conf.bak-daybreak"

test "$(id -u)" = 0 || { echo "ERROR=root_required" >&2; exit 2; }
test -f "$SOURCE_SERVER" || { echo "ERROR=server_source_missing" >&2; exit 3; }
test -f "$SOURCE_UNIT" || { echo "ERROR=unit_source_missing" >&2; exit 4; }
test -f /opt/shopvivaliz-remote-control/server.py || { echo "ERROR=base_remote_control_missing" >&2; exit 5; }
test -f /var/lib/shopvivaliz-remote-control/service.env || { echo "ERROR=base_service_env_missing" >&2; exit 6; }
# Refuse to replace an already-enabled universal controller with old sources.
# This check MUST precede the first install/copy/service mutation.
if systemctl is-active --quiet "$UNIVERSAL_SERVICE"; then
  if ! grep -q 'UNIVERSAL_MCP_TOOLS' "$SOURCE_SERVER"; then
    echo "ERROR=browser_universal_source_missing_refusing_downgrade" >&2
    exit 14
  fi
  for required in mcp-server.mjs live-browser.mjs live-worker.mjs package.json package-lock.json; do
    if [ ! -f "$UNIVERSAL_SOURCE_DIR/$required" ]; then
      echo "ERROR=browser_universal_runtime_source_missing:$required" >&2
      exit 15
    fi
  done
  [ -f "$UNIVERSAL_UNIT_SOURCE" ] || { echo "ERROR=browser_universal_unit_source_missing" >&2; exit 16; }
fi

command -v rustdesk >/dev/null 2>&1 || { echo "ERROR=rustdesk_binary_missing" >&2; exit 9; }
if [ -e "$DESKTOP_ENV" ]; then
  desktop_mode="$(stat -c %a "$DESKTOP_ENV")"
  desktop_owner="$(stat -c %U:%G "$DESKTOP_ENV")"
  [ "$desktop_mode" = "600" ] || { echo "ERROR=desktop_env_mode_invalid" >&2; exit 10; }
  [ "$desktop_owner" = "root:root" ] || { echo "ERROR=desktop_env_owner_invalid" >&2; exit 11; }
  grep -q '^SHOPVIVALIZ_RUSTDESK_HOST_IDS=' "$DESKTOP_ENV" || { echo "ERROR=desktop_env_mapping_missing" >&2; exit 12; }
fi

export DEBIAN_FRONTEND=noninteractive
missing=()
for bin in xdotool xclip scrot xwd; do
  command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
if [ "${#missing[@]}" -gt 0 ]; then
  apt-get update -qq
  apt-get install -y --no-install-recommends xdotool xclip scrot x11-apps
fi

# The corporate CDP browser units require a protected Xvfb :99 display.
# Reinstall only systemd dependencies; do not restart authenticated profiles.
bash scripts/setup-shopvivaliz-display-recovery.sh

install -d -m 0755 "$INSTALL_DIR"
install -m 0755 "$SOURCE_SERVER" "$INSTALL_DIR/server.py"
install -m 0644 "$SOURCE_UNIT" "$UNIT_PATH"
python3 -m py_compile "$INSTALL_DIR/server.py"
# The universal browser is a separate unprivileged Chromium/Playwright runtime.
# Install it from the same source tree as the parent, atomically at service
# boundaries; never copy an authenticated Atendimento/Dev browser profile.
if [ -f "$UNIVERSAL_SOURCE_DIR/mcp-server.mjs" ]; then
  command -v node >/dev/null 2>&1 || { echo "ERROR=node_missing" >&2; exit 17; }
  command -v npm >/dev/null 2>&1 || { echo "ERROR=npm_missing" >&2; exit 18; }
  [ -f "$UNIVERSAL_UNIT_SOURCE" ] || { echo "ERROR=browser_universal_unit_source_missing" >&2; exit 16; }
  install -d -m 0750 -o ubuntu -g ubuntu "$UNIVERSAL_RUNTIME_DIR"
  for source in mcp-server.mjs live-browser.mjs live-worker.mjs universal-browser.mjs; do
    install -m 0750 -o ubuntu -g ubuntu "$UNIVERSAL_SOURCE_DIR/$source" "$UNIVERSAL_RUNTIME_DIR/$source"
  done
  for source in package.json package-lock.json; do
    install -m 0644 -o ubuntu -g ubuntu "$UNIVERSAL_SOURCE_DIR/$source" "$UNIVERSAL_RUNTIME_DIR/$source"
  done
  sudo -n -u ubuntu npm ci --prefix "$UNIVERSAL_RUNTIME_DIR" --omit=dev --no-audit --no-fund
  install -m 0644 "$UNIVERSAL_UNIT_SOURCE" "/etc/systemd/system/$UNIVERSAL_SERVICE"
fi


# The Browser MCP must never inherit or retain a drop-in that points it at the
# authenticated ChatGPT continuity profile. General browsing and continuity are
# isolated sessions; keeping this override makes browser_open create tabs in
# the continuity Chrome cgroup and can exhaust its task budget.
rm -f "$CONFLICTING_SESSION_DROPIN" "$CONFLICTING_SESSION_BACKUP" "$CONFLICTING_SESSION_DAYBREAK_BACKUP"
if [ -d "$UNIT_DROPIN_DIR" ] && [ -z "$(find "$UNIT_DROPIN_DIR" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
  rmdir "$UNIT_DROPIN_DIR"
fi

systemctl daemon-reload
systemctl enable shopvivaliz-remote-control-browser-mcp.service
systemctl restart shopvivaliz-remote-control-browser-mcp.service
if [ -f "$UNIVERSAL_SOURCE_DIR/mcp-server.mjs" ]; then
  systemctl enable "$UNIVERSAL_SERVICE"
  systemctl restart "$UNIVERSAL_SERVICE"
  universal_ready=0
  for _ in $(seq 1 20); do
    if curl -fsS --connect-timeout 3 --max-time 5 http://127.0.0.1:5595/health >/dev/null; then
      universal_ready=1
      break
    fi
    sleep 1
  done
  [ "$universal_ready" = "1" ] || { echo "ERROR=browser_universal_not_ready" >&2; exit 19; }
  echo "REMOTE_CONTROL_BROWSER_UNIVERSAL_HEALTH=PASS"
fi


effective_exec="$(systemctl show shopvivaliz-remote-control-browser-mcp.service -p ExecStart --value)"
for needle in   "SHOPVIVALIZ_BROWSER_MCP_GUI_USER=fredconsole"   "SHOPVIVALIZ_BROWSER_MCP_DISPLAY=:0"   "SHOPVIVALIZ_BROWSER_MCP_WINDOW_CLASS=shopvivaliz-general"; do
  if [[ "$effective_exec" != *"$needle"* ]]; then
    echo "ERROR=browser_mcp_session_isolation_drift missing=$needle" >&2
    if ! systemctl cat shopvivaliz-remote-control-browser-mcp.service --no-pager >&2; then
      echo "WARN=browser_mcp_unit_dump_failed" >&2
    fi
    exit 8
  fi
done

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
assert deps.get('xwd') is True
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

# Dedicated CDP browser MCPs: one process per authenticated browser session.
for session in atendimento dev; do
  unit="shopvivaliz-browser-${session}-mcp.service"
  install -m 0644 "deploy/systemd/${unit}" "/etc/systemd/system/${unit}"
done
systemctl daemon-reload
for session in atendimento dev; do
  unit="shopvivaliz-browser-${session}-mcp.service"
  if [ "$session" = atendimento ]; then port=5582; else port=5583; fi
  # enable --now is a no-op for an already running unit. Both session MCPs
  # must reload the newly installed server.py and service unit on every deploy.
  # This restarts only the MCP bridge, never the authenticated Chromium profile.
  systemctl enable "$unit"
  systemctl restart "$unit"
  ready=0
  for _ in $(seq 1 20); do
    if curl -fsS "http://127.0.0.1:${port}/health" >"/tmp/${unit}.health.json"; then
      python3 - "$session" "/tmp/${unit}.health.json" <<'PYHEALTH'
import json,sys
session,path=sys.argv[1:]
p=json.load(open(path,encoding='utf-8'))
assert p.get('ok') is True
print('browser_session='+session+' health=PASS')
PYHEALTH
      rm -f "/tmp/${unit}.health.json"
      ready=1
      break
    fi
    sleep 1
  done
  [ "$ready" = 1 ] || { systemctl status "$unit" --no-pager -l; exit 13; }
done
