#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-rustdesk-remote.sh"
test -f "$SCRIPT"

tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

fn="$(
  awk '
    /^configure_client_profile\(\) \{/ {capture=1}
    capture {print}
    capture && /^\}/ {exit}
  ' "$SCRIPT"
)"

test -n "$fn"

eval "$fn"

owner="$(id -un)"
home="$tmpdir/home"
install -d "$home"

configure_client_profile "$home" "$owner" "10.0.1.38" "server-public-key"

cfg="$home/.config/rustdesk/RustDesk2.toml"
test -s "$cfg"
grep -Fq "custom-rendezvous-server = '10.0.1.38'" "$cfg"
grep -Fq "relay-server = '10.0.1.38'" "$cfg"
grep -Fq "key = 'server-public-key'" "$cfg"

echo "RUSTDESK_PROFILE_NOUNSET_TEST=PASS"
