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
grep -q 'PASSWORD_PATH="/home/ubuntu/shopvivaliz-rdp-password.txt"' "$setup"
grep -q 'openssl rand -base64 24' "$setup"
grep -q 'chpasswd' "$setup"
grep -q 'RDP_PASSWORD_FILE_READY=' "$setup"
grep -q 'oathtool --totp' "$setup"
grep -q 'pamtester xrdp-sesman' "$setup"
grep -q 'PRIVATE_RDP_PAM_AUTH=PASS' "$setup"
grep -q 'mktemp "\${PASSWORD_PATH}.tmp.XXXXXX"' "$setup"
if grep -Eq '(^|[[:space:]])(generated_password|password_tmp|password)=' "$setup"; then
  echo "credential runtime variables must not use password-labelled assignments"
  exit 1
fi
if grep -q 'pam_permit.so' "$setup"; then
  echo "unsafe pam_permit remains"
  exit 1
fi
grep -q 'rdp_prepare' "$workflow"
grep -q 'rdp_status' "$workflow"
grep -q 'rdp_enable_otp' "$workflow"
grep -q 'BASTION_TUNNEL_RETRY_MAX=6' "$workflow"
grep -q 'bastion_tunnel_ready_attempt' "$workflow"
grep -q 'rdp_prepare' "$private_workflow"
grep -q 'rdp_status' "$private_workflow"
grep -q 'rdp_otp_prepare' "$private_workflow"
grep -q 'rdp_enable_otp' "$private_workflow"
grep -q 'setup-private-rdp.sh' "$private_workflow"
grep -q 'enrollment_publish)' "$setup"
grep -q 'enrollment_cleanup)' "$setup"
grep -q 'RDP_ENROLLMENT_SESSION_READY=true' "$setup"
grep -q 'python3 -m http.server 18777 --bind 127.0.0.1' "$setup"
grep -q 'rdp_enrollment_publish' "$private_workflow"
grep -q 'rdp_enrollment_cleanup' "$private_workflow"
grep -q 'rdp_enrollment_publish' "$workflow"
grep -q 'rdp_enrollment_cleanup' "$workflow"
grep -q 'ENROLL_TAILNET_PORT="18778"' "$setup"
grep -q 'ENROLL_TAILNET_PID_FILE=' "$setup"
grep -q 'python3 -m http.server 18778 --bind.*ts_ip' "$setup"
grep -q 'RDP_ENROLLMENT_TAILNET_READY=true' "$setup"
grep -q 'RDP_ENROLLMENT_TAILNET_URL=' "$setup"

echo "PRIVATE_RDP_CONTRACT=PASS"
