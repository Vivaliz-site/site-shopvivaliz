#!/usr/bin/env bash
# Non-mutating local preflight for one ShopVivaliz Cloudflare admin tunnel.
# This script never reads tokens, creates tunnels, invokes sudo or starts services.
set -Eeuo pipefail

host="$(hostname -s)"
case "$host" in
  always-free-arm-1787907847-26) role=backend ;;
  shopvivaliz-free-a1) role=origin ;;
  marina-a1-4c24g) role=marina ;;
  *) printf 'PREFLIGHT_STATUS=UNKNOWN_HOST\nHOST=%s\n' "$host"; exit 3 ;;
esac

unit="shopvivaliz-cloudflared-admin.service"
secret_file="/etc/shopvivaliz/cloudflare-admin/token"
cloudflared_bin="/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared"
unit_state=unknown
ssh_state=unknown
cloudflared_path=absent
secret_metadata=absent

if command -v systemctl >/dev/null 2>&1; then
  unit_state="$(systemctl is-active "$unit" 2>/dev/null || true)"
  ssh_state="$(systemctl is-active ssh.service 2>/dev/null || true)"
  [[ -n "$unit_state" ]] || unit_state=unknown
  [[ -n "$ssh_state" ]] || ssh_state=unknown
fi
# Match the exact approved systemd ExecStart, not a PATH binary.
# Check every mutable ancestor of the pinned binary and protected token.
# An apparently root-owned leaf under a writable parent is not trusted.
safe_root_directory() {
  local dir="$1" metadata owner mode
  [[ -d "$dir" && ! -L "$dir" ]] || return 1
  metadata="$(stat -c '%u:%a' "$dir" 2>/dev/null)" || return 1
  owner="${metadata%%:*}"
  mode="${metadata#*:}"
  [[ "$owner" == 0 && "$mode" =~ ^[0-7]{3,4}$ ]] || return 1
  (( (8#$mode & 8#022) == 0 ))
}
binary_parents_safe=false
if safe_root_directory /opt &&
   safe_root_directory /opt/shopvivaliz-cloudflared-admin &&
   safe_root_directory /opt/shopvivaliz-cloudflared-admin/releases &&
   safe_root_directory /opt/shopvivaliz-cloudflared-admin/releases/2026.10.4; then
  binary_parents_safe=true
fi
token_parents_safe=false
if safe_root_directory /etc/shopvivaliz &&
   safe_root_directory /etc/shopvivaliz/cloudflare-admin; then
  token_parents_safe=true
fi
binary_owner_mode="$(stat -c '%u:%a' "$cloudflared_bin" 2>/dev/null || true)"
if [[ "$binary_parents_safe" == true && -f "$cloudflared_bin" && -x "$cloudflared_bin" && ! -L "$cloudflared_bin" ]] &&
   [[ "$(readlink -f "$cloudflared_bin" 2>/dev/null || true)" == "$cloudflared_bin" ]] &&
   [[ "$binary_owner_mode" =~ ^0:(755|750|700|555|550|500)$ ]]; then
  cloudflared_path=present_root_owned_pinned
fi
if [[ "$token_parents_safe" != true ]]; then
  secret_metadata=unsafe_parent_directory
elif [[ -L "$secret_file" ]]; then
  secret_metadata=unsafe_symlink
elif [[ -f "$secret_file" ]]; then
  # Inspect only metadata. Never open or print secret bytes.
  mode_owner="$(stat -c '%a:%u' "$secret_file" 2>/dev/null || true)"
  case "$mode_owner" in
    600:0|400:0) secret_metadata=private_root_owned_regular_file ;;
    *) secret_metadata=unsafe_owner_or_permissions ;;
  esac
fi
runner_nnp=unknown
if [[ -r /proc/self/status ]]; then
  runner_nnp="$(awk '/^NoNewPrivs:/{print $2}' /proc/self/status || true)"
  [[ -n "$runner_nnp" ]] || runner_nnp=unknown
fi

printf 'HOST=%s\nROLE=%s\nUID=%s\nSSH_UNIT=%s\nCLOUDFLARED_EXECUTABLE=%s\nCLOUDFLARED_ADMIN_UNIT=%s\nTUNNEL_TOKEN_METADATA=%s\nRUNNER_NO_NEW_PRIVILEGES=%s\n' \
  "$host" "$role" "$(id -u)" "$ssh_state" "$cloudflared_path" "$unit_state" "$secret_metadata" "$runner_nnp"
printf 'CLOUDFLARE_ACCOUNT_AUTH=NOT_TESTED\nACCESS_POLICY=NOT_TESTED\nTUNNEL_CONNECTIVITY=NOT_TESTED\nSSH_IDENTITY=NOT_TESTED\nSUDO_PERMISSION=NOT_TESTED\n'
if [[ "$cloudflared_path" == present_root_owned_pinned && "$secret_metadata" == private_root_owned_regular_file && "$unit_state" == active && "$ssh_state" == active ]]; then
  printf 'LOCAL_PREFLIGHT=CANDIDATE_ONLY\n'
else
  printf 'LOCAL_PREFLIGHT=INCOMPLETE\n'
fi
