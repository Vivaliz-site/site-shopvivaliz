#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
setup="$root/scripts/setup-private-rdp.sh"
workflow="$root/.github/workflows/backend-vm-oci-control.yml"
private_workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"

test -f "$setup"
grep -q 'libpam-google-authenticator' "$setup"
grep -q 'tailscale' "$setup"
grep -q 'pam_google_authenticator.so forward_pass' "$setup"
grep -q 'port=3389' "$setup"
grep -q 'PRIVATE_RDP_NETWORK_SCOPE=tailnet_vcn_only' "$setup"
grep -q "grep -qxE '(yes|true)'" "$setup"
if grep -q 'pam_permit.so' "$setup"; then
  echo "unsafe pam_permit remains"
  exit 1
fi
grep -q 'rdp_prepare' "$workflow"
grep -q 'rdp_status' "$workflow"
grep -q 'rdp_enable_otp' "$workflow"
grep -q 'rdp_prepare' "$private_workflow"
grep -q 'rdp_status' "$private_workflow"
grep -q 'rdp_otp_prepare' "$private_workflow"
grep -q 'rdp_enable_otp' "$private_workflow"
grep -q 'setup-private-rdp.sh' "$private_workflow"

echo "PRIVATE_RDP_CONTRACT=PASS"
