#!/bin/bash
# Operator-only Marina MCP release promotion. No application deploy, no DB/worker changes.
# This script is NOT a blanket privileged approval or a GitHub Actions transport.
set -Eeuo pipefail
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
umask 077
refuse() { printf 'MARINA_MCP_UPGRADE_REFUSED=%s\n' "$1" >&2; exit 3; }
mode="${1:---inspect}"
[[ $# -le 1 && ( "$mode" == "--inspect" || "$mode" == "--stage" || "$mode" == "--activate" ) ]] || refuse invalid_mode
[[ "$(hostname -s)" == "marina-a1-4c24g" ]] || refuse wrong_host
root=/opt/shopvivaliz-marina-mcp
source_checkout="$root/operator-src"
old_target="$(readlink "$root/current" 2>/dev/null)" || refuse current_release_unavailable
if [[ "$mode" == "--inspect" ]]; then
    printf 'MARINA_MCP_CURRENT=%s\nMARINA_MCP_OPERATOR=INSPECT_ONLY\n' "$old_target"
    exit 0
fi
[[ "$EUID" -eq 0 ]] || refuse official_root_operator_required
grep -Eq '^NoNewPrivs:[[:space:]]*0[[:space:]]*$' /proc/self/status || refuse no_new_privileges_denied
sha="${SHOPVIVALIZ_MARINA_APPROVED_SHA:-}"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || refuse approved_sha_required
expected_current="${SHOPVIVALIZ_MARINA_EXPECTED_CURRENT_SHA:-}"
[[ "$expected_current" =~ ^[0-9a-f]{40}$ ]] || refuse expected_current_sha_required
[[ "$old_target" == "releases/$expected_current" ]] || refuse stale_active_release
[[ "$sha" != "$expected_current" ]] || refuse release_already_current
refuse_writable() {
    local mode
    mode="$(stat -c '%a' "$1")" || refuse stat_unavailable
    (( (8#$mode & 8#22) == 0 )) || refuse group_or_other_writable
}
require_root_tree() {
    local p="$1"
    while :; do
        [[ ! -L "$p" && -d "$p" ]] || refuse unsafe_source_ancestor
        [[ "$(stat -c '%u' "$p")" == 0 ]] || refuse nonroot_source_ancestor
        refuse_writable "$p"
        [[ "$p" == / ]] && break
        p="${p%/*}"
        [[ -n "$p" ]] || p=/
    done
}
require_root_tree "$source_checkout"
for p in "$source_checkout/.git" "$source_checkout/marina-direct-mcp" "$source_checkout/marina-direct-mcp/systemd" "$source_checkout/scripts"; do
    [[ ! -L "$p" && -d "$p" && "$(stat -c '%u' "$p")" == 0 ]] || refuse checkout_directory_unsafe
    refuse_writable "$p"
done
[[ -f "$source_checkout/.git/config" && ! -L "$source_checkout/.git/config" ]] || refuse git_config_unsafe
[[ "$(stat -c '%u' "$source_checkout/.git/config")" == 0 ]] || refuse git_config_not_root
refuse_writable "$source_checkout/.git/config"
safe_git() {
    /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/nonexistent XDG_CONFIG_HOME=/nonexistent \
        /usr/bin/git -c core.fsmonitor=false -c core.hooksPath=/dev/null -C "$source_checkout" "$@"
}
[[ "$(safe_git rev-parse HEAD 2>/dev/null)" == "$sha" ]] || refuse unapproved_checkout
[[ -z "$(safe_git status --porcelain 2>/dev/null)" ]] || refuse dirty_checkout
[[ -d /run && ! -L /run && "$(stat -c '%u' /run)" == 0 ]] || refuse lock_parent_unsafe
refuse_writable /run
lock=/run/shopvivaliz-marina-mcp-upgrade.lock
[[ ! -L "$lock" ]] || refuse lock_symlink
if [[ -e "$lock" ]]; then
    [[ -f "$lock" && "$(stat -c '%u:%a' "$lock")" == '0:600' ]] || refuse lock_unsafe
fi
exec 9>"$lock"
flock -n 9 || refuse concurrent_upgrade
required=(
    server.py
    read_only_telemetry.py
    scoped_write.py
    trusted_write_operator.py
    operator_admin_route.py
    operator_preflight.py
    local_auth_smoke.py
    systemd/shopvivaliz-marina-mcp.service
)
for name in "${required[@]}"; do
    f="$source_checkout/marina-direct-mcp/$name"
    [[ -f "$f" && ! -L "$f" && "$(stat -c '%u' "$f")" == 0 ]] || refuse source_file_unsafe
    refuse_writable "$f"
done
verifier="$source_checkout/scripts/validate-owner-pr-grant.py"
[[ -f "$verifier" && ! -L "$verifier" && "$(stat -c '%u' "$verifier")" == 0 ]] || refuse grant_verifier_unsafe
refuse_writable "$verifier"
[[ -d "$root/releases" && ! -L "$root/releases" && "$(stat -c '%u' "$root/releases")" == 0 ]] || refuse release_root_unsafe
refuse_writable "$root/releases"
release="$root/releases/$sha"
[[ ! -L "$release" ]] || refuse release_symlink
if [[ -e "$release" ]]; then
    [[ -d "$release" && "$(stat -c '%u:%a' "$release")" == "0:755" ]] || refuse release_conflict
else
    install -d -o root -g root -m 0755 "$release"
fi
copy_immutable() {
    local source="$1" target="$2"
    if [[ -e "$target" || -L "$target" ]]; then
        [[ -f "$target" && ! -L "$target" && "$(stat -c '%u:%a' "$target")" == '0:444' ]] || refuse existing_file_unsafe
        cmp -s "$source" "$target" || refuse existing_file_mismatch
    else
        install -o root -g root -m 0444 "$source" "$target"
    fi
}
for name in server.py read_only_telemetry.py scoped_write.py trusted_write_operator.py operator_admin_route.py operator_preflight.py local_auth_smoke.py; do
    copy_immutable "$source_checkout/marina-direct-mcp/$name" "$release/$name"
done
copy_immutable "$verifier" "$release/validate_owner_pr_grant.py"
copy_immutable "$source_checkout/marina-direct-mcp/systemd/shopvivaliz-marina-mcp.service" "$release/shopvivaliz-marina-mcp.service"
grep -Fqx 'User=shopvivaliz-marina-mcp' "$release/shopvivaliz-marina-mcp.service" || refuse service_user_invalid
grep -Fqx 'NoNewPrivileges=yes' "$release/shopvivaliz-marina-mcp.service" || refuse service_privileges_invalid
grep -Fqx 'ReadWritePaths=/var/lib/shopvivaliz-marina-mcp/workspace /var/lib/shopvivaliz-marina-mcp/state' "$release/shopvivaliz-marina-mcp.service" || refuse writable_paths_invalid
grep -Fq -- '--enable-approved-scoped-write' "$release/shopvivaliz-marina-mcp.service" || refuse write_gate_missing
sudo -n -u shopvivaliz-marina-mcp /usr/bin/python3 -B "$release/server.py" --check >/dev/null || refuse staged_local_preflight_failed
if [[ "$mode" == "--stage" ]]; then
    printf 'MARINA_MCP_UPGRADE=IMMUTABLE_RELEASE_STAGED\nRELEASE_SHA=%s\nCURRENT_UNCHANGED=true\n' "$sha"
    exit 0
fi
# Activation is a single-host MCP-only change, never the site/deploy/workers.
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p ActiveState --value)" == active ]] || refuse service_not_active
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p User --value)" == shopvivaliz-marina-mcp ]] || refuse unexpected_service_user
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p NoNewPrivileges --value)" == yes ]] || refuse unexpected_service_privileges
[[ "$(systemctl show shopvivaliz-marina-mcp-tunnel.service -p ActiveState --value)" == inactive ]] || refuse live_tunnel_unexpected
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p DropInPaths --value)" == "" ]] || refuse service_dropin_present
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p FragmentPath --value)" == /etc/systemd/system/shopvivaliz-marina-mcp.service ]] || refuse service_fragment_conflict
unit=/etc/systemd/system/shopvivaliz-marina-mcp.service
[[ -f "$unit" && ! -L "$unit" && "$(stat -c '%u:%a' "$unit")" == '0:644' ]] || refuse installed_unit_unsafe
uid="$(id -u shopvivaliz-marina-mcp)"
gid="$(id -g shopvivaliz-marina-mcp)"
for dir in /var/lib/shopvivaliz-marina-mcp/workspace /var/lib/shopvivaliz-marina-mcp/state; do
    [[ ! -L "$dir" ]] || refuse private_dir_symlink
    if [[ -e "$dir" ]]; then
        [[ -d "$dir" && "$(stat -c '%u:%g:%a' "$dir")" == "$uid:$gid:700" ]] || refuse private_dir_unsafe
    else
        install -d -o shopvivaliz-marina-mcp -g shopvivaliz-marina-mcp -m 0700 "$dir"
    fi
done
# Verify owner's exact approved grant under the isolated service UID before
# altering either the live symlink or the systemd unit.
sudo -n -u shopvivaliz-marina-mcp /usr/bin/python3 -B "$release/server.py" --check --enable-approved-scoped-write >/dev/null || refuse owner_grant_preflight_denied
backup="$(mktemp /run/shopvivaliz-marina-unit.XXXXXXXX)"
temp_unit="$(mktemp /etc/systemd/system/.shopvivaliz-marina-mcp.XXXXXXXX)"
temp_link="$root/.current-upgrade-$$"
rollback() {
    status=$?
    trap - EXIT
    if [[ "$status" -ne 0 ]]; then
        if [[ -e "$backup" && "$switched" == yes ]]; then
            cp "$backup" "$unit"
            chmod 0644 "$unit"
            ln -s "releases/$expected_current" "$temp_link.rollback"
            mv -Tf "$temp_link.rollback" "$root/current"
            systemctl daemon-reload || :
            systemctl restart shopvivaliz-marina-mcp.service || :
            printf 'MARINA_MCP_UPGRADE=ROLLED_BACK_AFTER_FAILURE\n' >&2
        fi
    fi
    rm -f "$backup" "$temp_unit" "$temp_link" "$temp_link.rollback"
    exit "$status"
}
switched=no
trap rollback EXIT
cp "$unit" "$backup"
install -o root -g root -m 0644 "$release/shopvivaliz-marina-mcp.service" "$temp_unit"
ln -s "releases/$sha" "$temp_link"
# Safe atomic two-object switch with rollback, under exclusive operator lock.
mv -Tf "$temp_unit" "$unit"
mv -Tf "$temp_link" "$root/current"
switched=yes
systemctl daemon-reload
systemctl restart shopvivaliz-marina-mcp.service
sudo -n -u shopvivaliz-marina-mcp /usr/bin/python3 -B "$release/local_auth_smoke.py"
[[ "$(readlink "$root/current")" == "releases/$sha" ]] || refuse activation_not_persisted
[[ "$(systemctl show shopvivaliz-marina-mcp.service -p ActiveState --value)" == active ]] || refuse activation_not_running
printf 'MARINA_MCP_UPGRADE=ACTIVE_LOCAL_SMOKE_GREEN\nRELEASE_SHA=%s\n' "$sha"
