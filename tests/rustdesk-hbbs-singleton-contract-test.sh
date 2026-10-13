#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET="$ROOT/scripts/setup-rustdesk-remote.sh"
bash -n "$TARGET"

# Stubs let CI simulate a running Docker-owned hbbs without a Docker daemon.
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
cat >"$tmp/hostname" <<'SH'
#!/bin/sh
echo always-free-arm-1787907847-26
SH
cat >"$tmp/docker" <<'SH'
#!/bin/sh
[ "$1" = inspect ] || exit 83
[ "$2" = -f ] || exit 84
[ "$3" = '{{.State.Pid}}' ] || exit 85
[ "$4" = shopvivaliz-rustdesk-hbbs ] || exit 86
echo 4321
SH
cat >"$tmp/pgrep" <<'SH'
#!/bin/sh
[ "$1" = -x ] && [ "$2" = hbbs ] || exit 82
case "${HBBS_TEST_MODE:-match}" in
 match) printf '4321\n' ;;
 duplicate) printf '4321\n9876\n' ;;
 orphan_only) printf '9876\n' ;;
 missing) exit 1 ;;
 *) exit 88 ;;
esac
SH
chmod 755 "$tmp/hostname" "$tmp/docker" "$tmp/pgrep"

export PATH="$tmp:$PATH"
HBBS_TEST_MODE=match bash "$TARGET" server_integrity >"$tmp/success"
grep -qx 'RUSTDESK_HBBS_SINGLETON=PASS' "$tmp/success"

for mode in duplicate orphan_only missing; do
  if HBBS_TEST_MODE="$mode" bash "$TARGET" server_integrity >"$tmp/out" 2>"$tmp/err"; then
    echo "FAIL: $mode must not pass as a healthy singleton"
    exit 1
  else
    rc="$?"
  fi
  [ "$rc" -eq 47 ] || { echo "FAIL: $mode exit=$rc expected=47"; exit 1; }
  grep -q 'RUSTDESK_ERROR=hbbs_duplicate_or_orphan' "$tmp/err"
done

# Both deployment gates must remain in place.
grep -Fq 'hbbs_duplicate_or_orphan_before_install 47' "$TARGET"
grep -Fq 'hbbs_duplicate_or_orphan_after_install 47' "$TARGET"

# Regression: INPUT jumps into this chain before its generic loopback ACCEPT.
# Never block 127.0.0.1, and retain the final reject for untrusted networks.
loop_line="$(grep -nF 'iptables -A "$CHAIN" -i lo -j ACCEPT' "$TARGET" | head -1 | cut -d: -f1)"
vcn_line="$(grep -nF 'iptables -A "$CHAIN" -s 10.0.0.0/8 -j ACCEPT' "$TARGET" | head -1 | cut -d: -f1)"
tail_line="$(grep -nF 'iptables -A "$CHAIN" -s 100.64.0.0/10 -j ACCEPT' "$TARGET" | head -1 | cut -d: -f1)"
deny_line="$(grep -nF 'iptables -A "$CHAIN" -j REJECT' "$TARGET" | head -1 | cut -d: -f1)"
[ "$loop_line" -lt "$vcn_line" ]
[ "$vcn_line" -lt "$tail_line" ]
[ "$tail_line" -lt "$deny_line" ]
echo 'RUSTDESK_HBBS_SINGLETON_CONTRACT=PASS'
