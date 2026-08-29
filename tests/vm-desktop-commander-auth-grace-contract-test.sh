#!/usr/bin/env bash
set -euo pipefail

f="${1:-scripts/vm-desktop-commander-supervisor.sh}"
fail=0
must() {
  grep -Fq "$1" "$f" || { echo "MISSING: $1"; fail=1; }
}

must 'SESSION_PATCHER="${SESSION_PATCHER:-/usr/local/lib/shopvivaliz/patch-desktop-commander-session-persistence.mjs}"'
must '"$NODE_BIN" "$SESSION_PATCHER" "$DC_PACKAGE_ROOT"'
must 'AUTH_GRACE_SECONDS="${AUTH_GRACE_SECONDS:-300}"'
must 'AUTH_REQUIRED=true reason=provider_device_flow_waiting'
must 'auth_started_at="$(date +%s)"'
must 'device_mtime_at_auth="$(stat -c %Y "$DEVICE_FILE" 2>/dev/null || echo 0)"'
must '$(date +%s) - auth_started_at >= AUTH_GRACE_SECONDS'
must '[[ "$connected" -eq 0 && "$auth_required" -eq 0 ]]'

python3 - "$f" <<'PY' || fail=1
import sys
s = open(sys.argv[1], encoding='utf-8').read()
start = s.index('while kill -0 "$child"')
end = s.index('rc=0', start)
block = s[start:end]
wait = block.find('provider_device_flow_waiting')
timeout = block.find('AUTH_GRACE_SECONDS')
term = block.find('kill -TERM -- "-$child"')
assert wait >= 0, 'no provider wait state'
assert timeout > wait, 'no grace timeout branch'
assert term > timeout, 'child terminates before grace timeout'
PY

exit "$fail"
