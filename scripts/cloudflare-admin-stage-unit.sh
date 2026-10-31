#!/bin/bash
# Official root-operator STAGING ONLY. Does not enable/start tunnel or grant sudo.
# Unified with the existing Cloudflare admin service contract from PR #3263.
set -Eeuo pipefail
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077
readonly TARGET="${1:-}"
case "$TARGET" in
  always-free-arm-1787907847-26|shopvivaliz-free-a1|marina-a1-4c24g) ;;
  *) echo 'ERROR: target must be one canonical OCI VM' >&2; exit 2 ;;
esac
if [[ "$(hostname -s)" != "$TARGET" ]]; then echo 'ERROR: wrong host' >&2; exit 2; fi
if [[ "$EUID" -ne 0 ]]; then echo 'ERROR: authorized root maintenance session required' >&2; exit 3; fi
if [[ "$(awk '/^NoNewPrivs:/ {print $2}' /proc/self/status)" != 0 ]]; then
  echo 'ERROR: NoNewPrivileges prevents approved privileged maintenance' >&2; exit 3
fi
if ! systemctl is-active --quiet ssh.service && ! systemctl is-active --quiet sshd.service; then
  echo 'ERROR: SSH service inactive' >&2; exit 3
fi
safe_root_dir() {
  local path="$1" owner mode
  [[ -d "$path" && ! -L "$path" ]] || return 1
  owner="$(stat -c '%u' "$path")" || return 1
  mode="$(stat -c '%a' "$path")" || return 1
  [[ "$owner" == 0 && "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
  (( (8#$mode & 8#022) == 0 ))
}
readonly CF_BIN=/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared
for dir in /opt /opt/shopvivaliz-cloudflared-admin \
           /opt/shopvivaliz-cloudflared-admin/releases \
           /opt/shopvivaliz-cloudflared-admin/releases/2026.10.4; do
  safe_root_dir "$dir" || { echo 'ERROR: untrusted pinned binary directory' >&2; exit 3; }
done
[[ -f "$CF_BIN" && -x "$CF_BIN" && ! -L "$CF_BIN" ]] || {
  echo 'ERROR: pinned cloudflared binary invalid' >&2; exit 3;
}
[[ "$(readlink -f "$CF_BIN")" == "$CF_BIN" ]] || {
  echo 'ERROR: cloudflared binary points outside reviewed release' >&2; exit 3;
}
[[ "$(stat -c '%u:%a' "$CF_BIN")" =~ ^0:(755|750|700|555|550|500)$ ]] || {
  echo 'ERROR: cloudflared must be root-owned with restricted executable mode' >&2; exit 3;
}
version_text="$("$CF_BIN" --version 2>/dev/null || true)"
if [[ "$version_text" =~ ([0-9]{4})\.([0-9]+)\.([0-9]+) ]]; then
  year="${BASH_REMATCH[1]}"; month="${BASH_REMATCH[2]}"; patch="${BASH_REMATCH[3]}"
  if (( 10#$year < 2026 || (10#$year == 2026 && 10#$month < 10) || \
        (10#$year == 2026 && 10#$month == 10 && 10#$patch < 4) )); then
    echo 'ERROR: cloudflared release below reviewed 2026.10.4 baseline' >&2; exit 3
  fi
else
  echo 'ERROR: cloudflared version not verifiable' >&2; exit 3
fi
readonly TOKEN_DIR=/etc/shopvivaliz/cloudflare-admin
readonly TOKEN_FILE="$TOKEN_DIR/token"
for dir in /etc /etc/shopvivaliz "$TOKEN_DIR"; do
  safe_root_dir "$dir" || { echo 'ERROR: unsafe token parent directory' >&2; exit 3; }
done
[[ -f "$TOKEN_FILE" && ! -L "$TOKEN_FILE" ]] || {
  echo 'ERROR: dedicated tunnel token file missing' >&2; exit 3;
}
[[ "$(stat -c '%u:%a' "$TOKEN_FILE")" =~ ^0:(600|400)$ ]] || {
  echo 'ERROR: tunnel token must be root-owned, mode 0600 or 0400' >&2; exit 3;
}
# Distinct VMs have distinct token *values* provisioned through the official
# Cloudflare account. Metadata here cannot prove scope, Access policy or MFA.
# No Access approval marker is fabricated: the unit itself requires it.
readonly UNIT=/etc/systemd/system/shopvivaliz-cloudflared-admin.service
for dir in /etc/systemd /etc/systemd/system; do
  safe_root_dir "$dir" || { echo 'ERROR: unsafe systemd parent directory' >&2; exit 3; }
done
# The legacy service name from PR #3262 must not coexist with the reviewed
# canonical service from #3263. Vendor, runtime, drop-ins, masked, enabled
# or even inactive units must be reconciled by an authorized host operator.
for admin_name in shopvivaliz-cloudflare-admin.service shopvivaliz-cloudflared-admin.service; do
  for systemd_dir in /etc/systemd/system /run/systemd/system /usr/lib/systemd/system /lib/systemd/system; do
    for candidate in "$systemd_dir/$admin_name" "$systemd_dir/$admin_name.d" \
                     "$systemd_dir/multi-user.target.wants/$admin_name" \
                     "$systemd_dir/default.target.wants/$admin_name"; do
      if [[ -e "$candidate" || -L "$candidate" ]]; then
        echo 'ERROR: conflicting_cloudflare_admin_unit; operator reconciliation required' >&2; exit 3
      fi
    done
  done
  active_state="$(systemctl show "$admin_name" --property=ActiveState --value --no-pager 2>/dev/null)" || {
    echo 'ERROR: unable to verify admin unit runtime state' >&2; exit 3;
  }
  [[ "$active_state" == inactive ]] || {
    echo 'ERROR: admin unit not conclusively inactive' >&2; exit 3;
  }
  fragment_path="$(systemctl show "$admin_name" --property=FragmentPath --value --no-pager 2>/dev/null)" || {
    echo 'ERROR: unable to verify admin unit fragment' >&2; exit 3;
  }
  dropin_paths="$(systemctl show "$admin_name" --property=DropInPaths --value --no-pager 2>/dev/null)" || {
    echo 'ERROR: unable to verify admin unit drop-ins' >&2; exit 3;
  }
  [[ -z "$fragment_path" && -z "$dropin_paths" ]] || {
    echo 'ERROR: existing admin service metadata; refusing to shadow' >&2; exit 3;
  }
  enabled_state="$(systemctl is-enabled "$admin_name" 2>/dev/null || true)"
  case "$enabled_state" in
    not-found) ;;
    *) echo 'ERROR: admin unit has existing enablement state' >&2; exit 3 ;;
  esac
done
# Create the reviewed static service exactly, with immutable constants.
# No shell expansion, tokens or inherited environment enter the unit.
unit_tmp="$(mktemp /etc/systemd/system/.shopvivaliz-cloudflared-admin.XXXXXXXX)"
trap 'rm -f "$unit_tmp"' EXIT
cat > "$unit_tmp" <<'UNIT_TEMPLATE'
[Unit]
Description=ShopVivaliz independent Cloudflare admin tunnel (per-VM, operator-gated)
Wants=network-online.target
After=network-online.target
ConditionPathExists=/etc/shopvivaliz/cloudflare-admin/token
ConditionPathExists=/etc/shopvivaliz/cloudflare-admin/access-approved

[Service]
Type=simple
DynamicUser=yes
StateDirectory=shopvivaliz-cloudflared-admin
Environment=HOME=/var/lib/shopvivaliz-cloudflared-admin
LoadCredential=tunnel-token:/etc/shopvivaliz/cloudflare-admin/token
ExecStart=/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared tunnel --no-autoupdate run --token-file %d/tunnel-token
Restart=on-failure
RestartSec=10s
UMask=0077
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelModules=yes
ProtectKernelTunables=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
SystemCallArchitectures=native
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX

[Install]
WantedBy=multi-user.target
UNIT_TEMPLATE
chmod 0644 "$unit_tmp"
chown root:root "$unit_tmp"
ln "$unit_tmp" "$UNIT" || { echo 'ERROR: concurrent unit appeared' >&2; exit 4; }
rm -f "$unit_tmp"
trap - EXIT
systemctl daemon-reload
if systemctl is-enabled --quiet shopvivaliz-cloudflared-admin.service ||
   systemctl is-active --quiet shopvivaliz-cloudflared-admin.service; then
  echo 'ERROR: staged admin unit unexpectedly active or enabled' >&2; exit 4
fi
echo 'STAGED_ONLY: canonical unit remains disabled and stopped'
echo 'NEXT: independent Access/MFA validation, official approval, SSH and E2E'
