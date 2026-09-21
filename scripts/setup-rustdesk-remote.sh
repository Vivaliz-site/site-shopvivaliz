#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
HOST="$(hostname)"
BACKEND_HOST="always-free-arm-1787907847-26"
SITE_HOST="shopvivaliz-free-a1"
SERVER_PRIVATE_IP="10.0.1.38"
SERVER_TAILSCALE_IP="100.66.174.74"
SERVER_ROOT="/opt/shopvivaliz-rustdesk-server"
CLIENT_SERVER="${RUSTDESK_ID_SERVER:-$SERVER_PRIVATE_IP}"
CLIENT_KEY="${RUSTDESK_SERVER_KEY:-}"
PASSWORD_FILE="/etc/shopvivaliz/rustdesk-unattended-password"

die() { echo "RUSTDESK_ERROR=$1" >&2; exit "${2:-1}"; }

require_root() {
  [ "$(id -u)" -eq 0 ] || die root_required 20
}

assert_host() {
  case "$HOST" in
    "$SITE_HOST"|"$BACKEND_HOST") ;;
    *) die host_mismatch 21 ;;
  esac
}

install_client_package() {
  command -v curl >/dev/null 2>&1 || { apt-get update -qq; DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl ca-certificates python3; }
  tmp="$(mktemp --suffix=.deb)"
  trap 'rm -f "$tmp"' RETURN
  url="$(python3 - <<'PY'
import json, urllib.request
req=urllib.request.Request("https://api.github.com/repos/rustdesk/rustdesk/releases/latest",headers={"User-Agent":"shopvivaliz-rustdesk-bootstrap"})
with urllib.request.urlopen(req, timeout=30) as r:
    data=json.load(r)
assets=data.get("assets") or []
candidates=[]
for a in assets:
    n=(a.get("name") or "").lower()
    if n.endswith(".deb") and ("aarch64" in n or "arm64" in n):
        candidates.append(a.get("browser_download_url"))
if not candidates:
    raise SystemExit("no arm64 .deb asset in latest RustDesk release")
print(candidates[0])
PY
)"
  [ -n "$url" ] || die client_download_url_missing 30
  curl -fL --retry 3 --connect-timeout 15 "$url" -o "$tmp"
  apt-get update -qq
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "$tmp"
  systemctl daemon-reload
  if systemctl list-unit-files rustdesk.service >/dev/null 2>&1; then systemctl enable rustdesk.service >/dev/null; fi
}

configure_client_profile() {
  local home="$1" owner="$2" server="$3" key="$4"
  local cfgdir="$home/.config/rustdesk" cfg="$cfgdir/RustDesk2.toml"
  install -d -m 700 -o "$owner" -g "$owner" "$cfgdir"
  cat >"$cfg" <<EOF
rendezvous_server = '$server:21116'
nat_type = 1
serial = 0

[options]
custom-rendezvous-server = '$server'
relay-server = '$server'
key = '$key'
EOF
  chown "$owner:$owner" "$cfg"
  chmod 600 "$cfg"
}

ensure_unattended_password() {
  install -d -m 700 /etc/shopvivaliz
  if [ ! -s "$PASSWORD_FILE" ]; then
    umask 077
    python3 - <<'PY' >"$PASSWORD_FILE"
import secrets
alphabet="ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%_-"
print("".join(secrets.choice(alphabet) for _ in range(28)))
PY
  fi
  chmod 600 "$PASSWORD_FILE"
  if command -v rustdesk >/dev/null 2>&1; then
    if rustdesk --help 2>&1 | grep -q -- '--password'; then
      if ! rustdesk --password < "$PASSWORD_FILE" >/dev/null 2>&1; then die unattended_password_set_failed 32; fi
    fi
  fi
  echo "RUSTDESK_PASSWORD_SOURCE=local_root_only"
}

install_client() {
  require_root
  assert_host
  [ -n "$CLIENT_KEY" ] || die server_key_required 31
  if systemctl is-active --quiet rustdesk.service; then systemctl stop rustdesk.service; fi
  install_client_package
  if systemctl is-active --quiet rustdesk.service; then systemctl stop rustdesk.service; fi

  configure_client_profile /root root "$CLIENT_SERVER" "$CLIENT_KEY"
  for u in fredconsole fredrdp ubuntu; do
    if id "$u" >/dev/null 2>&1; then
      home="$(getent passwd "$u" | cut -d: -f6)"
      [ -n "$home" ] && [ -d "$home" ] && configure_client_profile "$home" "$u" "$CLIENT_SERVER" "$CLIENT_KEY"
    fi
  done

  ensure_unattended_password
  systemctl enable --now rustdesk.service >/dev/null
  sleep 3
  echo "RUSTDESK_CLIENT_INSTALL=PASS"
  status
}

install_rustdesk_firewall() {
  cat >/usr/local/sbin/shopvivaliz-rustdesk-firewall <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
CHAIN=SHOPVIVALIZ_RUSTDESK
iptables -N "$CHAIN" 2>/dev/null || true
iptables -F "$CHAIN"
iptables -A "$CHAIN" -s 10.0.0.0/8 -j ACCEPT
iptables -A "$CHAIN" -s 100.64.0.0/10 -j ACCEPT
iptables -A "$CHAIN" -j REJECT

iptables -C INPUT -p tcp -m multiport --dports 21115,21116,21117 -j "$CHAIN" 2>/dev/null ||
  iptables -I INPUT 1 -p tcp -m multiport --dports 21115,21116,21117 -j "$CHAIN"
iptables -C INPUT -p udp --dport 21116 -j "$CHAIN" 2>/dev/null ||
  iptables -I INPUT 1 -p udp --dport 21116 -j "$CHAIN"

iptables -C INPUT -p tcp -m multiport --dports 21118,21119 -j REJECT 2>/dev/null ||
  iptables -I INPUT 1 -p tcp -m multiport --dports 21118,21119 -j REJECT
EOF
  chown root:root /usr/local/sbin/shopvivaliz-rustdesk-firewall
  chmod 0755 /usr/local/sbin/shopvivaliz-rustdesk-firewall

  cat >/etc/systemd/system/shopvivaliz-rustdesk-firewall.service <<'EOF'
[Unit]
Description=ShopVivaliz RustDesk private-network firewall
After=network-pre.target
Before=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/shopvivaliz-rustdesk-firewall
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now shopvivaliz-rustdesk-firewall.service
}

install_server() {
  require_root
  assert_host
  [ "$HOST" = "$BACKEND_HOST" ] || die server_must_run_on_backend 40
  if ! command -v docker >/dev/null 2>&1; then
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq docker.io docker-compose-v2
  fi
  systemctl enable --now docker
  install -d -m 700 "$SERVER_ROOT/data"
  cat >"$SERVER_ROOT/compose.yml" <<EOF
services:
  hbbs:
    container_name: shopvivaliz-rustdesk-hbbs
    image: rustdesk/rustdesk-server:latest
    command: hbbs
    volumes:
      - $SERVER_ROOT/data:/root
    network_mode: "host"
    depends_on:
      - hbbr
    restart: unless-stopped
  hbbr:
    container_name: shopvivaliz-rustdesk-hbbr
    image: rustdesk/rustdesk-server:latest
    command: hbbr
    volumes:
      - $SERVER_ROOT/data:/root
    network_mode: "host"
    restart: unless-stopped
EOF
  docker compose -f "$SERVER_ROOT/compose.yml" pull
  docker compose -f "$SERVER_ROOT/compose.yml" up -d
  for _ in $(seq 1 30); do
    [ -s "$SERVER_ROOT/data/id_ed25519.pub" ] && break
    sleep 1
  done
  [ -s "$SERVER_ROOT/data/id_ed25519.pub" ] || die server_key_not_generated 41
  if [ -f "$SERVER_ROOT/data/id_ed25519" ]; then chmod 600 "$SERVER_ROOT/data/id_ed25519"; fi
  chmod 644 "$SERVER_ROOT/data/id_ed25519.pub"

  command -v iptables >/dev/null 2>&1 || die iptables_missing 46
  install_rustdesk_firewall

  docker ps --format '{{.Names}} {{.Status}}' | grep -q '^shopvivaliz-rustdesk-hbbs ' || die hbbs_not_running 42
  docker ps --format '{{.Names}} {{.Status}}' | grep -q '^shopvivaliz-rustdesk-hbbr ' || die hbbr_not_running 43
  ss -lnt | grep -q ':21116 ' || die hbbs_port_missing 44
  ss -lnt | grep -q ':21117 ' || die hbbr_port_missing 45
  echo "RUSTDESK_SERVER_INSTALL=PASS"
  echo "RUSTDESK_SERVER_PRIVATE=$SERVER_PRIVATE_IP"
  echo "RUSTDESK_SERVER_TAILSCALE=$SERVER_TAILSCALE_IP"
  echo "RUSTDESK_SERVER_KEY_PRESENT=true"
}

status() {
  assert_host
  echo "RUSTDESK_HOST=$HOST"
  if command -v rustdesk >/dev/null 2>&1; then
    echo "RUSTDESK_CLIENT_INSTALLED=true"
    if version_value="$(rustdesk --version 2>/dev/null | head -1)"; then
      echo "RUSTDESK_CLIENT_VERSION=$version_value"
    else
      echo "RUSTDESK_CLIENT_VERSION=unavailable"
    fi
    if idv="$(timeout 10 rustdesk --get-id 2>/dev/null)"; then :; else idv=""; fi
    [ -n "$idv" ] && echo "RUSTDESK_ID=$idv" || echo "RUSTDESK_ID=unavailable"
  else
    echo "RUSTDESK_CLIENT_INSTALLED=false"
  fi
  if svc="$(systemctl is-active rustdesk.service 2>/dev/null)"; then :; else svc="inactive"; fi
  echo "RUSTDESK_CLIENT_SERVICE=${svc:-unknown}"
  if [ "$HOST" = "$BACKEND_HOST" ]; then
    if command -v docker >/dev/null 2>&1 && [ -f "$SERVER_ROOT/compose.yml" ]; then
      if hbbs="$(docker inspect -f '{{.State.Status}}' shopvivaliz-rustdesk-hbbs 2>/dev/null)"; then :; else hbbs="missing"; fi
      if hbbr="$(docker inspect -f '{{.State.Status}}' shopvivaliz-rustdesk-hbbr 2>/dev/null)"; then :; else hbbr="missing"; fi
      echo "RUSTDESK_HBBS=${hbbs:-missing}"
      echo "RUSTDESK_HBBR=${hbbr:-missing}"
      [ -s "$SERVER_ROOT/data/id_ed25519.pub" ] && echo "RUSTDESK_SERVER_KEY_PRESENT=true" || echo "RUSTDESK_SERVER_KEY_PRESENT=false"
      if systemctl is-active --quiet shopvivaliz-rustdesk-firewall.service; then
        echo "RUSTDESK_FIREWALL=active"
      else
        echo "RUSTDESK_FIREWALL=inactive"
      fi
    else
      echo "RUSTDESK_HBBS=missing"
      echo "RUSTDESK_HBBR=missing"
    fi
  fi
  if loginctl list-sessions --no-legend 2>/dev/null | grep -qE 'fredconsole|fredrdp'; then
    echo "RUSTDESK_GUI_SESSION_PRESENT=true"
  else
    echo "RUSTDESK_GUI_SESSION_PRESENT=false"
  fi
}

case "$ACTION" in
  server_install) install_server ;;
  client_install) install_client ;;
  status) status ;;
  *) die unsupported_action 64 ;;
esac
