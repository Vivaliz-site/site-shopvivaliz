#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
HOST="$(hostname)"
SITE_HOST="shopvivaliz-free-a1"
BACKEND_HOST="always-free-arm-1787907847-26"
AGENT_USER="shopvivaliz-agent"
AGENT_HOME="/home/$AGENT_USER"
AUTHORIZED_KEY="${SHOPVIVALIZ_AGENT_SSH_PUBKEY:-}"
OPS_WRAPPER="/usr/local/sbin/shopvivaliz-agent-ops"

die() { echo "AGENT_SSH_ERROR=$1" >&2; exit "${2:-1}"; }

require_root() {
  [ "$(id -u)" -eq 0 ] || die root_required 20
}

assert_host() {
  case "$HOST" in
    "$SITE_HOST"|"$BACKEND_HOST") ;;
    *) die host_mismatch 21 ;;
  esac
}

install_ops_wrapper() {
  cat >"$OPS_WRAPPER" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail

cmd="${1:-}"
arg="${2:-}"

allowed_service() {
  case "$1" in
    shopvivaliz-24x7.service|agent-bridge.service|shopvivaliz-mcp.service|mei-mg-email.service|rustdesk.service|anydesk.service|apache2.service|shopvivaliz-queue-worker.service|shopvivaliz-token-renewer.service)
      return 0 ;;
    *) return 1 ;;
  esac
}

case "$cmd" in
  host-status)
    hostname
    uptime
    df -h /
    ;;
  service-status)
    allowed_service "$arg" || { echo "AGENT_OPS_ERROR=service_not_allowed" >&2; exit 64; }
    if ! SYSTEMD_PAGER=cat systemctl --no-pager status "$arg"; then echo "AGENT_OPS_WARN=service_status_nonzero"; fi
    if state="$(systemctl is-active "$arg" 2>/dev/null)"; then echo "$state"; else echo "inactive"; fi
    ;;
  service-restart)
    allowed_service "$arg" || { echo "AGENT_OPS_ERROR=service_not_allowed" >&2; exit 64; }
    systemctl restart "$arg"
    systemctl is-active "$arg"
    ;;
  rustdesk-status)
    if state="$(systemctl is-active rustdesk.service 2>/dev/null)"; then echo "$state"; else echo "inactive"; fi
    if command -v docker >/dev/null 2>&1; then
      if docker_state="$(docker inspect -f '{{.Name}} {{.State.Status}}' shopvivaliz-rustdesk-hbbs shopvivaliz-rustdesk-hbbr 2>/dev/null)"; then
        printf '%s\n' "$docker_state"
      else
        echo "AGENT_OPS_WARN=rustdesk_containers_unavailable"
      fi
    fi
    ;;
  browser-status)
    curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:17777/health
    ;;
  *)
    echo "AGENT_OPS_ERROR=unsupported_command" >&2
    exit 64
    ;;
esac
EOF
  chown root:root "$OPS_WRAPPER"
  chmod 0755 "$OPS_WRAPPER"
}

install_agent_ssh() {
  require_root
  assert_host
  [ -n "$AUTHORIZED_KEY" ] || die public_key_required 30
  printf '%s' "$AUTHORIZED_KEY" | grep -Eq '^ssh-(ed25519|rsa) ' || die invalid_public_key 31

  if ! id "$AGENT_USER" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$AGENT_USER"
  fi
  if ! passwd -l "$AGENT_USER" >/dev/null 2>&1; then die password_lock_failed 32; fi

  install -d -m 700 -o "$AGENT_USER" -g "$AGENT_USER" "$AGENT_HOME/.ssh"
  {
    printf 'from="10.0.0.0/8,100.64.0.0/10",no-agent-forwarding,no-port-forwarding,no-X11-forwarding,no-user-rc '
    printf '%s\n' "$AUTHORIZED_KEY"
  } > "$AGENT_HOME/.ssh/authorized_keys"
  chown "$AGENT_USER:$AGENT_USER" "$AGENT_HOME/.ssh/authorized_keys"
  chmod 600 "$AGENT_HOME/.ssh/authorized_keys"

  cat >/etc/ssh/sshd_config.d/70-shopvivaliz-agent.conf <<EOF
Match User $AGENT_USER
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    AuthenticationMethods publickey
    PubkeyAuthentication yes
    PermitTTY yes
    X11Forwarding no
    AllowTcpForwarding no
    PermitTunnel no
    GatewayPorts no
EOF

  install_ops_wrapper

  cat >/etc/sudoers.d/shopvivaliz-agent <<EOF
$AGENT_USER ALL=(root) NOPASSWD: $OPS_WRAPPER *
Defaults:$AGENT_USER !authenticate,use_pty,log_output
EOF
  chmod 440 /etc/sudoers.d/shopvivaliz-agent
  visudo -cf /etc/sudoers.d/shopvivaliz-agent >/dev/null

  sshd -t
  systemctl reload ssh.service 2>/dev/null || systemctl reload sshd.service

  echo "AGENT_SSH_INSTALL=PASS"
  status
}

status() {
  assert_host
  echo "AGENT_SSH_HOST=$HOST"
  if id "$AGENT_USER" >/dev/null 2>&1; then
    echo "AGENT_SSH_USER_PRESENT=true"
    if [ -s "$AGENT_HOME/.ssh/authorized_keys" ]; then
      echo "AGENT_SSH_AUTHORIZED_KEY_PRESENT=true"
      grep -q 'from="10.0.0.0/8,100.64.0.0/10"' "$AGENT_HOME/.ssh/authorized_keys" &&
        echo "AGENT_SSH_PRIVATE_SOURCE_RESTRICTION=true" ||
        echo "AGENT_SSH_PRIVATE_SOURCE_RESTRICTION=false"
    else
      echo "AGENT_SSH_AUTHORIZED_KEY_PRESENT=false"
      echo "AGENT_SSH_PRIVATE_SOURCE_RESTRICTION=false"
    fi
  else
    echo "AGENT_SSH_USER_PRESENT=false"
  fi
  [ -f /etc/ssh/sshd_config.d/70-shopvivaliz-agent.conf ] &&
    echo "AGENT_SSH_POLICY_PRESENT=true" ||
    echo "AGENT_SSH_POLICY_PRESENT=false"
  if [ -f /etc/sudoers.d/shopvivaliz-agent ] && visudo -cf /etc/sudoers.d/shopvivaliz-agent >/dev/null 2>&1; then
    echo "AGENT_SSH_SUDOERS_VALID=true"
  else
    echo "AGENT_SSH_SUDOERS_VALID=false"
  fi
  [ -x "$OPS_WRAPPER" ] &&
    echo "AGENT_SSH_OPS_WRAPPER_PRESENT=true" ||
    echo "AGENT_SSH_OPS_WRAPPER_PRESENT=false"
  if command -v tailscale >/dev/null 2>&1; then
    if ts="$(tailscale ip -4 2>/dev/null | head -1)"; then :; else ts=""; fi
    [ -n "$ts" ] && echo "AGENT_SSH_TAILSCALE_IP=$ts"
  fi
}

case "$ACTION" in
  install) install_agent_ssh ;;
  status) status ;;
  *) die unsupported_action 64 ;;
esac
