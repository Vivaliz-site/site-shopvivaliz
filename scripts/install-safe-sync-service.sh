#!/usr/bin/env bash
set -euo pipefail

SOURCE_ROOT="${SOURCE_ROOT:-${ROOT:-/home/ubuntu/shopvivaliz-deploy/repo}}"
SEED_REPO="${SEED_REPO:-/home/ubuntu/shopvivaliz-deploy/repo}"
SYNC_ROOT="${SYNC_ROOT:-/home/ubuntu/shopvivaliz-deploy/sync-repo}"
SYNC_BRANCH="${SYNC_BRANCH:-main}"
SAFE_SYNC_RUN_ON_INSTALL="${SAFE_SYNC_RUN_ON_INSTALL:-true}"
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

case "$SAFE_SYNC_RUN_ON_INSTALL" in
  true|false) ;;
  *)
    echo "SAFE_SYNC_RUN_ON_INSTALL invalido: $SAFE_SYNC_RUN_ON_INSTALL (esperado true ou false)" >&2
    exit 11
    ;;
esac

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

# This checkout is an operational clone, not a developer worktree. Keep its
# line-ending policy explicit so a host-level core.autocrlf setting cannot turn
# an otherwise identical tracked file into a false dirty checkout.
configure_sync_checkout() {
  runuser -u ubuntu -- git -C "$SYNC_ROOT" config core.autocrlf false
  runuser -u ubuntu -- git -C "$SYNC_ROOT" config core.eol lf
}

normalize_line_ending_only_drift() {
  local dirty staged untracked
  dirty="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" status --porcelain=v1 --untracked-files=all)"
  [[ -n "$dirty" ]] || return 0
  if runuser -u ubuntu -- git -C "$SYNC_ROOT" diff --cached --quiet -- .; then
    staged=0
  else
    staged=1
  fi
  untracked="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" ls-files --others --exclude-standard)"
  if [[ "$staged" != 0 ]] || [[ -n "$untracked" ]] \
    || ! runuser -u ubuntu -- git -C "$SYNC_ROOT" diff --quiet --ignore-space-at-eol -- .; then
    return 1
  fi
  echo 'SAFE_SYNC_REPAIR=NORMALIZED_LINE_ENDING_ONLY_DRIFT'
  runuser -u ubuntu -- git -C "$SYNC_ROOT" restore --source=HEAD --worktree -- .
}

if [[ ! -d "$SYNC_ROOT/.git" ]]; then
  if [[ -e "$SYNC_ROOT" ]]; then
    # A previous bootstrap may have left an empty placeholder directory. It is
    # safe to remove only that exact empty directory; never recurse or delete
    # an unknown path because it may contain operational data.
    if [[ -d "$SYNC_ROOT" && ! -L "$SYNC_ROOT" ]] \
      && [[ -z "$(find "$SYNC_ROOT" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
      rmdir "$SYNC_ROOT"
      echo "SAFE_SYNC_REPAIR=REMOVED_EMPTY_PLACEHOLDER"
    elif [[ -d "$SYNC_ROOT" && ! -L "$SYNC_ROOT" ]] \
      && [[ -f "$SYNC_ROOT/git-auto-sync.py" ]] \
      && [[ -f "$SYNC_ROOT/scripts/safe-repo-sync.sh" ]] \
      && [[ -f "$SYNC_ROOT/scripts/deploy-production.sh" ]] \
      && [[ -f "$SYNC_ROOT/scripts/should-deploy-production.sh" ]]; then
      # A legacy bootstrap can leave a complete working tree without its .git
      # metadata. Preserve it for recovery, then create the canonical clone;
      # never recursively delete or overwrite this operational data.
      quarantine_root="$(dirname "$SYNC_ROOT")/quarantine"
      quarantine_target="$quarantine_root/sync-repo-non-git-$(date -u +%Y%m%dT%H%M%SZ)-$$"
      install -d -o root -g root -m 0750 "$quarantine_root"
      if [[ -e "$quarantine_target" || -L "$quarantine_target" ]]; then
        echo "Destino de quarentena ja existe: $quarantine_target" >&2
        exit 5
      fi
      mv -T -- "$SYNC_ROOT" "$quarantine_target"
      echo "SAFE_SYNC_REPAIR=QUARANTINED_NON_GIT_CHECKOUT"
      echo "SAFE_SYNC_QUARANTINE=$quarantine_target"
    else
      echo "Caminho de sync existe mas nao e clone Git e nao e um placeholder vazio: $SYNC_ROOT" >&2
      exit 5
    fi
  fi
  runuser -u ubuntu -- git clone --quiet --no-tags --single-branch --branch "$SYNC_BRANCH" "$remote_url" "$SYNC_ROOT"
  configure_sync_checkout
  runuser -u ubuntu -- git -C "$SYNC_ROOT" restore --source=HEAD --worktree -- .
else
  configure_sync_checkout
  normalize_line_ending_only_drift || {
    echo "Checkout dedicado de sync esta sujo: $SYNC_ROOT" >&2
    exit 6
  }
  current_branch="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" branch --show-current)"
  if [[ "$current_branch" != "$SYNC_BRANCH" ]]; then
    echo "Checkout dedicado de sync esta em $current_branch, esperado $SYNC_BRANCH" >&2
    exit 7
  fi
  runuser -u ubuntu -- git -C "$SYNC_ROOT" fetch --quiet --prune --no-tags origin "$SYNC_BRANCH"
  local_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse HEAD)"
  remote_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse "origin/$SYNC_BRANCH")"

  # A legacy HEAD can itself contain mixed line endings. In that state, an
  # attribute-aware restore of HEAD may remain reported as dirty even though
  # the only difference is line endings. Re-prove that condition after fetch,
  # then reconcile directly to the fetched source of truth before merging.
  post_normalize_dirty="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" status --porcelain=v1 --untracked-files=all)"
  if [[ -n "$post_normalize_dirty" ]]; then
    post_normalize_untracked="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" ls-files --others --exclude-standard)"
    if ! runuser -u ubuntu -- git -C "$SYNC_ROOT" diff --cached --quiet -- . \
      || [[ -n "$post_normalize_untracked" ]] \
      || ! runuser -u ubuntu -- git -C "$SYNC_ROOT" diff --quiet --ignore-space-at-eol -- .; then
      echo "Checkout dedicado de sync possui alteracoes semanticas apos normalizacao" >&2
      exit 6
    fi
    echo 'SAFE_SYNC_REPAIR=RECONCILED_LINE_ENDING_ONLY_DRIFT'
    runuser -u ubuntu -- git -C "$SYNC_ROOT" restore --source="origin/$SYNC_BRANCH" --staged --worktree -- .
    runuser -u ubuntu -- git -C "$SYNC_ROOT" reset --hard "origin/$SYNC_BRANCH"
    if [[ -n "$(runuser -u ubuntu -- git -C "$SYNC_ROOT" status --porcelain=v1 --untracked-files=all)" ]]; then
      echo "Checkout dedicado de sync permaneceu sujo apos reconciliacao" >&2
      exit 6
    fi
    local_sha="$remote_sha"
  fi
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

if [[ "$SAFE_SYNC_RUN_ON_INSTALL" == 'true' ]]; then
  systemctl start "$SERVICE_NAME"

  # Type=oneshot termina em inactive (dead) apos sucesso. systemctl status
  # devolve rc=3 nesse estado e nao pode ser usado como gate sob set -e.
  service_result="$(systemctl show --property=Result --value "$SERVICE_NAME")"
  if [[ "$service_result" != 'success' ]]; then
    echo "Safe sync oneshot terminou com Result=$service_result" >&2
    systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
    exit 10
  fi
else
  # Canonical activation holds repo-sync.lock, so the one-shot must run only
  # after that lock is released. Clear any stale failed state now; monitor
  # performs the post-activation run and proves Result=success.
  systemctl reset-failed "$SERVICE_NAME" >/dev/null 2>&1
  echo 'SAFE_SYNC_INITIAL_RUN=DEFERRED'
fi
systemctl is-active --quiet "$TIMER_NAME"
systemctl is-enabled --quiet "$TIMER_NAME"

sync_sha="$(runuser -u ubuntu -- git -C "$SYNC_ROOT" rev-parse HEAD)"
echo "SAFE_SYNC_ROOT=$SYNC_ROOT"
echo "SAFE_SYNC_SHA=$sync_sha"
systemctl show "$SERVICE_NAME" --property=ActiveState,SubState,Result --no-pager
systemctl show "$TIMER_NAME" --property=ActiveState,SubState,Result --no-pager
echo 'SAFE_SYNC_INSTALL=PASS'
