#!/usr/bin/env bash
set -Eeuo pipefail
root="$(cd "$(dirname "$0")/.." && pwd)"
setup="$root/scripts/setup-backend-anydesk.sh"
workflow="$root/.github/workflows/backend-anydesk-control.yml"

test -f "$setup"
test -f "$workflow"
grep -q 'EXPECTED_HOST="always-free-arm-1787907847-26"' "$setup"
grep -q 'ANYDESK_VERSION="8.0.4"' "$setup"
grep -q 'ANYDESK_SHA256="059e5b39a2a368a0db1b458a1d4033b01d9760a99bfbc867fbd56b78a72f1819"' "$setup"
grep -q 'systemctl enable --now anydesk' "$setup"
grep -q 'systemctl start lightdm' "$setup"
grep -q 'launch_gui' "$setup"
grep -q 'anydesk_install' "$workflow"
grep -q 'anydesk_status' "$workflow"
grep -q 'setup-backend-anydesk.sh' "$workflow"
if grep -Fq '|| true' "$setup"; then
  echo "dangerous || true remains in AnyDesk setup"
  exit 1
fi
if grep -Eq '(PASSWORD|TOKEN|SECRET)=' "$setup" "$workflow"; then
  echo "secret-like literal assignment detected"
  exit 1
fi
echo "BACKEND_ANYDESK_CONTRACT=PASS"
