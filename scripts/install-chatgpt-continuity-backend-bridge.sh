#!/usr/bin/env bash
set -Eeuo pipefail

unit='shopvivaliz-chatgpt-continuity.service'
worker_source="${CHATGPT_CONTINUITY_WORKER_SOURCE:-$(cd "$(dirname "$0")" && pwd)/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs}"
install_root='/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity'
config_root='/home/ubuntu/.config/shopvivaliz-chatgpt-continuity'
worker="$install_root/chatgpt-continuity-bridge-worker.mjs"
token_file='/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token'
cdp_url="${CHATGPT_CONTINUITY_CDP_URL:-http://127.0.0.1:9555}"
bridge_endpoint="${CHATGPT_CONTINUITY_BRIDGE_ENDPOINT:-https://shopvivaliz.com.br/api/chatgpt-continuity/bridge.php}"
poll_ms="${CHATGPT_CONTINUITY_POLL_MS:-15000}"

fail() {
  printf 'ERROR %s\n' "$1" >&2
  exit 2
}

[[ "$(id -u)" -ne 0 ]] || fail 'run as ubuntu, not root'
node_bin="$(command -v node || true)"
[[ -n "$node_bin" ]] || fail 'node is required'
command -v systemctl >/dev/null || fail 'systemctl is required'
command -v curl >/dev/null || fail 'curl is required'
[[ -f "$worker_source" ]] || fail "worker source missing: $worker_source"
[[ -s "$token_file" ]] || fail "protected bridge token missing: $token_file"

runtime_dir="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
export XDG_RUNTIME_DIR="$runtime_dir"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=${runtime_dir}/bus}"
[[ -S "${runtime_dir}/bus" ]] || fail 'user systemd bus unavailable; linger/user manager must be active'

install -d -m 700 "$install_root" "$config_root" "$HOME/.config/systemd/user"
install -m 700 "$worker_source" "$worker"
chmod 600 "$token_file"

cat > "$HOME/.config/systemd/user/$unit" <<UNIT
[Unit]
Description=ShopVivaliz ChatGPT continuity bridge on canonical backend browser
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$install_root
Environment=CHATGPT_CONTINUITY_BRIDGE_ENDPOINT=$bridge_endpoint
Environment=CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=$token_file
Environment=CHATGPT_CONTINUITY_CDP_URL=$cdp_url
Environment=CHATGPT_CONTINUITY_POLL_MS=$poll_ms
Environment=CHATGPT_CONTINUITY_STALL_MONITOR=1
ExecStart=$node_bin $worker
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$install_root $config_root

[Install]
WantedBy=default.target
UNIT

systemctl --user daemon-reload
systemctl --user enable --now "$unit"
systemctl --user is-enabled --quiet "$unit" || fail 'continuity service is not enabled'
systemctl --user is-active --quiet "$unit" || fail 'continuity service is not active'

curl -fsS --connect-timeout 3 --max-time 5 "$cdp_url/json/version" >/dev/null   || fail "canonical ChatGPT CDP endpoint is unavailable at $cdp_url"

# Authenticate a heartbeat without printing or persisting the token outside
# its protected file. This proves that backend and production agree on the
# same bridge secret without exposing the value in logs.
heartbeat="$(curl -fsS --connect-timeout 5 --max-time 15 \
  -H "Authorization: Bearer $(cat "$token_file")" \
  -H 'Content-Type: application/json' \
  --data '{"operation":"heartbeat"}' \
  "$bridge_endpoint")"
python3 - "$heartbeat" <<'PY'
import json, sys
payload=json.loads(sys.argv[1])
if payload.get("status") != "OK":
    raise SystemExit("continuity bridge heartbeat did not return OK")
print("CHATGPT_CONTINUITY_BRIDGE_HEARTBEAT=PASS")
PY

echo "CHATGPT_CONTINUITY_BACKEND_SERVICE=PASS"
echo "UNIT=$unit"
echo "CDP_URL=$cdp_url"
