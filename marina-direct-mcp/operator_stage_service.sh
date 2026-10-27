#!/usr/bin/env bash
# Operator-only, fail-closed staging of the Marina read-only MCP.
# Does NOT create credentials, install a tunnel, enable or start services.
set -Eeuo pipefail
umask 077

refuse() { printf 'MARINA_MCP_STAGE_REFUSED=%s\n' "$1" >&2; exit 3; }
mode="${1:---inspect}"
[[ $# -eq 1 || $# -eq 0 ]] || refuse invalid_arguments
[[ "$mode" == '--inspect' || "$mode" == '--stage' ]] || refuse invalid_mode
[[ "$(hostname -s)" == 'marina-a1-4c24g' ]] || refuse host_identity_mismatch

if [[ "$mode" == '--inspect' ]]; then
  printf 'MARINA_MCP_STAGE=INSPECT_ONLY\nMIGRATION_STATUS=NO_CUT_NAO_APTO\n'
  exit 0
fi

# Stage only from a genuine, authorized, privileged operator shell.
[[ "$EUID" -eq 0 ]] || refuse official_root_operator_required
[[ -r /proc/self/status ]] || refuse privilege_state_unavailable
grep -Eq '^NoNewPrivs:[[:space:]]*0[[:space:]]*$' /proc/self/status || refuse no_new_privileges_denied

source_root="$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
source_dir="$source_root/marina-direct-mcp"
expected_sha="${SHOPVIVALIZ_MARINA_APPROVED_SHA:-}"
[[ "$expected_sha" =~ ^[0-9a-f]{40}$ ]] || refuse approved_release_sha_required
[[ "$(stat -c '%u' "$source_root")" == '0' ]] || refuse source_not_root_owned
refuse_writable() {
  local mode
  mode="$(stat -c '%a' "$1")" || refuse unsafe_mode
  [[ "$mode" =~ ^[0-7]{3,4}$ ]] || refuse unsafe_mode
  (( (8#$mode & 8#22) == 0 )) || refuse group_or_other_writable
}
refuse_writable "$source_root"
# A root-owned checkout beneath an untrusted parent can be swapped after validation.
# Require root ownership and no group/other write on every source ancestor.
trusted_source_ancestors() {
  local parent="${1%/*}"
  [[ -n "$parent" ]] || parent=/
  while :; do
    [[ -d "$parent" && ! -L "$parent" ]] || refuse source_parent_unsafe
    [[ "$(stat -c '%u' "$parent")" == 0 ]] || refuse source_parent_not_root_owned
    refuse_writable "$parent"
    [[ "$parent" == / ]] && break
    parent="${parent%/*}"
    [[ -n "$parent" ]] || parent=/
  done
}
trusted_source_ancestors "$source_root"
[[ ! -L "$source_dir" && -d "$source_dir" ]] || refuse source_dir_unsafe
[[ "$(stat -c '%u' "$source_dir")" == '0' ]] || refuse source_not_root_owned
refuse_writable "$source_dir"
[[ ! -L "$source_dir/systemd" ]] || refuse unsafe_systemd_source_dir
[[ "$(stat -c '%u' "$source_dir/systemd")" == '0' ]] || refuse source_systemd_dir_not_root_owned
refuse_writable "$source_dir/systemd"
[[ "$(git -C "$source_root" rev-parse HEAD 2>/dev/null)" == "$expected_sha" ]] || refuse unapproved_checkout
checkout_status="$(git -C "$source_root" status --porcelain -- marina-direct-mcp)" || refuse checkout_status_unavailable
[[ -z "$checkout_status" ]] || refuse unclean_checkout

for file in server.py operator_preflight.py systemd/shopvivaliz-marina-mcp.service systemd/shopvivaliz-marina-mcp-tunnel.service; do
  [[ -f "$source_dir/$file" && ! -L "$source_dir/$file" ]] || refuse source_file_missing_or_symlink
  [[ "$(stat -c '%u' "$source_dir/$file")" == '0' ]] || refuse source_file_not_root_owned
  refuse_writable "$source_dir/$file"
done

# An inactive but enabled unit would start on the next boot. Never stage over it.
assert_unit_not_autostart() {
  local unit="$1" unit_state unit_file_state
  unit_state="$(systemctl show "$unit" --property=ActiveState --value --no-pager 2>/dev/null)" || refuse service_state_unavailable
  [[ "$unit_state" == inactive ]] || refuse active_unit_mutation_denied
  unit_file_state="$(systemctl is-enabled "$unit" 2>/dev/null)" || :
  case "$unit_file_state" in
    disabled|not-found) ;;
    *) refuse unit_may_start_on_boot ;;
  esac
}
for unit in shopvivaliz-marina-mcp.service shopvivaliz-marina-mcp-tunnel.service; do
  grep -Fqx 'User=shopvivaliz-marina-mcp' "$source_dir/systemd/$unit" || refuse unit_identity_invalid
  grep -Fqx 'NoNewPrivileges=yes' "$source_dir/systemd/$unit" || refuse unit_privilege_invalid
  assert_unit_not_autostart "$unit"
done

# Do not replace, reconfigure or restart an existing installation.
stage_root=/opt/shopvivaliz-marina-mcp
for dir in /opt "$stage_root" "$stage_root/releases" "$stage_root/bin" /var/lib /var/lib/shopvivaliz-marina-mcp /etc/systemd /etc/systemd/system; do
  [[ ! -L "$dir" ]] || refuse unsafe_directory_symlink
  if [[ -e "$dir" ]]; then
    [[ -d "$dir" ]] || refuse path_not_directory
    refuse_writable "$dir"
    if [[ "$dir" != /var/lib/shopvivaliz-marina-mcp ]]; then
      [[ "$(stat -c '%u' "$dir")" == '0' ]] || refuse unsafe_directory_owner
    fi
  fi
done
release_dir="$stage_root/releases/$expected_sha"
[[ ! -L "$release_dir" ]] || refuse unsafe_release_symlink
if [[ -e "$release_dir" ]]; then
  [[ -d "$release_dir" && "$(stat -c '%u' "$release_dir")" == '0' ]] || refuse unsafe_existing_release
  refuse_writable "$release_dir"
  [[ "$(stat -c '%a' "$release_dir")" == '755' ]] || refuse unsafe_existing_release_mode
fi
[[ ! -e "$stage_root/current" || -L "$stage_root/current" ]] || refuse existing_current_not_symlink
if [[ -L "$stage_root/current" ]]; then
  [[ "$(readlink "$stage_root/current")" == "releases/$expected_sha" ]] || refuse different_release_active
fi

# Inspect before creating the service identity. Never re-own an existing home.
if getent passwd shopvivaliz-marina-mcp >/dev/null; then
  record="$(getent passwd shopvivaliz-marina-mcp)"
  IFS=: read -r _ _ uid _ _ home shell <<< "$record"
  [[ "$uid" != '0' && "$home" == /var/lib/shopvivaliz-marina-mcp && "$shell" == /usr/sbin/nologin ]] || refuse service_identity_conflict
  [[ "$(id -gn shopvivaliz-marina-mcp)" == shopvivaliz-marina-mcp ]] || refuse service_primary_group_conflict
fi
assert_existing_service_home() {
  local home="$1" existing_uid existing_gid
  if [[ -e "$home" || -L "$home" ]]; then
    getent passwd shopvivaliz-marina-mcp >/dev/null || refuse orphaned_service_home
    [[ -d "$home" && ! -L "$home" ]] || refuse unsafe_existing_service_home
    existing_uid="$(id -u shopvivaliz-marina-mcp)" || refuse service_identity_unavailable
    existing_gid="$(id -g shopvivaliz-marina-mcp)" || refuse service_identity_unavailable
    [[ "$(stat -c '%u:%g:%a' "$home")" == "$existing_uid:$existing_gid:700" ]] || refuse existing_home_owner_mode_mismatch
  elif ! getent passwd shopvivaliz-marina-mcp >/dev/null; then
    ! getent group shopvivaliz-marina-mcp >/dev/null || refuse stray_service_group
  fi
}
assert_existing_service_home /var/lib/shopvivaliz-marina-mcp

# A repeated stage may only accept identical, root-owned files.
check_target() {
  local from="$1" target="$2" expected_mode="$3"
  if [[ -e "$target" || -L "$target" ]]; then
    [[ -f "$target" && ! -L "$target" ]] || refuse existing_target_unsafe
    [[ "$(stat -c '%u' "$target")" == '0' ]] || refuse target_not_root_owned
    refuse_writable "$target"
    [[ "$(stat -c '%a' "$target")" == "$expected_mode" ]] || refuse existing_target_wrong_mode
    cmp -s "$from" "$target" || refuse existing_target_differs
  fi
}
check_target "$source_dir/server.py" "$release_dir/server.py" 444
check_target "$source_dir/operator_preflight.py" "$release_dir/operator_preflight.py" 444
for unit in shopvivaliz-marina-mcp.service shopvivaliz-marina-mcp-tunnel.service; do
  check_target "$source_dir/systemd/$unit" "/etc/systemd/system/$unit" 644
done

[[ "$(stat -c '%u' /run)" == '0' ]] || refuse unsafe_lock_parent
refuse_writable /run
lock_file=/run/shopvivaliz-marina-mcp-stage.lock
[[ ! -L "$lock_file" ]] || refuse unsafe_lock_symlink
if [[ -e "$lock_file" ]]; then
  [[ -f "$lock_file" && "$(stat -c '%u' "$lock_file")" == '0' && "$(stat -c '%a' "$lock_file")" == '600' ]] || refuse unsafe_lock_file
fi
exec 9>"$lock_file"
flock -n 9 || refuse concurrent_operator_stage
if ! getent passwd shopvivaliz-marina-mcp >/dev/null; then
  /usr/sbin/useradd --system --user-group --create-home --home-dir /var/lib/shopvivaliz-marina-mcp --shell /usr/sbin/nologin shopvivaliz-marina-mcp
fi
getent group shopvivaliz-marina-mcp >/dev/null || refuse service_group_missing
install -d -o root -g root -m 0755 "$stage_root" "$stage_root/releases" "$stage_root/bin" "$release_dir"
install -d -o shopvivaliz-marina-mcp -g shopvivaliz-marina-mcp -m 0700 /var/lib/shopvivaliz-marina-mcp
stage_file() {
  local from="$1" target="$2" mode="$3"
  if [[ ! -e "$target" ]]; then
    install -o root -g root -m "$mode" "$from" "$target"
  fi
}
stage_file "$source_dir/server.py" "$release_dir/server.py" 0444
stage_file "$source_dir/operator_preflight.py" "$release_dir/operator_preflight.py" 0444
if [[ ! -L "$stage_root/current" ]]; then
  ln -s "releases/$expected_sha" "$stage_root/current"
fi
for unit in shopvivaliz-marina-mcp.service shopvivaliz-marina-mcp-tunnel.service; do
  stage_file "$source_dir/systemd/$unit" "/etc/systemd/system/$unit" 0644
done
systemctl daemon-reload
printf 'MARINA_MCP_STAGE=CODE_AND_UNITS_ONLY\nTUNNEL=NOT_PROVISIONED\nSERVICES=NOT_ENABLED_OR_STARTED\nMIGRATION_STATUS=NO_CUT_NAO_APTO\n'
