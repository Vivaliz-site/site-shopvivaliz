#!/usr/bin/env bash
set -Eeuo pipefail

ACTION="${1:-status}"
SERVER_SOURCE="${2:-}"
UNIT_SOURCE="${3:-}"
AGENT_TASK_STATE_SOURCE="${4:-}"
CONTINUITY_SOURCE_DIR="${5:-}"
# Runtime mutations must require leases/fencing unless an operator explicitly opts out.
SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=${SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF:-1}
HOST="$(hostname)"
BACKEND_HOST="always-free-arm-1787907847-26"
RETIRED_SITE_HOST="shopvivaliz-free-a1"
STATE_DIR="/var/lib/shopvivaliz-remote-control"
INSTALL_DIR="/opt/shopvivaliz-remote-control"
SERVICE="shopvivaliz-remote-control-mcp.service"
BROWSER_SERVICE="shopvivaliz-remote-control-browser-mcp.service"
REMOTE_USER="shopvivaliz-remote"
PUBKEY="${SHOPVIVALIZ_REMOTE_CONTROL_PUBKEY:-}"

die() { echo "REMOTE_CONTROL_SETUP_ERROR=$1" >&2; exit "${2:-1}"; }
require_root() { [ "$(id -u)" -eq 0 ] || die root_required 20; }

install_controller() {
  require_root
  [ "$HOST" = "$BACKEND_HOST" ] || die controller_host_mismatch 21
  [ -n "$SERVER_SOURCE" ] && [ -f "$SERVER_SOURCE" ] || die server_source_required 22
  [ -n "$UNIT_SOURCE" ] && [ -f "$UNIT_SOURCE" ] || die unit_source_required 23
  [ -n "$AGENT_TASK_STATE_SOURCE" ] && [ -f "$AGENT_TASK_STATE_SOURCE" ] || die agent_task_state_source_required 24
  [ -n "$CONTINUITY_SOURCE_DIR" ] && [ -d "$CONTINUITY_SOURCE_DIR" ] || die continuity_source_dir_required 25
  for required in conversation_lease.py runtime_lock.py mutation_gate.py foreground_handoff.py; do
    [ -f "$CONTINUITY_SOURCE_DIR/$required" ] || die "continuity_dependency_missing:$required" 26
  done

  install -d -m 700 -o root -g root "$STATE_DIR"
  install -d -m 755 -o root -g root "$INSTALL_DIR"
  install -d -m 755 -o root -g root "$INSTALL_DIR/scripts/continuity"
  install -m 0755 -o root -g root "$SERVER_SOURCE" "$INSTALL_DIR/server.py"
  # Verifier code only: approval signing keys and the source-side root helper
  # MUST NOT be installed or enabled by generic MCP bootstrap.
  cart_gate_source="$(dirname "$SERVER_SOURCE")/maintenance_cart.py"
  if [ -f "$cart_gate_source" ]; then
    install -m 0644 -o root -g root "$cart_gate_source" "$INSTALL_DIR/maintenance_cart.py"
    echo "MARINA_CART_GATE_CODE_STAGED=YES_NOT_ENABLED"
  fi
  install -m 0755 -o root -g root "$AGENT_TASK_STATE_SOURCE" "$INSTALL_DIR/scripts/agent_task_state.py"
  install -m 0644 -o root -g root "$CONTINUITY_SOURCE_DIR"/*.py "$INSTALL_DIR/scripts/continuity/"

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
    printf 'SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=%s\n' "$SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF"
    printf 'SHOPVIVALIZ_REMOTE_MCP_MAX_DURABLE_TIMEOUT=%s\n' "${SHOPVIVALIZ_REMOTE_MCP_MAX_DURABLE_TIMEOUT:-7200}"
    printf 'SHOPVIVALIZ_CONTINUITY_LIB_DIR=%s\n' "$INSTALL_DIR/scripts/continuity"
    printf 'SHOPVIVALIZ_AGENT_TASK_STATE_DIR=/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state\n'
  } > "$env_file"
  chmod 600 "$env_file"

  install -m 0644 -o root -g root "$UNIT_SOURCE" "/etc/systemd/system/$SERVICE"

  systemctl daemon-reload
  systemctl enable "$SERVICE"
  systemctl restart "$SERVICE"
  sleep 2
  systemctl is-active --quiet "$SERVICE"
  curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:5580/health >/dev/null

  # The browser MCP imports the base controller module at process start.
  # Refresh an already-active browser MCP whenever the base tool catalog changes.
  if systemctl is-active --quiet "$BROWSER_SERVICE"; then
    systemctl try-restart "$BROWSER_SERVICE"
    browser_ready=0
    for _ in $(seq 1 20); do
      if curl -fsS --connect-timeout 3 --max-time 8 http://127.0.0.1:5581/health >/dev/null; then
        browser_ready=1
        break
      fi
      sleep 1
    done
    [ "$browser_ready" = "1" ] || die browser_dependent_refresh_failed 24
    echo "REMOTE_CONTROL_BROWSER_DEPENDENT_REFRESH=PASS"
  fi

  echo "REMOTE_CONTROL_CONTROLLER_INSTALL=PASS"
  echo "REMOTE_CONTROL_PUBLIC_KEY_FILE=$STATE_DIR/id_ed25519.pub"
}


# Narrow maintenance: only the two installed MCP Python files, no service env,
# token/key generation, SSH setup, unit rewrite or unrelated host changes.
install_controller_code_only() {
  require_root
  [ "$HOST" = "$BACKEND_HOST" ] || die controller_host_mismatch 21
  [ -f "$SERVER_SOURCE" ] || die server_source_required 22
  [ -f "$UNIT_SOURCE" ] || die checkpoint_writer_source_required 23
  [ -f "$INSTALL_DIR/server.py" ] || die existing_controller_required 24
  [ -d "$INSTALL_DIR/scripts/continuity" ] || die existing_continuity_runtime_required 25
  systemctl is-active --quiet "$SERVICE" || die controller_not_active 26
  systemctl is-active --quiet "$BROWSER_SERVICE" || die browser_mcp_not_active 27
  local cart_gate_source="$(dirname "$SERVER_SOURCE")/maintenance_cart.py"
  if [ -e "$cart_gate_source" ]; then
    # Refuse broken verifier dependencies before touching a running MCP.
    python3 -m py_compile "$SERVER_SOURCE" "$UNIT_SOURCE" "$cart_gate_source"
    python3 -c 'from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey'
  else
    # This narrow installer may still serve legacy code without the new tool.
    if grep -q 'marina_cart_retire_source' "$SERVER_SOURCE"; then
      die cart_verifier_source_missing 31
    fi
    python3 -m py_compile "$SERVER_SOURCE" "$UNIT_SOURCE"
  fi

  local lockfile="$STATE_DIR/.checkpoint-mcp-install.lock"
  exec 9>"$lockfile"
  chmod 0600 "$lockfile"
  flock -n 9 || die concurrent_mcp_installer 28

  local writer="$INSTALL_DIR/scripts/continuity/checkpoint_update_atomic.py"
  local backupdir writer_preexisting=0 cart_preexisting=0 success=0
  local cart_gate="$INSTALL_DIR/maintenance_cart.py"
  backupdir="$(mktemp -d "$STATE_DIR/.checkpoint-mcp-backup.XXXXXXXX")"
  chmod 0700 "$backupdir"
  cp -p "$INSTALL_DIR/server.py" "$backupdir/server.py"
  if [ -e "$cart_gate" ]; then
    cart_preexisting=1
    cp -p "$cart_gate" "$backupdir/maintenance_cart.py"
  fi
  if [ -e "$writer" ]; then
    writer_preexisting=1
    cp -p "$writer" "$backupdir/checkpoint_update_atomic.py"
  fi
  cleanup_and_rollback() {
    if [ "$success" -ne 1 ]; then
      echo "BACKEND_MCP_ONLY_INSTALL=ROLLBACK" >&2
      cp -p "$backupdir/server.py" "$INSTALL_DIR/server.py"
      if [ "$cart_preexisting" -eq 1 ]; then
        cp -p "$backupdir/maintenance_cart.py" "$cart_gate"
      else
        rm -f "$cart_gate"
      fi
      if [ "$writer_preexisting" -eq 1 ]; then
        cp -p "$backupdir/checkpoint_update_atomic.py" "$writer"
      else
        rm -f "$writer"
      fi
      if ! systemctl restart "$SERVICE"; then
        echo "BACKEND_MCP_ROLLBACK_BASE_RESTART_FAILED" >&2
      fi
      if ! systemctl restart "$BROWSER_SERVICE"; then
        echo "BACKEND_MCP_ROLLBACK_BROWSER_RESTART_FAILED" >&2
      fi
    fi
    rm -f "$backupdir/server.py" "$backupdir/checkpoint_update_atomic.py" "$backupdir/maintenance_cart.py"
    rmdir "$backupdir"
  }
  trap cleanup_and_rollback EXIT

  install -m 0644 -o root -g root "$UNIT_SOURCE" "$writer"
  install -m 0755 -o root -g root "$SERVER_SOURCE" "$INSTALL_DIR/server.py"
  if [ -f "$cart_gate_source" ]; then
    # Code-only installation: no operator keys, source helper, or feature flag.
    install -m 0644 -o root -g root "$cart_gate_source" "$cart_gate"
    cmp "$cart_gate_source" "$cart_gate"
  fi
  cmp "$SERVER_SOURCE" "$INSTALL_DIR/server.py"
  cmp "$UNIT_SOURCE" "$writer"
  systemctl restart "$SERVICE"
  local ready=0
  for _ in $(seq 1 20); do
    if systemctl is-active --quiet "$SERVICE" && \
       curl -fsS --connect-timeout 2 --max-time 3 http://127.0.0.1:5580/health >/dev/null; then
      ready=1
      break
    fi
    sleep 1
  done
  [ "$ready" -eq 1 ] || die controller_code_reload_failed 29
  systemctl restart "$BROWSER_SERVICE"
  ready=0
  for _ in $(seq 1 20); do
    if systemctl is-active --quiet "$BROWSER_SERVICE" && \
       curl -fsS --connect-timeout 2 --max-time 3 http://127.0.0.1:5581/health >/dev/null; then
      ready=1
      break
    fi
    sleep 1
  done
  [ "$ready" -eq 1 ] || die browser_code_reload_failed 30
  success=1
  trap - EXIT
  cleanup_and_rollback
  echo "BACKEND_MCP_ONLY_RESTRICTED_INSTALL=PASS"
}


install_target() {
  # Source VM has been permanently removed. No account/ssh/sudo provisioning.
  die retired_source_install_forbidden 41
  require_root
  [ "$HOST" = "$RETIRED_SITE_HOST" ] || die target_host_mismatch 31
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
  elif [ "$HOST" = "$RETIRED_SITE_HOST" ]; then
    die retired_source_status_forbidden 42
    id "$REMOTE_USER" >/dev/null 2>&1 && echo "REMOTE_CONTROL_USER_PRESENT=true" || echo "REMOTE_CONTROL_USER_PRESENT=false"
    [ -s "/home/$REMOTE_USER/.ssh/authorized_keys" ] && echo "REMOTE_CONTROL_KEY_PRESENT=true" || echo "REMOTE_CONTROL_KEY_PRESENT=false"
    visudo -cf /etc/sudoers.d/shopvivaliz-remote-control >/dev/null 2>&1 && echo "REMOTE_CONTROL_SUDO_VALID=true" || echo "REMOTE_CONTROL_SUDO_VALID=false"
  else
    die unsupported_host 40
  fi
}

case "$ACTION" in
  install-controller) install_controller ;;
  install-controller-code-only) install_controller_code_only ;;
  install-target) install_target ;;
  status) status ;;
  *) die unsupported_action 64 ;;
esac
