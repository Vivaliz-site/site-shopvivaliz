#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
setup="$root/scripts/setup-private-rdp.sh"
workflow="$root/.github/workflows/backend-vm-oci-control.yml"

test -f "$setup"
grep -q 'libpam-google-authenticator' "$setup"
grep -q 'tailscale' "$setup"
grep -q 'pam_google_authenticator.so forward_pass' "$setup"
grep -q 'pam_permit.so' "$setup" && { echo "unsafe pam_permit remains"; exit 1; } || true
grep -q 'rdp_prepare' "$workflow"
grep -q 'rdp_status' "$workflow"
grep -q 'rdp_enable_otp' "$workflow"

echo "PRIVATE_RDP_CONTRACT=PASS"
