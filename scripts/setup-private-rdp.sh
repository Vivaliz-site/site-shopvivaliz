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
ENROLL_DIR="/home/ubuntu/.private-rdp-enroll"
ENROLL_PORT="18777"
ENROLL_PID_FILE="$ENROLL_DIR/server.pid"
ENROLL_SESSION_FILE="$ENROLL_DIR/session.id"
BROWSER_API="http://127.0.0.1:17777"

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

publish_enrollment() {
  if [ ! -s "$PASSWORD_PATH" ]; then
    echo "PRIVATE_RDP_ERROR=password_file_missing" >&2
    exit 31
  fi
  if [ ! -s "$QR_PATH" ]; then
    echo "PRIVATE_RDP_ERROR=otp_qr_missing" >&2
    exit 32
  fi
  if ! curl -fsS --connect-timeout 2 --max-time 5 "$BROWSER_API/health" | python3 -c 'import json,sys; d=json.load(sys.stdin); raise SystemExit(0 if d.get("ok") is True and d.get("endpoint")=="browser-worker" else 1)'; then
    echo "PRIVATE_RDP_ERROR=browser_worker_unhealthy" >&2
    exit 33
  fi

  install -d -m 700 -o ubuntu -g ubuntu "$ENROLL_DIR"
  install -m 600 -o ubuntu -g ubuntu "$PASSWORD_PATH" "$ENROLL_DIR/password.txt"
  install -m 600 -o ubuntu -g ubuntu "$QR_PATH" "$ENROLL_DIR/otp.png"
  cat > "$ENROLL_DIR/index.html" <<'EOF'
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="referrer" content="no-referrer">
<title>ShopVivaliz RDP Enrollment</title>
<style>
body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:32px}
.card{max-width:780px;margin:auto;background:#111827;border:1px solid #334155;border-radius:18px;padding:28px}
.pw{font-family:ui-monospace,monospace;font-size:22px;background:#020617;padding:14px;border-radius:10px;word-break:break-all}
img{display:block;max-width:380px;width:100%;margin:24px auto;background:white;padding:12px;border-radius:12px}
.note{color:#cbd5e1;line-height:1.5}
</style>
</head>
<body><div class="card">
<h1>ShopVivaliz VM - RDP Seguro</h1>
<p>Usuario: <b>fredrdp</b></p>
<p>Senha RDP:</p>
<div id="pw" class="pw">carregando...</div>
<p class="note">Cadastre o QR abaixo no Authenticator. No RDP, use a senha acima seguida imediatamente do codigo TOTP de 6 digitos.</p>
<img src="otp.png" alt="QR TOTP">
<p class="note">Pagina temporaria, servida apenas em 127.0.0.1 dentro da VM.</p>
<script>
fetch('password.txt',{cache:'no-store'})
  .then(r=>{if(!r.ok) throw new Error('load'); return r.text()})
  .then(t=>document.getElementById('pw').textContent=t.trim())
  .catch(()=>document.getElementById('pw').textContent='erro ao carregar');
</script>
</div></body></html>
EOF
  chown ubuntu:ubuntu "$ENROLL_DIR/index.html"
  chmod 600 "$ENROLL_DIR/index.html"

  if [ -s "$ENROLL_SESSION_FILE" ]; then
    local old_session
    old_session="$(cat "$ENROLL_SESSION_FILE")"
    if [ -n "$old_session" ]; then
      if ! curl -fsS -X POST "$BROWSER_API/sessions/$old_session/close" >/dev/null 2>&1; then
        echo "PRIVATE_RDP_WARN=previous_enrollment_session_close_failed" >&2
      fi
    fi
    unset old_session
  fi

  if [ -s "$ENROLL_PID_FILE" ]; then
    local old_pid
    old_pid="$(cat "$ENROLL_PID_FILE")"
    if [ -n "$old_pid" ] && kill -0 "$old_pid" 2>/dev/null; then
      if ! kill "$old_pid"; then
        echo "PRIVATE_RDP_WARN=previous_enrollment_server_stop_failed" >&2
      fi
    fi
    unset old_pid
  fi

  runuser -u ubuntu -- sh -c "nohup python3 -m http.server 18777 --bind 127.0.0.1 --directory '$ENROLL_DIR' >'$ENROLL_DIR/server.log' 2>&1 </dev/null & echo \$! > '$ENROLL_PID_FILE'"

  local ready
  ready=false
  for _ in $(seq 1 20); do
    if curl -fsS --connect-timeout 1 --max-time 2 "http://127.0.0.1:$ENROLL_PORT/index.html" >/dev/null; then
      ready=true
      break
    fi
    sleep 1
  done
  if [ "$ready" != true ]; then
    echo "PRIVATE_RDP_ERROR=enrollment_server_not_ready" >&2
    exit 34
  fi

  local response session_id
  response="$(curl -fsS -X POST "$BROWSER_API/sessions" -H 'Content-Type: application/json' --data '{"url":"http://127.0.0.1:18777/index.html","label":"rdp-enrollment","origin":"rdp-secure-setup","persistent":false,"ttl_seconds":1800}')"
  session_id="$(printf '%s' "$response" | python3 -c 'import json,sys; d=json.load(sys.stdin); s=d.get("session") or {}; print(s.get("id","") if d.get("ok") is True and s.get("label")=="rdp-enrollment" else "")')"
  unset response
  if [ -z "$session_id" ]; then
    echo "PRIVATE_RDP_ERROR=enrollment_session_create_failed" >&2
    exit 35
  fi
  printf '%s\n' "$session_id" > "$ENROLL_SESSION_FILE"
  chown ubuntu:ubuntu "$ENROLL_SESSION_FILE"
  chmod 600 "$ENROLL_SESSION_FILE"
  unset session_id

  echo "RDP_ENROLLMENT_SESSION_READY=true"
  echo "RDP_ENROLLMENT_LABEL=rdp-enrollment"
  echo "RDP_ENROLLMENT_TTL_SECONDS=1800"
}

cleanup_enrollment() {
  if [ -s "$ENROLL_SESSION_FILE" ]; then
    local session_id
    session_id="$(cat "$ENROLL_SESSION_FILE")"
    if [ -n "$session_id" ]; then
      if ! curl -fsS -X POST "$BROWSER_API/sessions/$session_id/close" >/dev/null 2>&1; then
        echo "PRIVATE_RDP_WARN=enrollment_session_close_failed" >&2
      fi
    fi
    unset session_id
  fi

  if [ -s "$ENROLL_PID_FILE" ]; then
    local server_pid
    server_pid="$(cat "$ENROLL_PID_FILE")"
    if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
      if ! kill "$server_pid"; then
        echo "PRIVATE_RDP_WARN=enrollment_server_stop_failed" >&2
      fi
    fi
    unset server_pid
  fi

  rm -rf "$ENROLL_DIR"
  echo "RDP_ENROLLMENT_CLEANUP=PASS"
}

case "$ACTION" in
  prepare) prepare ;;
  status) print_status ;;
  otp_prepare) prepare_otp ;;
  enable_otp) enable_otp ;;
  enrollment_publish) publish_enrollment ;;
  enrollment_cleanup) cleanup_enrollment ;;
  *)
    echo "PRIVATE_RDP_ERROR=unsupported_action" >&2
    exit 64
    ;;
esac
