#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
EXPECTED_HOST="always-free-arm-1787907847-26"
RDP_USER="fredrdp"
BACKEND_IP="10.0.1.38"
STATE_DIR="/var/lib/shopvivaliz-private-rdp"
QR_PATH="/home/ubuntu/shopvivaliz-rdp-otp-enroll.png"
PASSWORD_PATH="/home/ubuntu/shopvivaliz-rdp-password.txt"
PAM_FILE="/etc/pam.d/xrdp-sesman"
XRDP_INI="/etc/xrdp/xrdp.ini"

if [ "$(id -u)" -ne 0 ]; then
  echo "PRIVATE_RDP_ERROR=root_required" >&2
  exit 20
fi
if [ "$(hostname)" != "$EXPECTED_HOST" ]; then
  echo "PRIVATE_RDP_ERROR=host_mismatch" >&2
  exit 21
fi
if ! id "$RDP_USER" >/dev/null 2>&1; then
  echo "PRIVATE_RDP_ERROR=rdp_user_missing" >&2
  exit 22
fi

mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR"

password_state() {
  passwd -S "$RDP_USER" 2>/dev/null | awk '{print $2}'
}

tailscale_ip() {
  tailscale ip -4 2>/dev/null | head -1
}

print_status() {
  local ts_state ts_ip pw
  if command -v tailscale >/dev/null 2>&1; then
    ts_state="$(tailscale status --json 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin).get("BackendState","unknown"))' 2>/dev/null || echo unknown)"
    if ! ts_ip="$(tailscale_ip)"; then
    ts_ip=""
  fi
  else
    ts_state="not-installed"
    ts_ip=""
  fi
  if ! pw="$(password_state)"; then
    pw=""
  fi
  echo "PRIVATE_RDP_STATUS=ok"
  echo "TAILSCALE_STATE=$ts_state"
  echo "TAILSCALE_IP=${ts_ip:-none}"
  echo "FREDRDP_PASSWORD_STATE=${pw:-unknown}"
  echo "OTP_SECRET_READY=$([ -s "/home/$RDP_USER/.google_authenticator" ] && echo true || echo false)"
  echo "OTP_QR_READY=$([ -s "$QR_PATH" ] && echo true || echo false)"
  echo "RDP_PASSWORD_FILE_READY=$([ -s "$PASSWORD_PATH" ] && echo true || echo false)"
  ss -ltn 2>/dev/null | awk '$4 ~ /:3389$/ {print "XRDP_LISTEN="$4}'
}

install_tailscale() {
  if command -v tailscale >/dev/null 2>&1; then
    return
  fi
  install -d -m 755 /usr/share/keyrings /etc/apt/sources.list.d
  curl -fsSL https://pkgs.tailscale.com/stable/ubuntu/noble.noarmor.gpg -o /usr/share/keyrings/tailscale-archive-keyring.gpg
  curl -fsSL https://pkgs.tailscale.com/stable/ubuntu/noble.tailscale-keyring.list -o /etc/apt/sources.list.d/tailscale.list
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq tailscale
}

prepare() {
  install_tailscale
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq libpam-google-authenticator qrencode oathtool pamtester
  systemctl enable --now tailscaled
  systemctl enable --now xrdp
  if ! tailscale set --operator=ubuntu >/dev/null 2>&1; then
    echo "PRIVATE_RDP_WARN=operator_not_set" >&2
  fi
  timedatectl show -p NTPSynchronized --value | grep -qxE '(yes|true)' || {
    echo "PRIVATE_RDP_ERROR=time_not_synchronized" >&2
    exit 23
  }
  print_status
  echo "PRIVATE_RDP_PREPARE=PASS"
}

prepare_otp() {
  command -v google-authenticator >/dev/null 2>&1 || {
    echo "PRIVATE_RDP_ERROR=google_authenticator_missing" >&2
    exit 24
  }
  local ts_ip
  if ! ts_ip="$(tailscale_ip)"; then
    ts_ip=""
  fi
  if [ -z "$ts_ip" ]; then
    echo "PRIVATE_RDP_ERROR=tailscale_not_authenticated" >&2
    exit 25
  fi

  local secret_file="/home/$RDP_USER/.google_authenticator"
  if [ ! -s "$secret_file" ]; then
    runuser -u "$RDP_USER" -- google-authenticator       -t -d -f -C -q -Q NONE -r 3 -R 30 -W -e 5       -l "ShopVivaliz RDP" -i "ShopVivaliz" >/dev/null 2>&1
  fi
  chown "$RDP_USER:$RDP_USER" "$secret_file"
  chmod 600 "$secret_file"

  local secret uri
  secret="$(head -n1 "$secret_file")"
  uri="$(python3 - "$secret" <<'PY'
import sys
from urllib.parse import quote
secret = sys.argv[1].strip()
issuer = "ShopVivaliz"
label = "ShopVivaliz RDP"
print("otpauth://totp/" + quote(issuer + ":" + label, safe="") +
      "?secret=" + quote(secret, safe="") +
      "&issuer=" + quote(issuer, safe="") +
      "&algorithm=SHA1&digits=6&period=30")
PY
)"
  umask 077
  qrencode -o "$QR_PATH" -s 8 -m 4 "$uri"
  chown ubuntu:ubuntu "$QR_PATH"
  chmod 600 "$QR_PATH"
  unset secret uri

  local pw credential_value credential_tmp
  if ! pw="$(password_state)"; then
    pw=""
  fi
  if [ "$pw" != "P" ] || [ ! -s "$PASSWORD_PATH" ]; then
    credential_value="$(openssl rand -base64 24 | tr -d '\n')"
    credential_tmp="$(mktemp "${PASSWORD_PATH}.tmp.XXXXXX")"
    install -m 600 -o ubuntu -g ubuntu /dev/null "$credential_tmp"
    printf '%s\n' "$credential_value" > "$credential_tmp"
    if ! printf '%s:%s\n' "$RDP_USER" "$credential_value" | chpasswd; then
      rm -f "$credential_tmp"
      unset credential_value
      echo "PRIVATE_RDP_ERROR=password_set_failed" >&2
      exit 29
    fi
    mv -f "$credential_tmp" "$PASSWORD_PATH"
    chown ubuntu:ubuntu "$PASSWORD_PATH"
    chmod 600 "$PASSWORD_PATH"
    unset credential_value
  fi

  echo "OTP_ENROLLMENT_QR=$QR_PATH"
  echo "RDP_PASSWORD_FILE_READY=$([ -s "$PASSWORD_PATH" ] && echo true || echo false)"
  echo "PRIVATE_RDP_OTP_PREPARE=PASS"
}

enable_otp() {
  local ts_ip pw backup_dir
  if ! ts_ip="$(tailscale_ip)"; then
    ts_ip=""
  fi
  if [ -z "$ts_ip" ]; then
    echo "PRIVATE_RDP_ERROR=tailscale_not_authenticated" >&2
    exit 25
  fi
  if [ ! -s "/home/$RDP_USER/.google_authenticator" ]; then
    echo "PRIVATE_RDP_ERROR=otp_secret_missing" >&2
    exit 26
  fi
  if ! pw="$(password_state)"; then
    pw=""
  fi
  if [ "$pw" != "P" ]; then
    echo "PRIVATE_RDP_ERROR=unix_password_not_set"
    echo "FREDRDP_PASSWORD_STATE=${pw:-unknown}"
    exit 27
  fi
  if [ ! -s "$PASSWORD_PATH" ]; then
    echo "PRIVATE_RDP_ERROR=password_file_missing" >&2
    exit 28
  fi

  backup_dir="$STATE_DIR/backup-$(date -u +%Y%m%dT%H%M%SZ)"
  mkdir -p "$backup_dir"
  cp -a "$PAM_FILE" "$backup_dir/xrdp-sesman"
  cp -a "$XRDP_INI" "$backup_dir/xrdp.ini"

  cat > "$PAM_FILE" <<'EOF'
#%PAM-1.0
auth required pam_succeed_if.so user = fredrdp
auth required pam_google_authenticator.so forward_pass
auth required pam_unix.so use_first_pass
account required pam_succeed_if.so user = fredrdp
@include common-account
@include common-session
EOF
  chmod 644 "$PAM_FILE"

  local secret credential_value code auth_token
  secret="$(head -n1 "/home/$RDP_USER/.google_authenticator")"
  credential_value="$(cat "$PASSWORD_PATH")"
  code="$(oathtool --totp -b "$secret")"
  auth_token="${credential_value}${code}"
  if ! printf '%s\n' "$auth_token" | pamtester xrdp-sesman "$RDP_USER" authenticate >/dev/null 2>&1; then
    unset secret credential_value code auth_token
    cp -a "$backup_dir/xrdp-sesman" "$PAM_FILE"
    echo "PRIVATE_RDP_ERROR=pam_self_test_failed" >&2
    exit 30
  fi
  unset secret credential_value code auth_token
  echo "PRIVATE_RDP_PAM_AUTH=PASS"

  python3 - "$XRDP_INI" <<'PY'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text(encoding="utf-8")
lines = text.splitlines()
out = []
replaced = False
for line in lines:
    if not replaced and line.startswith("port="):
        out.append("port=3389")
        replaced = True
    else:
        out.append(line)
if not replaced:
    raise SystemExit("xrdp port directive not found")
path.write_text("\n".join(out) + "\n", encoding="utf-8")
PY

  systemctl restart xrdp
  sleep 1

  if ! ss -ltn | awk '$4 ~ /:3389$/ {found=1} END {exit !found}'; then
    echo "PRIVATE_RDP_ERROR=listener_missing" >&2
    cp -a "$backup_dir/xrdp-sesman" "$PAM_FILE"
    cp -a "$backup_dir/xrdp.ini" "$XRDP_INI"
    systemctl restart xrdp
    exit 28
  fi

  echo "PRIVATE_RDP_NETWORK_SCOPE=tailnet_vcn_only"
  echo "PRIVATE_RDP_OTP_ENABLED=true"
  echo "PRIVATE_RDP_ENABLE_OTP=PASS"
  print_status
}

case "$ACTION" in
  prepare) prepare ;;
  status) print_status ;;
  otp_prepare) prepare_otp ;;
  enable_otp) enable_otp ;;
  *)
    echo "PRIVATE_RDP_ERROR=unsupported_action" >&2
    exit 64
    ;;
esac
