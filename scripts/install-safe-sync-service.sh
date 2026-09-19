#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="${SOURCE_ROOT:-${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}}"
SEED_REPO="${SEED_REPO:-/home/ubuntu/shopvivaliz-deploy/repo}"
SYNC_ROOT="${SYNC_ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"
SYNC_BRANCH="${SYNC_BRANCH:-main}"
SERVICE_NAME="shopvivaliz-sync-safe.service"
TIMER_NAME="shopvivaliz-sync-safe.timer"
SERVICE_SOURCE="$SOURCE_ROOT/deploy/systemd/$SERVICE_NAME"
TIMER_SOURCE="$SOURCE_ROOT/deploy/systemd/$TIMER_NAME"
SERVICE_TARGET="/etc/systemd/system/$SERVICE_NAME"
TIMER_TARGET="/etc/systemd/system/$TIMER_NAME"
RUNNER_PATH="$SOURCE_ROOT/scripts/safe-repo-sync.sh"
RUNNER_TARGET="/usr/local/lib/shopvivaliz/safe-repo-sync.sh"
DEPLOY_LOG_DIR="/home/ubuntu/shopvivaliz-deploy/logs"
DEPLOY_LOG_FILE="$DEPLOY_LOG_DIR/deploy.log"

if [[ ${EUID} -ne 0 ]]; then
  echo "Execute com sudo: sudo bash $SOURCE_ROOT/scripts/install-safe-sync-service.sh" >&2
  exit 1
fi

if [[ ! -d "$SEED_REPO/.git" ]]; then
  echo "Repositorio seed Git nao encontrado em $SEED_REPO" >&2
  exit 2
fi

for path in "$SERVICE_SOURCE" "$TIMER_SOURCE" "$RUNNER_PATH"; do
  if [[ ! -f "$path" ]]; then
    echo "Arquivo obrigatorio ausente: $path" >&2
    exit 3
  fi
done

remote_url="${SYNC_REMOTE_URL:-}"
if [[ -z "$remote_url" ]]; then
  remote_url="$(runuser -u ubuntu -- git -C "$SEED_REPO" remote get-url origin)"
fi
if [[ -z "$remote_url" ]]; then
  echo "Remote canonico ausente no repositorio seed" >&2
  exit 4
fi

sync_parent="$(dirname "$SYNC_ROOT")"
if [[ ! -d "$sync_parent" ]]; then
  install -d -o ubuntu -g ubuntu -m 0755 "$sync_parent"
fi

if [[ ! -d "$SYNC_ROOT/.git" ]]; then
  if [[ -e "$SYNC_ROOT" ]]; then
    echo "Caminho de sync existe mas nao e clone Git: $SYNC_ROOT" >&2
    exit 5
  fi
  runuser -u ubuntu -- git clone --quiet --no-tags --single-branch --branch "$SYNC_BRANCH" "$remote_url" "$SYNC_ROOT"
else
  if [[ -n "$(runuser -u ubuntu -- git -C "$SYNC_ROOT" status --porcelain)" ]]; then
    echo "Checkout dedicado de sync esta sujo: $SYNC_ROOT" >&2
    exit 6
  fi
  current_branch="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" branch --show-current)"
  if [[ "$current_branch" != "$SYNC_BRANCH" ]]; then
    echo "Checkout dedicado de sync esta em $current_branch, esperado $SYNC_BRANCH" >&2
    exit 7
  fi
  runuser -u ubuntu -- git -C "$SYNC_ROOT" fetch --quiet --prune --no-tags origin "$SYNC_BRANCH"
  local_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse HEAD)"
  remote_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse "origin/$SYNC_BRANCH")"
  if [[ "$local_sha" != "$remote_sha" ]]; then
    if ! runuser -u ubuntu -- git -C "$SYNC_ROOT" merge-base --is-ancestor "$local_sha" "$remote_sha"; then
      echo "Checkout dedicado de sync divergiu de origin/$SYNC_BRANCH" >&2
      exit 8
    fi
    runuser -u ubuntu -- git -C "$SYNC_ROOT" merge --ff-only --quiet "origin/$SYNC_BRANCH"
  fi
fi

for path in   "$SYNC_ROOT/git-auto-sync.py"   "$SYNC_ROOT/scripts/safe-repo-sync.sh"   "$SYNC_ROOT/scripts/deploy-production.sh"   "$SYNC_ROOT/scripts/should-deploy-production.sh"; do
  if [[ ! -f "$path" ]]; then
    echo "Checkout dedicado incompleto: $path" >&2
    exit 9
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

# Type=oneshot termina em inactive (dead) apos sucesso. systemctl status
# devolve rc=3 nesse estado e nao pode ser usado como gate sob set -e.
service_result="$(systemctl show --property=Result --value "$SERVICE_NAME")"
if [[ "$service_result" != 'success' ]]; then
  echo "Safe sync oneshot terminou com Result=$service_result" >&2
  systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
  exit 10
fi
systemctl is-active --quiet "$TIMER_NAME"
systemctl is-enabled --quiet "$TIMER_NAME"

sync_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse HEAD)"
echo "SAFE_SYNC_ROOT=$SYNC_ROOT"
echo "SAFE_SYNC_SHA=$sync_sha"
systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
systemctl show "$TIMER_NAME" --property=ActiveState,SubState,Result --no-pager
echo 'SAFE_SYNC_INSTALL=PASS'
