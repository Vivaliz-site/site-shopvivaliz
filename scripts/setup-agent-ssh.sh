#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
HOST="$(hostname)"
SITE_HOST="shopvivaliz-free-a1"
BACKEND_HOST="always-free-arm-1787907847-26"
AGENT_USER="shopvivaliz-agent"
AGENT_HOME="/home/$AGENT_USER"
AUTHORIZED_KEY="${SHOPVIVALIZ_AGENT_SSH_PUBKEY:-}"

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

install_agent_ssh() {
  require_root
  assert_host
  [ -n "$AUTHORIZED_KEY" ] || die public_key_required 30

  if ! id "$AGENT_USER" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$AGENT_USER"
  fi

  install -d -m 700 -o "$AGENT_USER" -g "$AGENT_USER" "$AGENT_HOME/.ssh"
  printf '%s\n' "$AUTHORIZED_KEY" > "$AGENT_HOME/.ssh/authorized_keys"
  chown "$AGENT_USER:$AGENT_USER" "$AGENT_HOME/.ssh/authorized_keys"
  chmod 600 "$AGENT_HOME/.ssh/authorized_keys"

  cat >/etc/ssh/sshd_config.d/70-shopvivaliz-agent.conf <<EOF
Match User $AGENT_USER
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    PubkeyAuthentication yes
    PermitTTY yes
    X11Forwarding no
    AllowTcpForwarding no
    PermitTunnel no
    GatewayPorts no
    PermitUserEnvironment no
EOF

  cat >/etc/sudoers.d/shopvivaliz-agent <<'EOF'
Cmnd_Alias SHOPVIVALIZ_AGENT_READ = /usr/bin/hostname, /usr/bin/whoami, /usr/bin/uptime, /usr/bin/df, /usr/bin/du, /usr/bin/git, /usr/bin/journalctl, /usr/bin/systemctl status *, /usr/bin/systemctl is-active *, /usr/bin/systemctl is-enabled *, /usr/bin/ss, /usr/bin/curl
Cmnd_Alias SHOPVIVALIZ_AGENT_OPS = /usr/bin/systemctl restart shopvivaliz-24x7.service, /usr/bin/systemctl restart agent-bridge.service, /usr/bin/systemctl restart shopvivaliz-mcp.service, /usr/bin/systemctl restart mei-mg-email.service, /usr/bin/systemctl restart rustdesk.service, /usr/bin/systemctl restart anydesk.service
$AGENT_USER ALL=(root) NOPASSWD: SHOPVIVALIZ_AGENT_READ, SHOPVIVALIZ_AGENT_OPS
Defaults:$AGENT_USER !authenticate,log_output
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
    else
      echo "AGENT_SSH_AUTHORIZED_KEY_PRESENT=false"
    fi
  else
    echo "AGENT_SSH_USER_PRESENT=false"
  fi
  if [ -f /etc/ssh/sshd_config.d/70-shopvivaliz-agent.conf ]; then
    echo "AGENT_SSH_POLICY_PRESENT=true"
  else
    echo "AGENT_SSH_POLICY_PRESENT=false"
  fi
  if [ -f /etc/sudoers.d/shopvivaliz-agent ] && visudo -cf /etc/sudoers.d/shopvivaliz-agent >/dev/null 2>&1; then
    echo "AGENT_SSH_SUDOERS_VALID=true"
  else
    echo "AGENT_SSH_SUDOERS_VALID=false"
  fi
  sshd -T 2>/dev/null | grep -q '^permitrootlogin no$' && echo "AGENT_SSH_ROOT_LOGIN_DISABLED=true" || echo "AGENT_SSH_ROOT_LOGIN_DISABLED=unknown"
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
