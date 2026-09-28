#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-agent-ssh.sh"
test -f "$SCRIPT"

config="$(
  awk '
    /cat >\/etc\/ssh\/sshd_config\.d\/70-shopvivaliz-agent\.conf <<EOF/ {capture=1; next}
    capture && /^EOF$/ {exit}
    capture {print}
  ' "$SCRIPT"
)"

test -n "$config"

permit_line="$(printf '%s\n' "$config" | grep -n '^PermitUserEnvironment no$' | cut -d: -f1)"
match_line="$(printf '%s\n' "$config" | grep -n '^Match User ' | cut -d: -f1)"

test -n "$permit_line"
test -n "$match_line"
test "$permit_line" -lt "$match_line"

match_block="$(printf '%s\n' "$config" | tail -n +"$match_line")"

if printf '%s\n' "$match_block" | grep -Eq '^[[:space:]]*PermitUserEnvironment[[:space:]]'; then
  echo "AGENT_SSH_MATCH_DIRECTIVE_TEST=FAIL forbidden=PermitUserEnvironment" >&2
  exit 1
fi

printf '%s\n' "$match_block" | grep -q '^    PermitUserRC no$'

for required in   "PasswordAuthentication no"   "KbdInteractiveAuthentication no"   "AuthenticationMethods publickey"   "AllowTcpForwarding no"   "PermitTunnel no"   "GatewayPorts no"; do
  printf '%s\n' "$match_block" | grep -Fq "$required"
done

echo "AGENT_SSH_MATCH_DIRECTIVE_TEST=PASS"
