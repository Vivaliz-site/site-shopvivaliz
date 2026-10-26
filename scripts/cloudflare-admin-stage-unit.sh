#!/usr/bin/env bash
# Root-only, operator-gated staging. DOES NOT enable or start the tunnel.
# The tunnel runtime token is not printed, parsed or inserted in process args.
set -Eeuo pipefail
readonly TARGET="${1:-}"
case "$TARGET" in
  always-free-arm-1787907847-26|shopvivaliz-free-a1|marina-a1-4c24g) ;;
  *) echo 'ERROR: target must be one canonical OCI VM' >&2; exit 2;;
esac
if [[ "$(hostname -s)" != "$TARGET" ]]; then echo 'ERROR: wrong host' >&2; exit 2; fi
if [[ "$EUID" -ne 0 ]]; then echo 'ERROR: authorized root maintenance session required' >&2; exit 3; fi
if [[ "$(awk '/^NoNewPrivs:/ {print $2}' /proc/self/status)" == 1 ]]; then
  echo 'ERROR: NoNewPrivileges prevents approved privileged maintenance' >&2; exit 3
fi
if ! systemctl is-active --quiet ssh.service && ! systemctl is-active --quiet sshd.service; then
  echo 'ERROR: ssh server inactive' >&2; exit 3
fi
# The local root operator must install an official cloudflared version >=2025.4.0.
CF_BIN="$(command -v cloudflared || true)"
[[ -n "$CF_BIN" ]] || { echo 'ERROR: cloudflared missing' >&2; exit 3; }
CF_BIN="$(readlink -f "$CF_BIN")"
[[ -f "$CF_BIN" && -x "$CF_BIN" ]] || { echo 'ERROR: cloudflared binary invalid' >&2; exit 3; }
case "$CF_BIN" in /usr/bin/cloudflared|/usr/local/bin/cloudflared) ;;
  *) echo 'ERROR: unapproved executable path' >&2; exit 3;;
esac
[[ "$(stat -c '%u' "$CF_BIN")" == 0 ]] || { echo 'ERROR: executable not root-owned' >&2; exit 3; }
[[ "$(stat -c '%a' "$CF_BIN")" =~ ^(755|750|700|555|550|500)$ ]] || {
  echo 'ERROR: executable permissions not approved' >&2; exit 3;
}
version_text="$("$CF_BIN" --version 2>/dev/null || true)"
if [[ "$version_text" =~ ([0-9]{4})\.([0-9]+)\.([0-9]+) ]]; then
  version_year="${BASH_REMATCH[1]}"
  version_month="${BASH_REMATCH[2]}"
  if (( 10#$version_year < 2025 || (10#$version_year == 2025 && 10#$version_month < 4) )); then
    echo 'ERROR: cloudflared must be >= 2025.4.0' >&2; exit 3
  fi
else
  echo 'ERROR: cloudflared version not verifiable' >&2; exit 3
fi
readonly TOKEN_DIR=/etc/shopvivaliz/cloudflare-admin
readonly TOKEN_FILE="$TOKEN_DIR/$TARGET.token"
for dir in /etc/shopvivaliz "$TOKEN_DIR"; do
  [[ -d "$dir" && ! -L "$dir" ]] || { echo 'ERROR: secret directory missing or symlinked' >&2; exit 3; }
  [[ "$(stat -c '%u' "$dir")" == 0 ]] || { echo 'ERROR: secret directory not root-owned' >&2; exit 3; }
  dir_mode="$(stat -c '%a' "$dir")"
  (( (8#$dir_mode & 8#022) == 0 )) || { echo 'ERROR: writable secret directory' >&2; exit 3; }
done
[[ ! -L "$TOKEN_FILE" && -f "$TOKEN_FILE" ]] || { echo 'ERROR: dedicated per-VM tunnel token missing' >&2; exit 3; }
stat_output="$(stat -c '%a:%u' "$TOKEN_FILE")"
[[ "$stat_output" == '600:0' ]] || { echo 'ERROR: tunnel token must be root-owned mode 0600' >&2; exit 3; }
readonly UNIT=/etc/systemd/system/shopvivaliz-cloudflare-admin.service
if [[ -e "$UNIT" || -L "$UNIT" ]]; then
  echo 'ERROR: existing dedicated unit preserved, refusing to overwrite' >&2; exit 3
fi
# Stage only after official Cloudflare Access policy is created and independently reviewed.
# These gates are external and cannot be inferred from an existing token file.
install -d -m 0755 /etc/systemd/system
unit_tmp="$(mktemp /etc/systemd/system/.shopvivaliz-cloudflare-admin.XXXXXXXX)"
trap 'rm -f "$unit_tmp"' EXIT
cat > "$unit_tmp" <<EOF
[Unit]
Description=ShopVivaliz per-VM Cloudflare admin tunnel (independent)
Documentation=https://developers.cloudflare.com/tunnel/get-started/
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
DynamicUser=yes
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
LoadCredential=token:$TOKEN_FILE
ExecStart=$CF_BIN tunnel --no-autoupdate run --token-file %d/token
Restart=on-failure
RestartSec=5s

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 "$unit_tmp"
chown root:root "$unit_tmp"
# Hard-link to destination atomically; never overwrite an existing unit.
ln "$unit_tmp" "$UNIT" || { echo 'ERROR: concurrent unit appeared' >&2; exit 4; }
rm -f "$unit_tmp"
trap - EXIT
systemctl daemon-reload
if systemctl is-enabled --quiet shopvivaliz-cloudflare-admin.service; then
  echo 'ERROR: unit unexpectedly enabled' >&2; exit 4
fi
echo 'STAGED_ONLY: dedicated unit remains disabled and stopped'
echo 'NEXT: approved Cloudflare Access policy, per-VM hostname, SSH identity and E2E review'
