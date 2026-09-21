#!/usr/bin/env bash
set -Eeuo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
setup="$root/scripts/setup-anydesk-backend.sh"
workflow="$root/.github/workflows/shopvivaliz-remote-access.yml"

test -f "$setup"
grep -q 'keys.anydesk.com/repos/DEB-GPG-KEY' "$setup"
grep -q 'https://deb.anydesk.com all main' "$setup"
grep -q 'apt-get install -y -qq anydesk' "$setup"
grep -q 'menu desktop-file-utils xdg-utils' "$setup"
grep -q 'dpkg --configure anydesk' "$setup"
grep -q 'systemctl enable --now anydesk.service' "$setup"
grep -q 'runuser -u "$RDP_USER"' "$setup"
grep -q 'GDK_BACKEND=x11' "$setup"
grep -q 'anydesk_install' "$workflow"
grep -q 'anydesk_status' "$workflow"
grep -q 'anydesk_launch' "$workflow"
grep -q 'setup-anydesk-backend.sh' "$workflow"
grep -q 'action.startswith("anydesk_")' "$workflow"

echo "ANYDESK_BACKEND_CONTRACT=PASS"
