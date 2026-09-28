#!/usr/bin/env bash
set -Eeuo pipefail

STATE_DIR=/var/lib/shopvivaliz-dc-four-host-sentinel
STATUS_FILE="$STATE_DIR/status.env"
BACKEND_UNIT=shopvivaliz-desktop-commander.service
BACKEND_GUARD=shopvivaliz-desktop-commander-guardian.timer
PEER_KEY=/home/ubuntu/.ssh/shopvivaliz-free-a1-monitor
PEER_KNOWN_HOSTS=/home/ubuntu/.ssh/known_hosts
PROD_HOST=10.0.1.112
PROD_USER=ubuntu

backend=degraded
fredwin=degraded
kocepsv=degraded
production=degraded
repair_backend=not_needed
repair_production=not_needed

is_unit_healthy() {
  local unit="$1"
  test "$(systemctl is-active "$unit")" = active &&
    test "$(systemctl is-enabled "$unit")" = enabled
}

relay_exec() {
  local port="$1"
  local command="$2"
  local payload response
  payload="$(python3 -c 'import json,sys; print(json.dumps({"params":{"command":sys.argv[1],"timeout":45}}))' "$command")"
  response="$(curl -fsS --max-time 55 -H 'Content-Type: application/json' -d "$payload" "http://127.0.0.1:$port/mcp/tool/execute_command")"
  printf '%s' "$response" | python3 -c 'import json,sys; p=json.load(sys.stdin); r=p.get("result") or {}; sys.exit(1) if r.get("success") is not True else print(str(r.get("output") or ""))'
}

check_windows_dc() {
  local port="$1"
  local status_script="$2"
  local expected_logon="$3"
  local command output
  command="powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File \"C:\\site-shopvivaliz\\scripts\\$status_script\""
  for attempt in 1 2 3; do
    if output="$(relay_exec "$port" "$command")" &&
      printf '%s' "$output" | python3 -c 'import sys; vals={}; [vals.__setitem__(*line.split("=",1)) for line in sys.stdin.read().splitlines() if "=" in line]; ok=vals.get("DEVICE_STATE_EXISTS")=="True" and vals.get("CANONICAL_AGENT_COUNT")=="1" and vals.get("NONCANONICAL_AGENT_COUNT")=="0" and vals.get("TASK_EXISTS")=="True" and vals.get("TASK_LOGON_TYPE","").lower()==sys.argv[1] and vals.get("TASK_RUN_LEVEL","").lower()=="highest" and vals.get("AUTH_REQUIRED")=="False" and vals.get("PROVIDER_CONNECTED")=="True"; sys.exit(0 if ok else 1)' "$expected_logon"; then
      return 0
    fi
    sleep 5
  done
  return 1
}

check_production() {
  local cmd='test "$(systemctl is-active shopvivaliz-desktop-commander.service)" = active && test "$(systemctl is-enabled shopvivaliz-desktop-commander.service)" = enabled && test "$(systemctl is-active shopvivaliz-desktop-commander-guardian.timer)" = active && test "$(systemctl is-enabled shopvivaliz-desktop-commander-guardian.timer)" = enabled'
  ssh -i "$PEER_KEY" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$PEER_KNOWN_HOSTS" -o ConnectTimeout=6 "$PROD_USER@$PROD_HOST" "$cmd"
}

repair_production_host() {
  local cmd='sudo systemctl reset-failed shopvivaliz-desktop-commander.service; sudo systemctl restart shopvivaliz-desktop-commander.service; sudo systemctl enable --now shopvivaliz-desktop-commander-guardian.timer; sleep 4; test "$(systemctl is-active shopvivaliz-desktop-commander.service)" = active && test "$(systemctl is-active shopvivaliz-desktop-commander-guardian.timer)" = active'
  ssh -i "$PEER_KEY" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$PEER_KNOWN_HOSTS" -o ConnectTimeout=6 "$PROD_USER@$PROD_HOST" "$cmd"
}

if is_unit_healthy "$BACKEND_UNIT" && is_unit_healthy "$BACKEND_GUARD"; then
  backend=healthy
else
  repair_backend=requested
  systemctl reset-failed "$BACKEND_UNIT"
  systemctl restart "$BACKEND_UNIT"
  systemctl enable --now "$BACKEND_GUARD"
  sleep 4
  if is_unit_healthy "$BACKEND_UNIT" && is_unit_healthy "$BACKEND_GUARD"; then
    backend=healthy
    repair_backend=completed
  else
    repair_backend=failed
  fi
fi

if check_windows_dc 5557 fredwin-desktop-commander-status.ps1 interactive; then fredwin=healthy; fi
if check_windows_dc 5558 desktopkocepsv-desktop-commander-status.ps1 s4u; then kocepsv=healthy; fi

if test -f "$PEER_KEY" && test -f "$PEER_KNOWN_HOSTS" && check_production; then
  production=healthy
else
  repair_production=requested
  if test -f "$PEER_KEY" && test -f "$PEER_KNOWN_HOSTS" && repair_production_host && check_production; then
    production=healthy
    repair_production=completed
  else
    repair_production=failed
  fi
fi

checked_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
overall=healthy
for state in "$backend" "$fredwin" "$kocepsv" "$production"; do
  if test "$state" != healthy; then overall=degraded; fi
done

tmp="$(mktemp "$STATE_DIR/status.env.XXXXXX")"
printf 'OVERALL=%s\n' "$overall" > "$tmp"
printf 'CHECKED_AT=%s\n' "$checked_at" >> "$tmp"
printf 'BACKEND=%s\n' "$backend" >> "$tmp"
printf 'FRED_WIN=%s\n' "$fredwin" >> "$tmp"
printf 'KOCEPSV=%s\n' "$kocepsv" >> "$tmp"
printf 'PRODUCTION=%s\n' "$production" >> "$tmp"
printf 'REPAIR_BACKEND=%s\n' "$repair_backend" >> "$tmp"
printf 'REPAIR_PRODUCTION=%s\n' "$repair_production" >> "$tmp"
chmod 0644 "$tmp"
mv -f "$tmp" "$STATUS_FILE"

printf 'OVERALL=%s BACKEND=%s FRED_WIN=%s KOCEPSV=%s PRODUCTION=%s\n' "$overall" "$backend" "$fredwin" "$kocepsv" "$production"
test "$overall" = healthy
