#!/usr/bin/env bash
# Install read-only login health monitoring without restarting Chromium.
set -Eeuo pipefail

if [[ "$(id -u)" != "0" ]]; then
  echo 'ERROR root_required' >&2
  exit 77
fi
repo_root="$(cd "$(dirname "$0")/../.." && pwd)"
source_script="$repo_root/scripts/chatgpt-continuity/shopvivaliz-login-integrity.py"
source_service="$repo_root/ops/systemd/shopvivaliz-login-integrity.service"
source_timer="$repo_root/ops/systemd/shopvivaliz-login-integrity.timer"
for source_file in "$source_script" "$source_service" "$source_timer"; do
  test -s "$source_file" || { echo 'ERROR missing_source' >&2; exit 41; }
done
python3 -c 'import ast,sys;ast.parse(open(sys.argv[1],encoding="utf8").read())' "$source_script"
for pair in \
  'shopvivaliz-dev-browser.service|shopvivaliz-dev-chromium' \
  'shopvivaliz-atendimento-browser.service|shopvivaliz-atendimento-chromium' \
  'shopvivaliz-atendimento-mcp-browser.service|shopvivaliz-atendimento-mcp-chromium'
do
  IFS='|' read -r unit profile <<< "$pair"
  systemctl is-active --quiet "$unit" || { echo "ERROR inactive_unit:$unit" >&2; exit 42; }
  test -s "/home/fredrdp/.config/$profile/Local State" || { echo "ERROR missing_profile:$profile" >&2; exit 43; }
done

dev_before="$(systemctl show shopvivaliz-dev-browser.service -p MainPID --value)"
att_before="$(systemctl show shopvivaliz-atendimento-browser.service -p MainPID --value)"
aux_before="$(systemctl show shopvivaliz-atendimento-mcp-browser.service -p MainPID --value)"
install -d -m 0700 -o root -g root /var/lib/shopvivaliz-login-integrity
install -m 0755 -o root -g root "$source_script" /usr/local/libexec/shopvivaliz-login-integrity.py
install -m 0644 -o root -g root "$source_service" /etc/systemd/system/shopvivaliz-login-integrity.service
install -m 0644 -o root -g root "$source_timer" /etc/systemd/system/shopvivaliz-login-integrity.timer

for pair in \
  'shopvivaliz-dev-browser.service|shopvivaliz-dev-chromium' \
  'shopvivaliz-atendimento-browser.service|shopvivaliz-atendimento-chromium' \
  'shopvivaliz-atendimento-mcp-browser.service|shopvivaliz-atendimento-mcp-chromium'
do
  IFS='|' read -r unit profile <<< "$pair"
  dropin="/etc/systemd/system/$unit.d/99-profile-preservation.conf"
  install -d -m 0755 "/etc/systemd/system/$unit.d"
  printf '[Service]\nExecStartPre=/usr/bin/test -s /home/fredrdp/.config/%s/Local State\n' "$profile" > "$dropin"
  chmod 0644 "$dropin"
done
systemd-analyze verify /etc/systemd/system/shopvivaliz-login-integrity.service /etc/systemd/system/shopvivaliz-login-integrity.timer >/dev/null
systemctl daemon-reload
systemctl enable --now shopvivaliz-login-integrity.timer
systemctl start shopvivaliz-login-integrity.service
systemctl is-active --quiet shopvivaliz-login-integrity.timer

test "$dev_before" = "$(systemctl show shopvivaliz-dev-browser.service -p MainPID --value)" || { echo 'ERROR dev_browser_restarted' >&2; exit 51; }
test "$att_before" = "$(systemctl show shopvivaliz-atendimento-browser.service -p MainPID --value)" || { echo 'ERROR atendimento_browser_restarted' >&2; exit 52; }
test "$aux_before" = "$(systemctl show shopvivaliz-atendimento-mcp-browser.service -p MainPID --value)" || { echo 'ERROR auxiliary_browser_restarted' >&2; exit 53; }
python3 - <<'PY'
import json
p='/var/lib/shopvivaliz-login-integrity/state.json'
s=json.load(open(p))
assert s.get('status') == 'HEALTHY', s.get('status')
assert s.get('readonly') is True
assert all(row.get('verified') and row.get('profile_intact') and row.get('service_active') for row in s['sessions'].values())
print('SHOPVIVALIZ_LOGIN_INTEGRITY=HEALTHY')
print('CHROMIUM_BROWSERS_UNTOUCHED=true')
PY
