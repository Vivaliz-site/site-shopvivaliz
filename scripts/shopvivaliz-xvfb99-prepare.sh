#!/bin/sh
# Prepare a protected X11 display for the existing corporate Chromium profiles.
# The Xauthority cookie is never printed or passed as a process argument.
set -eu

case "$1" in
  ensure)
    umask 077
    auth=/home/fredrdp/.Xauthority
    if [ ! -f "$auth" ]; then
      : > "$auth"
    fi
    if ! /usr/bin/xauth -f "$auth" list :99 | /usr/bin/grep -q 'MIT-MAGIC-COOKIE-1'; then
      cookie=$(/usr/bin/mcookie)
      /usr/bin/printf 'add :99 MIT-MAGIC-COOKIE-1 %s\n' "$cookie" | /usr/bin/xauth -f "$auth" -
      unset cookie
    fi
    /bin/chmod 0600 "$auth"
    ;;
  wait)
    i=0
    while [ "$i" -lt 50 ]; do
      if [ -S /tmp/.X11-unix/X99 ]; then exit 0; fi
      /usr/bin/sleep 0.2
      i=$((i+1))
    done
    exit 1
    ;;
  *)
    exit 2
    ;;
esac
