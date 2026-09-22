#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT="scripts/setup-agent-ssh.sh"
test -f "$SCRIPT"

match_block="$(
  awk '
    /^Match User \$AGENT_USER$/ {capture=1}
    capture {print}
    capture && /^EOF$/ {exit}
  ' "$SCRIPT"
)"

test -n "$match_block"

if printf '%s\n' "$match_block" | grep -Eq '^[[:space:]]*PermitUserEnvironment[[:space:]]'; then
  echo "AGENT_SSH_MATCH_DIRECTIVE_TEST=FAIL forbidden=PermitUserEnvironment" >&2
  exit 1
fi

for required in   "PasswordAuthentication no"   "KbdInteractiveAuthentication no"   "AuthenticationMethods publickey"   "AllowTcpForwarding no"   "PermitTunnel no"   "GatewayPorts no"; do
  printf '%s\n' "$match_block" | grep -Fq "$required"
done

echo "AGENT_SSH_MATCH_DIRECTIVE_TEST=PASS"
