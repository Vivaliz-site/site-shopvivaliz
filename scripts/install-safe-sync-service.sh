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

systemctl daemon-reload
systemctl disable --now shopvivaliz-sync.service 2>/dev/null || true
systemctl enable --now "$TIMER_NAME"
systemctl start "$SERVICE_NAME"

systemctl status "$SERVICE_NAME" --no-pager
systemctl status "$TIMER_NAME" --no-pager
