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
if command -v cloudflared >/dev/null 2>&1; then
  cloudflared_path=present
fi
if [[ -L "$secret_file" ]]; then
  secret_metadata=unsafe_symlink
elif [[ -f "$secret_file" ]]; then
  mode="$(stat -c '%a' "$secret_file" 2>/dev/null || true)"
  if [[ "$mode" == 600 || "$mode" == 400 ]]; then
    secret_metadata=private_regular_file
  else
    secret_metadata=unsafe_permissions
  fi
fi
runner_nnp=unknown
if [[ -r /proc/self/status ]]; then
  runner_nnp="$(awk '/^NoNewPrivs:/{print $2}' /proc/self/status || true)"
  [[ -n "$runner_nnp" ]] || runner_nnp=unknown
fi

printf 'HOST=%s\nROLE=%s\nUID=%s\nSSH_UNIT=%s\nCLOUDFLARED_EXECUTABLE=%s\nCLOUDFLARED_ADMIN_UNIT=%s\nTUNNEL_TOKEN_METADATA=%s\nRUNNER_NO_NEW_PRIVILEGES=%s\n' \
  "$host" "$role" "$(id -u)" "$ssh_state" "$cloudflared_path" "$unit_state" "$secret_metadata" "$runner_nnp"
printf 'CLOUDFLARE_ACCOUNT_AUTH=NOT_TESTED\nACCESS_POLICY=NOT_TESTED\nTUNNEL_CONNECTIVITY=NOT_TESTED\nSSH_IDENTITY=NOT_TESTED\nSUDO_PERMISSION=NOT_TESTED\n'
if [[ "$cloudflared_path" == present && "$secret_metadata" == private_regular_file && "$unit_state" == active && "$ssh_state" == active ]]; then
  printf 'LOCAL_PREFLIGHT=CANDIDATE_ONLY\n'
else
  printf 'LOCAL_PREFLIGHT=INCOMPLETE\n'
fi
