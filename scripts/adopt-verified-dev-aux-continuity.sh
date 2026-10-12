#!/usr/bin/env bash
# Opt-in Dev continuity transport adoption; never transfer browser credentials,
# restart Chromium, forge conversation bindings, or declare PROGRESS_CONFIRMED.
set -Eeuo pipefail
[[ "$(id -u)" == 0 ]] || { echo DEV_AUX_ROOT_REQUIRED; exit 2; }
[[ "${SHOPVIVALIZ_AUTHORIZE_DEV_CONTINUITY_ADOPTION:-}" == 1 ]] || {
  echo DEV_AUX_OWNER_OPTIN_REQUIRED; exit 3; }
approved="${SHOPVIVALIZ_CONTINUITY_APPROVED_SHA:-}"
[[ "$approved" =~ ^[0-9a-f]{40}$ ]] || { echo DEV_AUX_APPROVED_SHA_REQUIRED; exit 4; }

repo=/home/ubuntu/shopvivaliz-claude-workspace/site-shopvivaliz
worker=/home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs
guardian=/usr/local/libexec/shopvivaliz-chatgpt-browser-guardian.sh
unit=shopvivaliz-chatgpt-continuity.service
guard_unit=shopvivaliz-chatgpt-browser-guardian.service
guard_timer=shopvivaliz-chatgpt-browser-guardian.timer
health=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state/_chatgpt-browser-health.json
user_dropin=/home/ubuntu/.config/systemd/user/shopvivaliz-chatgpt-continuity.service.d/90-verified-dev-aux.conf
guard_dropin=/etc/systemd/system/shopvivaliz-chatgpt-browser-guardian.service.d/90-verified-dev-aux.conf
[[ -f "$worker" && -f "$guardian" ]] || { echo DEV_AUX_WORKER_SOURCE_MISSING; exit 5; }
[[ ! -e "$user_dropin" && ! -e "$guard_dropin" ]] || {
  echo DEV_AUX_CONFIG_ALREADY_PRESENT; exit 6; }
for s in shopvivaliz-browser-dev-mcp.service shopvivaliz-dev-browser.service \
  shopvivaliz-atendimento-browser.service shopvivaliz-atendimento-mcp-browser.service "$guard_timer"; do
  systemctl is-active --quiet "$s" || { echo REQUIRED_SERVICE_NOT_ACTIVE; exit 7; }
done
systemctl --user --machine=ubuntu@ is-active --quiet "$unit" || {
  echo CONTINUITY_USER_SERVICE_INACTIVE; exit 8; }
remote="$(timeout 20 runuser -u ubuntu -- git -C "$repo" ls-remote origin refs/heads/main | cut -f1)"
local="$(runuser -u ubuntu -- git -C "$repo" rev-parse origin/main)"
[[ "$remote" == "$approved" && "$local" == "$approved" ]] || {
  echo DEV_AUX_MAIN_SOURCE_NOT_ATTESTED; exit 9; }

umask 077
stage="$(mktemp -d /run/shopvivaliz-dev-continuity-adopt.XXXXXXXX)"
lease_file="$stage/lease.json"
locked=0; rollback=0; timer_stopped=0; worker_written=0; guardian_written=0
processes=()
for s in shopvivaliz-dev-browser.service shopvivaliz-atendimento-browser.service \
  shopvivaliz-atendimento-mcp-browser.service; do
  processes+=("$(systemctl show -p MainPID --value "$s")")
done
replace_file() {
  local from="$1" to="$2" mode="$3" owner="$4"
  local tmp
  tmp="$(mktemp "$to.new.XXXXXXXX")"
  install -m "$mode" -o "$owner" -g "$owner" "$from" "$tmp"
  mv -f "$tmp" "$to"
}
on_exit() {
  local rc=$?
  trap - EXIT
  if [[ "$rollback" == 1 && "$rc" -ne 0 ]]; then
    rm -f "$user_dropin" "$guard_dropin"
    [[ "$worker_written" != 1 ]] ||
      replace_file "$stage/worker.old" "$worker" 0700 ubuntu ||
      echo DEV_AUX_WORKER_ROLLBACK_FAILED >&2
    [[ "$guardian_written" != 1 ]] ||
      replace_file "$stage/guardian.old" "$guardian" 0755 root ||
      echo DEV_AUX_GUARDIAN_ROLLBACK_FAILED >&2
    systemctl daemon-reload || :
    systemctl --user --machine=ubuntu@ daemon-reload || :
    systemctl --user --machine=ubuntu@ restart "$unit" ||
      echo DEV_AUX_CONTINUITY_RESTORE_FAILED >&2
    echo DEV_AUX_CONTINUITY_ROLLBACK_ATTEMPTED
  fi
  if [[ "$timer_stopped" == 1 ]]; then
    systemctl start "$guard_timer" ||
      echo DEV_AUX_GUARDIAN_TIMER_RESTORE_FAILED >&2
  fi
  if [[ "$locked" == 1 ]]; then
    python3 - "$lease_file" <<'PY' ||
import importlib.util,json,sys
from pathlib import Path
lease=json.loads(Path(sys.argv[1]).read_text())
spec=importlib.util.spec_from_file_location(
 "dev_aux_lease", "/opt/shopvivaliz-remote-control-browser/server.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
m.base.runtime_lock.release_runtime_lock(
 lease["lease_id"],lease["fencing_token"],"dev_aux_continuity_adoption_finished")
PY
      echo DEV_AUX_LEASE_RELEASE_FAILED >&2
  fi
  rm -rf "$stage"
  exit "$rc"
}
trap on_exit EXIT

# Native account-scoped lock/fencing, exact session identity, no OTP exposure.
python3 - "$lease_file" <<'PY'
import importlib.util,json,sys
from pathlib import Path
spec=importlib.util.spec_from_file_location(
 "dev_aux_lease", "/opt/shopvivaliz-remote-control-browser/server.py")
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
lock=m.base.runtime_lock.acquire_runtime_lock(
 "maintenance","shopvivaliz-account-auth:dev",180,["dev_aux_continuity_adoption"])
try:
    aux=m._corporate_profile_probe('dev_aux', 'status')
    atendimento=m.browser_auth_status({"session":"atendimento"})
    canonical=m.browser_auth_status({"session":"dev"})
    if aux.get("state")!="AUTHENTICATED" or aux.get("identity_match") is not True:
        raise RuntimeError("Dev auxiliary identity not verified")
    if atendimento.get("state")!="AUTHENTICATED" or atendimento.get("identity_match") is not True:
        raise RuntimeError("Atendimento identity not verified")
    if canonical.get("state")=="AUTHENTICATED":
        raise RuntimeError("Canonical Dev already authenticated")
    Path(sys.argv[1]).write_text(json.dumps({
      "lease_id":lock["lease_id"],"fencing_token":lock["fencing_token"]}))
except Exception:
    m.base.runtime_lock.release_runtime_lock(
      lock["lease_id"],lock["fencing_token"],"dev_aux_continuity_preflight_rejected")
    raise
PY
locked=1
curl -fsS --max-time 9 http://127.0.0.1:5583/health | python3 -c '
import json,sys
d=json.load(sys.stdin)
assert d.get("ok") is True and d.get("session")=="dev"
assert d.get("cdp_reachable") is True
assert d.get("account_authenticated") is True
assert d.get("account_identity_verified") is True
'
for name in worker guardian; do
  if [[ "$name" == worker ]]; then
    path=scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs
    destination="$stage/worker.new.mjs"
  else
    path=scripts/chatgpt-continuity/chatgpt-browser-guardian.sh
    destination="$stage/guardian.new.sh"
  fi
  runuser -u ubuntu -- git -C "$repo" show "$approved:$path" > "$destination"
  expected="$(runuser -u ubuntu -- git -C "$repo" rev-parse "$approved:$path")"
  [[ "$(git hash-object "$destination")" == "$expected" ]] || {
    echo DEV_AUX_IMMUTABLE_BLOB_MISMATCH; exit 10; }
done
node --check "$stage/worker.new.mjs"
bash -n "$stage/guardian.new.sh"
cp -p "$worker" "$stage/worker.old"
cp -p "$guardian" "$stage/guardian.old"

# Stop just the guardian timer; never restart or signal any Chrome process.
systemctl stop "$guard_timer"
timer_stopped=1
for _ in $(seq 1 7); do
  if ! systemctl is-active --quiet "$guard_unit"; then break; fi
  sleep 1
done
! systemctl is-active --quiet "$guard_unit" || {
  echo DEV_AUX_CONCURRENT_GUARDIAN; exit 11; }
rollback=1
worker_written=1
replace_file "$stage/worker.new.mjs" "$worker" 0700 ubuntu
guardian_written=1
replace_file "$stage/guardian.new.sh" "$guardian" 0755 root
install -d -m 0755 -o ubuntu -g ubuntu "$(dirname "$user_dropin")"
install -d -m 0755 -o root -g root "$(dirname "$guard_dropin")"
printf '[Service]\nEnvironment=SHOPVIVALIZ_CONTINUITY_DEV_ADOPTED_CDP=9557\n' > "$stage/unit.conf"
install -m 0644 -o ubuntu -g ubuntu "$stage/unit.conf" "$user_dropin"
install -m 0644 -o root -g root "$stage/unit.conf" "$guard_dropin"
systemctl --user --machine=ubuntu@ daemon-reload
systemctl daemon-reload
systemctl --user --machine=ubuntu@ restart "$unit"
systemctl --user --machine=ubuntu@ is-active --quiet "$unit" || {
  echo DEV_AUX_CONTINUITY_WORKER_INACTIVE; exit 12; }
systemctl start "$guard_unit"
python3 - "$health" <<'PY'
import json,sys
from pathlib import Path
d=json.loads(Path(sys.argv[1]).read_text())
assert d.get("authenticated") is True
assert d.get("session_state")=="AUTHENTICATED"
PY
i=0
for s in shopvivaliz-dev-browser.service shopvivaliz-atendimento-browser.service \
  shopvivaliz-atendimento-mcp-browser.service; do
  systemctl is-active --quiet "$s" || { echo DEV_AUX_BROWSER_SERVICE_LOST; exit 13; }
  [[ "$(systemctl show -p MainPID --value "$s")" == "${processes[i]}" ]] || {
    echo DEV_AUX_CHROME_PID_CHANGED; exit 14; }
  i=$((i + 1))
done
systemctl start "$guard_timer"
systemctl is-active --quiet "$guard_timer" || {
  echo DEV_AUX_GUARDIAN_TIMER_NOT_RESTORED; exit 15; }
timer_stopped=0
echo DEV_AUX_CONTINUITY_TRANSPORT_VERIFIED=true
echo EXISTING_CHROMIUM_PROCESSES_PRESERVED=true
rollback=0
