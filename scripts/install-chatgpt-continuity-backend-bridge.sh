#!/usr/bin/env bash
set -Eeuo pipefail

unit='shopvivaliz-chatgpt-continuity.service'
tunnel_unit='shopvivaliz-chatgpt-continuity-a1-tunnel.service'
browser_unit='shopvivaliz-chatgpt-browser.service'
browser_guardian_service='shopvivaliz-chatgpt-browser-guardian.service'
browser_guardian_timer='shopvivaliz-chatgpt-browser-guardian.timer'
legacy_browser_healthcheck_timer='shopvivaliz-browser-healthcheck.timer'
legacy_browser_healthcheck_service='shopvivaliz-browser-healthcheck.service'
legacy_browser_healthcheck_timer_path='/etc/systemd/system/shopvivaliz-browser-healthcheck.timer'
legacy_browser_healthcheck_service_path='/etc/systemd/system/shopvivaliz-browser-healthcheck.service'
legacy_browser_healthcheck_script='/usr/local/sbin/shopvivaliz-browser-healthcheck.sh'
legacy_continuity_atendimento_override="$HOME/.config/systemd/user/$unit.d/90-atendimento-cdp.conf"
legacy_guardian_atendimento_override="/etc/systemd/system/$browser_guardian_service.d/90-atendimento-browser.conf"
tunnel_key='/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor'
script_dir="$(cd "$(dirname "$0")" && pwd)"
repo_root="$(cd "$script_dir/.." && pwd)"
worker_source="${CHATGPT_CONTINUITY_WORKER_SOURCE:-$script_dir/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs}"
browser_unit_source="$repo_root/ops/systemd/$browser_unit"
browser_unit_target="/etc/systemd/system/$browser_unit"
browser_guardian_source="$script_dir/chatgpt-continuity/chatgpt-browser-guardian.sh"
browser_guardian_target="/usr/local/libexec/shopvivaliz-chatgpt-browser-guardian.sh"
probe_cache_source="$script_dir/chatgpt-continuity/chatgpt-browser-probe-cache.py"
probe_cache_helper="/usr/local/libexec/chatgpt-browser-probe-cache.py"
browser_health_file="${CHATGPT_BROWSER_HEALTH_FILE:-/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_chatgpt-browser-health.json}"
browser_guardian_service_source="$repo_root/ops/systemd/$browser_guardian_service"
browser_guardian_service_target="/etc/systemd/system/$browser_guardian_service"
browser_guardian_timer_source="$repo_root/ops/systemd/$browser_guardian_timer"
browser_guardian_timer_target="/etc/systemd/system/$browser_guardian_timer"
install_root='/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity'
restart_pending="$install_root/.continuity-restart-required"
config_root='/home/ubuntu/.config/shopvivaliz-chatgpt-continuity'
worker="$install_root/chatgpt-continuity-bridge-worker.mjs"
token_file='/home/ubuntu/.config/shopvivaliz-chatgpt-continuity/bridge.token'
cdp_url="${CHATGPT_CONTINUITY_CDP_URL:-http://127.0.0.1:9555}"
bridge_endpoint="${CHATGPT_CONTINUITY_BRIDGE_ENDPOINT:-http://127.0.0.1:18081/api/chatgpt-continuity/bridge.php}"
bridge_host_header="${CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER:-shopvivaliz.com.br}"
poll_ms="${CHATGPT_CONTINUITY_POLL_MS:-15000}"

fail() {
  printf 'ERROR %s\n' "$1" >&2
  exit 2
}

install_if_changed() {
  local source="$1"
  local target="$2"
  local mode="$3"
  if [[ -f "$target" ]] && cmp -s "$source" "$target"; then
    chmod "$mode" "$target"
    return 1
  fi
  install -m "$mode" "$source" "$target"
  return 0
}

sudo_install_if_changed() {
  local source="$1"
  local target="$2"
  local mode="$3"
  if sudo -n test -f "$target" && sudo -n cmp -s "$source" "$target"; then
    sudo -n chmod "$mode" "$target"
    return 1
  fi
  sudo -n install -m "$mode" "$source" "$target"
  return 0
}

[[ "$(id -u)" -ne 0 ]] || fail 'run as ubuntu, not root'
node_bin="$(command -v node || true)"
ssh_bin="$(command -v ssh || true)"
[[ -n "$node_bin" ]] || fail 'node is required'
[[ -n "$ssh_bin" ]] || fail 'ssh is required'
command -v systemctl >/dev/null || fail 'systemctl is required'
command -v curl >/dev/null || fail 'curl is required'
command -v cmp >/dev/null || fail 'cmp is required'
command -v sudo >/dev/null || fail 'sudo is required'
sudo -n true >/dev/null 2>&1 || fail 'passwordless sudo is required for browser supervision'
[[ -f "$worker_source" ]] || fail "worker source missing: $worker_source"
[[ -f "$browser_unit_source" ]] || fail "browser systemd unit missing: $browser_unit_source"
[[ -f "$browser_guardian_source" ]] || fail "browser guardian missing: $browser_guardian_source"
[[ -f "$probe_cache_source" ]] || fail "browser probe helper missing: $probe_cache_source"
[[ -f "$browser_guardian_service_source" ]] || fail "browser guardian service missing: $browser_guardian_service_source"
[[ -f "$browser_guardian_timer_source" ]] || fail "browser guardian timer missing: $browser_guardian_timer_source"
[[ -s "$token_file" ]] || fail "protected bridge token missing: $token_file"
[[ -s "$tunnel_key" ]] || fail "private A1 tunnel key missing: $tunnel_key"

runtime_dir="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
export XDG_RUNTIME_DIR="$runtime_dir"
export DBUS_SESSION_BUS_ADDRESS="${DBUS_SESSION_BUS_ADDRESS:-unix:path=${runtime_dir}/bus}"
[[ -S "${runtime_dir}/bus" ]] || fail 'user systemd bus unavailable; linger/user manager must be active'

install -d -m 700 "$install_root" "$config_root" "$HOME/.config/systemd/user"
continuity_override_removed=false
if [[ -e "$legacy_continuity_atendimento_override" ]]; then
  rm -f "$legacy_continuity_atendimento_override"
  continuity_override_removed=true
fi
worker_changed=false
if install_if_changed "$worker_source" "$worker" 700; then
  worker_changed=true
fi
chmod 600 "$token_file" "$tunnel_key"

tunnel_unit_tmp="$(mktemp)"
continuity_unit_tmp="$(mktemp)"
trap 'rm -f "$tunnel_unit_tmp" "$continuity_unit_tmp"' EXIT

cat > "$tunnel_unit_tmp" <<UNIT
[Unit]
Description=ShopVivaliz ChatGPT continuity private tunnel to A1 loopback origin
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$ssh_bin -NT -i $tunnel_key -o BatchMode=yes -o IdentitiesOnly=yes -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o StrictHostKeyChecking=yes -L 127.0.0.1:18081:127.0.0.1:8080 ubuntu@10.0.1.112
Restart=always
RestartSec=5
NoNewPrivileges=true

[Install]
WantedBy=default.target
UNIT

cat > "$continuity_unit_tmp" <<UNIT
[Unit]
Description=ShopVivaliz ChatGPT continuity bridge on canonical backend browser
After=network-online.target $tunnel_unit
Requires=$tunnel_unit
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$install_root
Environment=CHATGPT_CONTINUITY_BRIDGE_ENDPOINT=$bridge_endpoint
Environment=CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE=$token_file
Environment=CHATGPT_CONTINUITY_BRIDGE_HOST_HEADER=$bridge_host_header
Environment=CHATGPT_CONTINUITY_CDP_URL=$cdp_url
Environment=CHATGPT_CONTINUITY_POLL_MS=$poll_ms
Environment=CHATGPT_CONTINUITY_MONITOR_FALLBACK_FILE=$install_root/_chatgpt-continuity-monitor-state.json
Environment=CHATGPT_CONTINUITY_STALL_MONITOR=0
Environment=CHATGPT_CONTINUITY_AUTO_ALLOW=1
Environment=CHATGPT_CONTINUITY_AUTHORIZATION_POLL_MS=3000
Environment=SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state
ExecStart=$node_bin $worker
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$install_root $config_root /home/ubuntu/shopvivaliz-deploy/shared/agent-task-state

[Install]
WantedBy=default.target
UNIT

tunnel_unit_changed=false
if install_if_changed "$tunnel_unit_tmp" "$HOME/.config/systemd/user/$tunnel_unit" 644; then
  tunnel_unit_changed=true
fi
continuity_unit_changed=false
if install_if_changed "$continuity_unit_tmp" "$HOME/.config/systemd/user/$unit" 644; then
  continuity_unit_changed=true
fi

# Persist restart intent before any later prerequisite can fail. A retry after
# a partial install must still reload the worker/unit bytes already copied.
if [[ "$worker_changed" = true || "$continuity_unit_changed" = true ]]; then
  : > "$restart_pending"
  chmod 600 "$restart_pending"
fi

sudo -n install -d -m 755 /usr/local/libexec
system_units_changed=false
if sudo -n test -e "$legacy_guardian_atendimento_override"; then
  sudo -n rm -f "$legacy_guardian_atendimento_override"
  system_units_changed=true
fi

# A legacy one-minute CDP-only healthcheck predates the canonical guardian and
# can race it by restarting the same authenticated browser. Retire it before
# installing/enabling the single guardian owner.
if sudo -n systemctl is-enabled --quiet "$legacy_browser_healthcheck_timer" 2>/dev/null \
  || sudo -n systemctl is-active --quiet "$legacy_browser_healthcheck_timer" 2>/dev/null; then
  sudo -n systemctl disable --now "$legacy_browser_healthcheck_timer" >/dev/null
fi
if sudo -n systemctl is-active --quiet "$legacy_browser_healthcheck_service" 2>/dev/null; then
  sudo -n systemctl stop "$legacy_browser_healthcheck_service" >/dev/null
fi
if sudo -n test -e "$legacy_browser_healthcheck_timer_path"; then
  sudo -n rm -f "$legacy_browser_healthcheck_timer_path"
  system_units_changed=true
fi
if sudo -n test -e "$legacy_browser_healthcheck_service_path"; then
  sudo -n rm -f "$legacy_browser_healthcheck_service_path"
  system_units_changed=true
fi
if sudo -n test -e "$legacy_browser_healthcheck_script"; then
  sudo -n rm -f "$legacy_browser_healthcheck_script"
fi

browser_unit_changed=false
if sudo_install_if_changed "$browser_unit_source" "$browser_unit_target" 644; then
  browser_unit_changed=true
  system_units_changed=true
fi
if sudo_install_if_changed "$probe_cache_source" "$probe_cache_helper" 755; then
  system_units_changed=true
fi
if sudo_install_if_changed "$browser_guardian_source" "$browser_guardian_target" 755; then
  system_units_changed=true
fi
if sudo_install_if_changed "$browser_guardian_service_source" "$browser_guardian_service_target" 644; then
  system_units_changed=true
fi
if sudo_install_if_changed "$browser_guardian_timer_source" "$browser_guardian_timer_target" 644; then
  system_units_changed=true
fi
if [[ "$system_units_changed" = true ]]; then
  sudo -n systemctl daemon-reload
fi
sudo -n systemctl enable "$browser_unit" >/dev/null
if sudo -n systemctl is-active --quiet "$browser_unit" && [[ "$browser_unit_changed" = true ]]; then
  sudo -n systemctl try-restart "$browser_unit"
fi
sudo -n systemctl enable --now "$browser_guardian_timer" >/dev/null

if [[ "$tunnel_unit_changed" = true || "$continuity_unit_changed" = true || "$continuity_override_removed" = true ]]; then
  systemctl --user daemon-reload
fi
systemctl --user enable --now "$tunnel_unit" >/dev/null
if [[ "$tunnel_unit_changed" = true ]]; then
  systemctl --user try-restart "$tunnel_unit"
fi
tunnel_ready=false
for _ in $(seq 1 12); do
  if timeout 1 bash -c 'true </dev/tcp/127.0.0.1/18081' 2>/dev/null; then
    tunnel_ready=true
    break
  fi
  sleep 1
done
[[ "$tunnel_ready" = true ]] || fail 'private A1 continuity tunnel did not become ready'
systemctl --user is-active --quiet "$tunnel_unit" || fail 'private A1 continuity tunnel is not active'

systemctl --user enable --now "$unit" >/dev/null
if [[ -f "$restart_pending" ]]; then
  systemctl --user try-restart "$unit"
fi
systemctl --user is-enabled --quiet "$unit" || fail 'continuity service is not enabled'
systemctl --user is-active --quiet "$unit" || fail 'continuity service is not active'
if [[ -f "$restart_pending" ]]; then
  rm -f "$restart_pending"
fi

cdp_ready=false
for _ in $(seq 1 30); do
  if curl -fsS --connect-timeout 1 --max-time 2 "$cdp_url/json/version" >/dev/null 2>&1; then
    cdp_ready=true
    break
  fi
  sleep 1
done
[[ "$cdp_ready" = true ]] || fail "canonical ChatGPT CDP endpoint did not become ready at $cdp_url"

# Authenticate a heartbeat without printing or persisting the token outside
# its protected file. This proves that backend and production agree on the
# same bridge secret without exposing the value in logs.
heartbeat="$(curl -fsS --connect-timeout 5 --max-time 15 \
  -H "Authorization: Bearer $(cat "$token_file")" \
  -H "Host: $bridge_host_header" \
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

# Installation and session readiness are separate gates. Expired auth must
# not leave the old worker running after new safe code has been copied.
echo "CHATGPT_CONTINUITY_BACKEND_INSTALLED=PASS"
sudo -n systemctl start "$browser_guardian_service"

echo "CHATGPT_CONTINUITY_BACKEND_SERVICE=PASS"
echo "UNIT=$unit"
echo "CDP_URL=$cdp_url"

# AUTH_SESSION_READINESS_GATE
# A successful oneshot can mean healthy transport with a pending login.
# Only a recent, actually observed authenticated session establishes readiness.
python3 "$probe_cache_helper" verify-ready --health "$browser_health_file"
