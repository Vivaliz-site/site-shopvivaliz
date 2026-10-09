#!/usr/bin/env bash
set -Eeuo pipefail

# Non-disruptive protection against abandoned Windows reverse SSH sessions.
# Never restart sshd or modify the Windows/Remote Control MCP services.
[ "$(id -u)" -eq 0 ] || { echo "ERROR=root_required" >&2; exit 2; }
[ "$(hostname)" = "always-free-arm-1787907847-26" ] || {
  echo "ERROR=backend_host_only" >&2; exit 3;
}

source_file="${1:-deploy/ssh/05-shopvivaliz-reverse-ssh-keepalive.conf}"
destination="/etc/ssh/sshd_config.d/05-shopvivaliz-reverse-ssh-keepalive.conf"
[ -s "$source_file" ] || { echo "ERROR=source_missing" >&2; exit 4; }
grep -Fq 'Include /etc/ssh/sshd_config.d/*.conf' /etc/ssh/sshd_config || {
  echo "ERROR=dropin_not_included" >&2; exit 5;
}

/usr/sbin/sshd -t  # Existing configuration must be valid before mutation.
mkdir -p /etc/ssh/sshd_config.d
tmp="$(mktemp /etc/ssh/sshd_config.d/.shopvivaliz-keepalive-new.XXXXXXXX)"
backup="$(mktemp /etc/ssh/sshd_config.d/.shopvivaliz-keepalive-old.XXXXXXXX)"
had_previous=0
changed=0
committed=0

rollback() {
  code=$?
  trap - EXIT
  if [ "$committed" -ne 1 ] && [ "$changed" -eq 1 ]; then
    if [ "$had_previous" -eq 1 ]; then
      install -m 0644 "$backup" "$destination"
    else
      rm -f -- "$destination"
    fi
    /usr/sbin/sshd -t || true
    systemctl reload ssh.service || true
  fi
  rm -f -- "$tmp" "$backup"
  exit "$code"
}
trap rollback EXIT

if [ -f "$destination" ]; then
  cp -p -- "$destination" "$backup"
  had_previous=1
fi
install -m 0644 "$source_file" "$tmp"
mv -f -- "$tmp" "$destination"
changed=1

/usr/sbin/sshd -t
settings="$(/usr/sbin/sshd -T)"
grep -qx 'clientaliveinterval 30' <<<"$settings" || {
  echo "ERROR=keepalive_interval_not_effective" >&2; exit 6;
}
grep -qx 'clientalivecountmax 3' <<<"$settings" || {
  echo "ERROR=keepalive_count_not_effective" >&2; exit 7;
}
systemctl reload ssh.service
systemctl is-active --quiet ssh.service
systemctl is-active --quiet ssh.socket
committed=1
echo "REVERSE_SSH_CLIENTALIVE=PASS interval=30 count=3"
