#!/usr/bin/env bash
set -euo pipefail

ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}"
SERVICE_NAME="shopvivaliz-sync-safe.service"
TIMER_NAME="shopvivaliz-sync-safe.timer"
SERVICE_SOURCE="$ROOT/deploy/systemd/$SERVICE_NAME"
TIMER_SOURCE="$ROOT/deploy/systemd/$TIMER_NAME"
SERVICE_TARGET="/etc/systemd/system/$SERVICE_NAME"
TIMER_TARGET="/etc/systemd/system/$TIMER_NAME"
RUNNER_PATH="$ROOT/scripts/safe-repo-sync.sh"
RUNNER_TARGET="/usr/local/lib/shopvivaliz/safe-repo-sync.sh"
DEPLOY_LOG_DIR="/home/ubuntu/shopvivaliz-deploy/logs"
DEPLOY_LOG_FILE="$DEPLOY_LOG_DIR/deploy.log"

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash $ROOT/scripts/install-safe-sync-service.sh" >&2
  exit 1
fi

if [[ ! -d "$ROOT/.git" ]]; then
  echo "Repositorio Git nao encontrado em $ROOT" >&2
  exit 2
fi

for path in "$SERVICE_SOURCE" "$TIMER_SOURCE" "$RUNNER_PATH"; do
  if [[ ! -f "$path" ]]; then
    echo "Arquivo obrigatorio ausente: $path" >&2
    exit 3
  fi
done

install -d -o root -g root -m 0755 /usr/local/lib/shopvivaliz
install -o root -g root -m 0755 "$RUNNER_PATH" "$RUNNER_TARGET"
install -o root -g root -m 0644 "$SERVICE_SOURCE" "$SERVICE_TARGET"
install -o root -g root -m 0644 "$TIMER_SOURCE" "$TIMER_TARGET"

# safe-repo-sync runs as ubuntu and may hand off to deploy-production.sh.
# Keep deploy evidence appendable by that operational user without truncation.
install -d -o ubuntu -g ubuntu -m 0755 "$DEPLOY_LOG_DIR"
touch "$DEPLOY_LOG_FILE"
chown ubuntu:ubuntu "$DEPLOY_LOG_FILE"
chmod 0644 "$DEPLOY_LOG_FILE"

systemctl daemon-reload
if systemctl cat shopvivaliz-sync.service >/dev/null 2>&1; then
  systemctl disable --now shopvivaliz-sync.service
fi
systemctl enable --now "$TIMER_NAME"
systemctl start "$SERVICE_NAME"

# Type=oneshot termina em inactive (dead) apos sucesso. `systemctl status`
# devolve rc=3 nesse estado e nao pode ser usado como gate sob `set -e`.
service_result="$(systemctl show --property=Result --value "$SERVICE_NAME")"
if [[ "$service_result" != 'success' ]]; then
  echo "Safe sync oneshot terminou com Result=$service_result" >&2
  systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
  exit 4
fi
systemctl is-active --quiet "$TIMER_NAME"
systemctl is-enabled --quiet "$TIMER_NAME"

# Diagnostico somente: o oneshot pode aparecer inactive mesmo apos sucesso.
systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
systemctl show "$TIMER_NAME" --property=ActiveState,SubState,Result --no-pager
echo 'SAFE_SYNC_INSTALL=PASS'
