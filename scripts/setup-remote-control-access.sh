#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
SERVER_SOURCE="${2:-}"
UNIT_SOURCE="${3:-}"
HOST="$(hostname)"
BACKEND_HOST="always-free-arm-1787907847-26"
SITE_HOST="shopvivaliz-free-a1"
STATE_DIR="/var/lib/shopvivaliz-remote-control"
INSTALL_DIR="/opt/shopvivaliz-remote-control"
SERVICE="shopvivaliz-remote-control-mcp.service"
REMOTE_USER="shopvivaliz-remote"
PUBKEY="${SHOPVIVALIZ_REMOTE_CONTROL_PUBKEY:-}"

die() { echo "REMOTE_CONTROL_SETUP_ERROR=$1" >&2; exit "${2:-1}"; }
require_root() { [ "$(id -u)" -eq 0 ] || die root_required 20; }

install_controller() {
  require_root
  [ "$HOST" = "$BACKEND_HOST" ] || die controller_host_mismatch 21
  [ -n "$SERVER_SOURCE" ] && [ -f "$SERVER_SOURCE" ] || die server_source_required 22
  [ -n "$UNIT_SOURCE" ] && [ -f "$UNIT_SOURCE" ] || die unit_source_required 23

  install -d -m 700 -o root -g root "$STATE_DIR"
  install -d -m 755 -o root -g root "$INSTALL_DIR"
  install -m 0755 -o root -g root "$SERVER_SOURCE" "$INSTALL_DIR/server.py"

  if [ ! -s "$STATE_DIR/id_ed25519" ]; then
    ssh-keygen -q -t ed25519 -N '' -C shopvivaliz-remote-control -f "$STATE_DIR/id_ed25519"
  fi
  chmod 600 "$STATE_DIR/id_ed25519"
  chmod 644 "$STATE_DIR/id_ed25519.pub"
  touch "$STATE_DIR/known_hosts"
  chmod 600 "$STATE_DIR/known_hosts"

  token_file="$STATE_DIR/mcp-token"
  env_file="$STATE_DIR/service.env"
  if [ ! -s "$token_file" ]; then
    umask 077
    openssl rand -hex 32 > "$token_file"
  fi
  chmod 600 "$token_file"
  {
    printf 'SHOPVIVALIZ_REMOTE_MCP_TOKEN='
    cat "$token_file"
  } > "$env_file"
  chmod 600 "$env_file"

  install -m 0644 -o root -g root "$UNIT_SOURCE" "/etc/systemd/system/$SERVICE"

  systemctl daemon-reload
  systemctl enable --now "$SERVICE"
  sleep 2
  systemctl is-active --quiet "$SERVICE"
  curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:5580/health >/dev/null
  echo "REMOTE_CONTROL_CONTROLLER_INSTALL=PASS"
  echo "REMOTE_CONTROL_PUBLIC_KEY_FILE=$STATE_DIR/id_ed25519.pub"
}

install_target() {
  require_root
  [ "$HOST" = "$SITE_HOST" ] || die target_host_mismatch 31
  [ -n "$PUBKEY" ] || die public_key_required 32
  printf '%s' "$PUBKEY" | grep -Eq '^ssh-(ed25519|rsa) ' || die invalid_public_key 33

  if ! id "$REMOTE_USER" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$REMOTE_USER"
  fi
  if ! passwd -l "$REMOTE_USER" >/dev/null 2>&1; then
    die password_lock_failed 34
  fi
  home="/home/$REMOTE_USER"
  install -d -m 700 -o "$REMOTE_USER" -g "$REMOTE_USER" "$home/.ssh"
  {
    printf 'from="10.0.0.0/8,100.64.0.0/10",no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-user-rc '
    printf '%s\n' "$PUBKEY"
  } >"$home/.ssh/authorized_keys"
  chown "$REMOTE_USER:$REMOTE_USER" "$home/.ssh/authorized_keys"
  chmod 600 "$home/.ssh/authorized_keys"

  cat >"/etc/ssh/sshd_config.d/71-shopvivaliz-remote-control.conf" <<EOF
Match User $REMOTE_USER
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthenticationMethods publickey
    PubkeyAuthentication yes
    PermitTTY no
    X11Forwarding no
    AllowTcpForwarding no
    PermitTunnel no
    GatewayPorts no
    PermitUserRC no
EOF

  cat >"/etc/sudoers.d/shopvivaliz-remote-control" <<EOF
$REMOTE_USER ALL=(ALL) NOPASSWD: ALL
Defaults:$REMOTE_USER !authenticate,use_pty,log_output
EOF
  chmod 440 /etc/sudoers.d/shopvivaliz-remote-control
  visudo -cf /etc/sudoers.d/shopvivaliz-remote-control >/dev/null
  sshd -t
  systemctl reload ssh.service 2>/dev/null || systemctl reload sshd.service
  echo "REMOTE_CONTROL_TARGET_INSTALL=PASS"
}

status() {
  echo "REMOTE_CONTROL_HOST=$HOST"
  if [ "$HOST" = "$BACKEND_HOST" ]; then
    systemctl is-enabled "$SERVICE"
    systemctl is-active "$SERVICE"
    curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:5580/health
  elif [ "$HOST" = "$SITE_HOST" ]; then
    id "$REMOTE_USER" >/dev/null 2>&1 && echo "REMOTE_CONTROL_USER_PRESENT=true" || echo "REMOTE_CONTROL_USER_PRESENT=false"
    [ -s "/home/$REMOTE_USER/.ssh/authorized_keys" ] && echo "REMOTE_CONTROL_KEY_PRESENT=true" || echo "REMOTE_CONTROL_KEY_PRESENT=false"
    visudo -cf /etc/sudoers.d/shopvivaliz-remote-control >/dev/null 2>&1 && echo "REMOTE_CONTROL_SUDO_VALID=true" || echo "REMOTE_CONTROL_SUDO_VALID=false"
  else
    die unsupported_host 40
  fi
}

case "$ACTION" in
  install-controller) install_controller ;;
  install-target) install_target ;;
  status) status ;;
  *) die unsupported_action 64 ;;
esac
