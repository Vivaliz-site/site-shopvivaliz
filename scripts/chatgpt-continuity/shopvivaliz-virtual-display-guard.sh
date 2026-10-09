#!/usr/bin/env bash
# Secure, persistent :99 X11 display for the isolated ChatGPT browser profiles.
set -Eeuo pipefail

mode="${1:-}"
display=':99'
socket='/tmp/.X11-unix/X99'
auth_file='/home/fredrdp/.Xauthority'

fail() { printf 'VIRTUAL_DISPLAY_ERROR=%s\n' "$1" >&2; exit 2; }
[[ "$(id -un)" == 'fredrdp' ]] || fail 'wrong_runtime_user'
[[ -x /usr/bin/Xvfb && -x /usr/bin/xauth && -x /usr/bin/openssl && -x /usr/bin/xset ]] || fail 'missing_x11_binary'

case "$mode" in
  prepare)
    # Another X server may be using :99. Fail instead of attaching a browser
    # to an unverified foreign display or rotating its existing credentials.
    [[ ! -S "$socket" ]] || fail 'display_99_already_owned'
    umask 077
    if [[ ! -e "$auth_file" ]]; then
      : > "$auth_file"
    fi
    [[ -f "$auth_file" && -O "$auth_file" ]] || fail 'authority_owner_invalid'
    chmod 600 "$auth_file"
    if ! /usr/bin/xauth -f "$auth_file" list :99 2>/dev/null | grep -q .; then
      # Feed a fresh cookie to xauth via stdin. Never put it in process argv,
      # command logging, CI output or the ChatGPT/browser audit trail.
      cookie="$(/usr/bin/openssl rand -hex 16)"
      printf 'add :99 MIT-MAGIC-COOKIE-1 %s\n' "$cookie" | /usr/bin/xauth -q -f "$auth_file" -
      unset cookie
    fi
    ;;
  wait)
    for _ in $(seq 1 40); do
      if [[ -S "$socket" ]] && DISPLAY="$display" XAUTHORITY="$auth_file" /usr/bin/xset -display "$display" q >/dev/null 2>&1; then
        echo 'VIRTUAL_DISPLAY_99=READY'
        exit 0
      fi
      sleep 0.25
    done
    fail 'x11_socket_or_authorization_not_ready'
    ;;
  *)
    fail 'invalid_mode'
    ;;
esac
