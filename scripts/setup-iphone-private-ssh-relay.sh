#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
BACKEND_HOST="always-free-arm-1787907847-26"
SITE_PRIVATE_IP="10.0.1.112"
RELAY_PORT="2224"
SOCKET_UNIT="shopvivaliz-site-ssh-relay.socket"
SERVICE_UNIT="shopvivaliz-site-ssh-relay.service"
SYSTEMD_DIR="/etc/systemd/system"

die() {
  echo "IPHONE_SSH_RELAY_ERROR=$1" >&2
  exit "${2:-1}"
}

require_root() {
  [ "$(id -u)" -eq 0 ] || die root_required 20
}

assert_backend_host() {
  [ "$(hostname)" = "$BACKEND_HOST" ] || die host_mismatch 21
}

tailscale_ipv4() {
  command -v tailscale >/dev/null 2>&1 || die tailscale_missing 30
  local tailscale_ip
  tailscale_ip="$(tailscale ip -4 2>/dev/null | head -1 || true)"
  [ -n "$tailscale_ip" ] || die tailscale_ipv4_missing 31
  case "$tailscale_ip" in
    100.*) ;;
    *) die tailscale_ipv4_unexpected 32 ;;
  esac
  printf '%s\n' "$tailscale_ip"
}

socket_proxy_binary() {
  local candidate
  for candidate in /usr/lib/systemd/systemd-socket-proxyd /lib/systemd/systemd-socket-proxyd; do
    if [ -x "$candidate" ]; then
      printf '%s\n' "$candidate"
      return 0
    fi
  done
  command -v systemd-socket-proxyd 2>/dev/null || die systemd_socket_proxyd_missing 33
}

site_ssh_reachable() {
  timeout 5 bash -c "</dev/tcp/$SITE_PRIVATE_IP/22" >/dev/null 2>&1
}

host_key_digest() {
  local host="$1"
  local port="$2"
  ssh-keyscan -T 5 -p "$port" "$host" 2>/dev/null |
    awk '$1 !~ /^#/ && NF >= 3 {print $2 " " $3}' |
    sort -u |
    sha256sum |
    awk '{print $1}'
}

status() {
  assert_backend_host
  local tailscale_ip socket_state enabled_state listener site_ok relay_digest site_digest handshake_ok
  tailscale_ip="$(tailscale_ipv4)"

  socket_state="$(systemctl is-active "$SOCKET_UNIT" 2>/dev/null || true)"
  enabled_state="$(systemctl is-enabled "$SOCKET_UNIT" 2>/dev/null || true)"
  listener="$(ss -H -ltn "sport = :$RELAY_PORT" 2>/dev/null | head -1 || true)"

  echo "IPHONE_SSH_RELAY_HOST=$BACKEND_HOST"
  echo "IPHONE_SSH_RELAY_TAILSCALE_IP=$tailscale_ip"
  echo "IPHONE_SSH_RELAY_PORT=$RELAY_PORT"
  echo "IPHONE_SSH_RELAY_SOCKET_STATE=${socket_state:-unknown}"
  echo "IPHONE_SSH_RELAY_SOCKET_ENABLED=${enabled_state:-unknown}"

  if [ "$socket_state" = active ] &&
     printf '%s\n' "$listener" | grep -Fq "$tailscale_ip:$RELAY_PORT" &&
     ! printf '%s\n' "$listener" | grep -Eq '0\.0\.0\.0:2224|\[::\]:2224'; then
    echo "IPHONE_SSH_RELAY_LISTENER_PRIVATE=true"
  else
    echo "IPHONE_SSH_RELAY_LISTENER_PRIVATE=false"
    return 41
  fi

  site_ok=false
  if site_ssh_reachable; then
    site_ok=true
  fi
  echo "IPHONE_SSH_RELAY_SITE_PRIVATE_SSH_REACHABLE=$site_ok"
  [ "$site_ok" = true ] || return 42

  relay_digest="$(host_key_digest "$tailscale_ip" "$RELAY_PORT")"
  site_digest="$(host_key_digest "$SITE_PRIVATE_IP" 22)"
  handshake_ok=false
  if [ -n "$relay_digest" ] && [ "$relay_digest" = "$site_digest" ]; then
    handshake_ok=true
  fi
  if [ "$handshake_ok" = true ]; then
    echo "IPHONE_SSH_RELAY_HANDSHAKE=PASS"
  else
    echo "IPHONE_SSH_RELAY_HANDSHAKE=FAIL"
    return 43
  fi

  echo "IPHONE_SSH_RELAY_STATUS=PASS"
}

install_relay() {
  require_root
  assert_backend_host

  local tailscale_ip proxy_bin
  tailscale_ip="$(tailscale_ipv4)"
  proxy_bin="$(socket_proxy_binary)"
  site_ssh_reachable || die site_private_ssh_unreachable 34

  cat >"$SYSTEMD_DIR/$SOCKET_UNIT" <<EOF
[Unit]
Description=ShopVivaliz private mobile SSH relay to site A1
After=network-online.target tailscaled.service
Wants=network-online.target tailscaled.service

[Socket]
ListenStream=$tailscale_ip:$RELAY_PORT
NoDelay=true

[Install]
WantedBy=sockets.target
EOF

  cat >"$SYSTEMD_DIR/$SERVICE_UNIT" <<EOF
[Unit]
Description=ShopVivaliz private mobile SSH proxy to site A1
Requires=$SOCKET_UNIT
After=network-online.target

[Service]
Type=notify
ExecStart=$proxy_bin $SITE_PRIVATE_IP:22
DynamicUser=yes
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictSUIDSGID=yes
LockPersonality=yes
RestrictAddressFamilies=AF_INET AF_INET6
EOF

  chmod 0644 "$SYSTEMD_DIR/$SOCKET_UNIT" "$SYSTEMD_DIR/$SERVICE_UNIT"
  systemd-analyze verify "$SYSTEMD_DIR/$SOCKET_UNIT" "$SYSTEMD_DIR/$SERVICE_UNIT"
  systemctl daemon-reload
  systemctl enable --now "$SOCKET_UNIT"

  local i
  for i in $(seq 1 10); do
    if ss -H -ltn "sport = :$RELAY_PORT" 2>/dev/null | grep -Fq "$tailscale_ip:$RELAY_PORT"; then
      break
    fi
    sleep 1
  done

  echo "IPHONE_SSH_RELAY_INSTALL=PASS"
  status
}

case "$ACTION" in
  install) install_relay ;;
  status) status ;;
  *) die unsupported_action 64 ;;
esac
