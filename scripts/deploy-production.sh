#!/usr/bin/env bash
# Deploy de producao por releases imutaveis.
# O cron externo usa flock em /var/lock/shopvivaliz-deploy.lock.

set -Eeuo pipefail

readonly REPO_DIR="/home/ubuntu/shopvivaliz-deploy/repo"
readonly RELEASES_DIR="/home/ubuntu/shopvivaliz-deploy/releases"
readonly SHARED_DIR="/home/ubuntu/shopvivaliz-deploy/shared"
readonly CURRENT_LINK="/home/ubuntu/shopvivaliz-deploy/current"
readonly TARGET_FILE="$SHARED_DIR/deploy-target-ref"
readonly LOG_DIR="/home/ubuntu/shopvivaliz-deploy/logs"
readonly LOG_FILE="$LOG_DIR/deploy.log"
readonly STATUS_FILE="$SHARED_DIR/logs/deploy-status.json"
readonly RUNNER_PATH="$REPO_DIR/scripts/deploy-production.sh"
readonly RETENTION_COUNT=5

mkdir -p "$RELEASES_DIR" "$SHARED_DIR" "$LOG_DIR" "$SHARED_DIR/logs"

log() {
  local level="$1"
  shift
  printf '[%s] [%s] %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$level" "$*" | tee -a "$LOG_FILE"
}

write_status() {
  local status="$1"
  local sha="$2"
  local release="$3"
  local message="$4"
  python3 - "$STATUS_FILE" "$status" "$sha" "$release" "$message" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

path = Path(sys.argv[1])
payload = {
    "status": sys.argv[2],
    "release_sha": sys.argv[3],
    "release": sys.argv[4],
    "message": sys.argv[5],
    "checked_at": datetime.now(timezone.utc).isoformat(),
}
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
PY
}

rollback_to() {
  local previous_release="$1"
  if [ -z "$previous_release" ] || [ ! -d "$RELEASES_DIR/$previous_release" ]; then
    log ERROR "Rollback indisponivel: release anterior ausente"
    return 1
  fi
  ln -sfn "releases/$previous_release" "$CURRENT_LINK.tmp"
  mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
  sudo systemctl reload apache2
  log WARN "Rollback aplicado para $previous_release"
}

restore_runner_bootstrap_if_needed() {
  local dirty
  dirty="$(git -C "$REPO_DIR" status --porcelain)"
  if [ -z "$dirty" ]; then
    return 0
  fi

  local count path remote_blob work_blob restore_tmp
  count="$(printf '%s\n' "$dirty" | sed '/^$/d' | wc -l | tr -d ' ')"
  path="$(printf '%s\n' "$dirty" | sed -n '1s/^...//p')"
  if [ "$count" != "1" ] || [ "$path" != "scripts/deploy-production.sh" ]; then
    log ERROR "Checkout de deploy contem alteracoes nao autorizadas: $dirty"
    return 1
  fi

  remote_blob="$(git -C "$REPO_DIR" rev-parse 'FETCH_HEAD:scripts/deploy-production.sh')"
  work_blob="$(git -C "$REPO_DIR" hash-object -- "$RUNNER_PATH")"
  if [ "$remote_blob" != "$work_blob" ]; then
    log ERROR "Runner modificado nao corresponde ao blob remoto"
    return 1
  fi

  restore_tmp="$(mktemp "$REPO_DIR/scripts/.deploy-restore.XXXXXX")"
  git -C "$REPO_DIR" show 'HEAD:scripts/deploy-production.sh' > "$restore_tmp"
  chmod 0755 "$restore_tmp"
  mv -f -- "$restore_tmp" "$RUNNER_PATH"

  if [ -n "$(git -C "$REPO_DIR" status --porcelain)" ]; then
    log ERROR "Nao foi possivel restaurar o checkout antes do sync"
    return 1
  fi
  log INFO "Bootstrap do runner restaurado; checkout voltou ao estado limpo"
}

sync_deploy_clone() {
  local target_ref="$1"
  if [ "$target_ref" != "main" ] && [ "$target_ref" != "origin/main" ]; then
    log INFO "Sync do clone ignorado para target nao canonico: $target_ref"
    return 0
  fi

  local sync_tmp
  sync_tmp="$(mktemp /tmp/shopvivaliz-git-sync.XXXXXX.py)"
  git -C "$REPO_DIR" show 'FETCH_HEAD:git-auto-sync.py' > "$sync_tmp"
  chmod 0755 "$sync_tmp"

  log INFO "Sincronizando clone de deploy com a main sanitizada"
  if ! SHOPVIVALIZ_REPO_DIR="$REPO_DIR" SHOPVIVALIZ_SYNC_BRANCH=main \
      /usr/bin/python3 "$sync_tmp" >> "$LOG_FILE" 2>&1; then
    rm -f -- "$sync_tmp"
    log ERROR "Sincronizacao do clone de deploy falhou"
    return 1
  fi
  rm -f -- "$sync_tmp"

  local local_sha remote_sha
  local_sha="$(git -C "$REPO_DIR" rev-parse HEAD)"
  remote_sha="$(git -C "$REPO_DIR" rev-parse origin/main)"
  if [ "$local_sha" != "$remote_sha" ]; then
    log ERROR "Clone nao ficou alinhado: local=$local_sha remoto=$remote_sha"
    return 1
  fi

  ROOT="$REPO_DIR" SHARED_ROOT="$SHARED_DIR" CURRENT_ROOT="$CURRENT_LINK" \
    bash "$REPO_DIR/scripts/install-auto-sync-oracle.sh" >> "$LOG_FILE" 2>&1
  log INFO "Clone e cron de sync alinhados em $local_sha"
}

verify_local_release() {
  local expected_sha="$1"
  local body
  body="$(mktemp)"
  for attempt in 1 2 3 4 5; do
    if curl --silent --show-error --fail \
      --connect-timeout 5 --max-time 15 \
      -H 'Host: shopvivaliz.com.br' \
      -o "$body" \
      'http://127.0.0.1/api/health/version.php'; then
      if python3 - "$body" "$expected_sha" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
expected = sys.argv[2].lower()
actual = str(payload.get("release_sha") or "").lower()
if not payload.get("ok") or actual != expected:
    raise SystemExit(1)
PY
      then
        rm -f -- "$body"
        return 0
      fi
    fi
    sleep $((attempt * 2))
  done
  cat "$body" >> "$LOG_FILE" 2>/dev/null || :
  rm -f -- "$body"
  return 1
}

if [ ! -d "$REPO_DIR/.git" ]; then
  log FATAL "Clone Git nao existe: $REPO_DIR"
  exit 1
fi

TARGET_REF="${1:-}"
if [ -z "$TARGET_REF" ] && [ -f "$TARGET_FILE" ]; then
  TARGET_REF="$(head -n 1 "$TARGET_FILE" | tr -d '\r' | xargs)"
fi
if [ -z "$TARGET_REF" ]; then
  TARGET_REF="origin/main"
fi

FETCH_SOURCE="$TARGET_REF"
if [ "$TARGET_REF" = "origin/main" ] || [ "$TARGET_REF" = "main" ]; then
  FETCH_SOURCE="main"
fi

log INFO "=== Deploy iniciado para $TARGET_REF ==="
if ! git -C "$REPO_DIR" fetch --prune --no-tags origin "$FETCH_SOURCE"; then
  log ERROR "git fetch falhou para $FETCH_SOURCE"
  exit 1
fi

# O runner antigo pode substituir somente este arquivo e reiniciar o processo.
# A nova versao restaura o blob do HEAD antes de executar o sync verificado.
restore_runner_bootstrap_if_needed
sync_deploy_clone "$TARGET_REF"

REMOTE_SHA="$(git -C "$REPO_DIR" rev-parse FETCH_HEAD)"
ACTIVE_RELEASE=""
ACTIVE_SHA=""
if [ -L "$CURRENT_LINK" ] && [ -e "$CURRENT_LINK" ]; then
  ACTIVE_RELEASE="$(basename "$(readlink -f "$CURRENT_LINK")")"
  ACTIVE_SHA="${ACTIVE_RELEASE##*-}"
fi

if [ "${REMOTE_SHA:0:8}" = "$ACTIVE_SHA" ]; then
  log INFO "Producao ja alinhada em $REMOTE_SHA"
  write_status success "$REMOTE_SHA" "$ACTIVE_RELEASE" "release ja estava alinhada"
  exit 0
fi

RELEASE_TIME="$(date +%Y%m%d-%H%M%S)"
NEW_RELEASE="$RELEASE_TIME-${REMOTE_SHA:0:8}"
NEW_RELEASE_PATH="$RELEASES_DIR/$NEW_RELEASE"
log INFO "Criando release $NEW_RELEASE"
mkdir -p "$NEW_RELEASE_PATH"
if ! (cd "$REPO_DIR" && git archive "$REMOTE_SHA") | tar -xf - -C "$NEW_RELEASE_PATH"; then
  rm -rf -- "$NEW_RELEASE_PATH"
  log ERROR "Extracao da release falhou"
  exit 1
fi
printf '%s\n' "$REMOTE_SHA" > "$NEW_RELEASE_PATH/.release-sha"

declare -a SYMLINKS=(
  ".env"
  "logs"
  "cache"
  "sessions"
  "storage"
  "tasks-queue.json"
)

for name in "${SYMLINKS[@]}"; do
  shared_path="$SHARED_DIR/$name"
  release_path="$NEW_RELEASE_PATH/$name"
  if [ "$name" = ".env" ]; then
    if [ ! -f "$shared_path" ]; then
      rm -rf -- "$NEW_RELEASE_PATH"
      log ERROR "Configuracao compartilhada ausente: $shared_path"
      exit 1
    fi
  elif [ "$name" = "tasks-queue.json" ]; then
    if [ ! -f "$shared_path" ]; then
      printf '{\n  "version": "2",\n  "tasks": []\n}\n' > "$shared_path"
    fi
  else
    mkdir -p "$shared_path"
  fi
  rm -rf -- "$release_path"
  ln -s "../../shared/$name" "$release_path"
  if [ "$(readlink -f "$release_path")" != "$shared_path" ]; then
    rm -rf -- "$NEW_RELEASE_PATH"
    log ERROR "Symlink compartilhado invalido: $name"
    exit 1
  fi
done

if [ ! -r "$NEW_RELEASE_PATH/scripts/materialize-runtime-secrets.php" ]; then
  rm -rf -- "$NEW_RELEASE_PATH"
  log ERROR "Materializador de runtime secrets ausente"
  exit 1
fi
php "$NEW_RELEASE_PATH/scripts/materialize-runtime-secrets.php" >> "$LOG_FILE" 2>&1
sudo chown ubuntu:www-data "$SHARED_DIR/runtime-secrets.php"
chmod 0640 "$SHARED_DIR/runtime-secrets.php"
runtime_link="$NEW_RELEASE_PATH/config/runtime-secrets.php"
rm -f -- "$runtime_link"
ln -s "../../../shared/runtime-secrets.php" "$runtime_link"
if [ "$(readlink -f "$runtime_link")" != "$SHARED_DIR/runtime-secrets.php" ]; then
  rm -rf -- "$NEW_RELEASE_PATH"
  log ERROR "Symlink de runtime secrets invalido"
  exit 1
fi
if ! sudo -u www-data php -r '$values = require $argv[1]; exit(is_array($values) && count($values) >= 4 ? 0 : 1);' "$runtime_link"; then
  rm -rf -- "$NEW_RELEASE_PATH"
  log ERROR "Runtime secrets nao sao legiveis pelo SAPI web"
  exit 1
fi

if [ -f "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" ]; then
  php "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" >> "$LOG_FILE" 2>&1
fi

php -l "$NEW_RELEASE_PATH/index.php" > /dev/null
php -l "$NEW_RELEASE_PATH/api/health/version.php" > /dev/null

ln -sfn "releases/$NEW_RELEASE" "$CURRENT_LINK.tmp"
mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"

if ! sudo systemctl reload apache2; then
  rollback_to "$ACTIVE_RELEASE"
  write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "reload do Apache falhou"
  exit 1
fi

if ! verify_local_release "$REMOTE_SHA"; then
  rollback_to "$ACTIVE_RELEASE"
  write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "endpoint de versao nao confirmou o SHA"
  exit 1
fi

mapfile -t OLD_RELEASES < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +$((RETENTION_COUNT + 1)))
for old_release in "${OLD_RELEASES[@]}"; do
  if [ "$old_release" != "$NEW_RELEASE" ] && [ "$old_release" != "$ACTIVE_RELEASE" ]; then
    rm -rf -- "$RELEASES_DIR/$old_release"
  fi
done

write_status success "$REMOTE_SHA" "$NEW_RELEASE" "deploy, sync e health confirmados"
log INFO "=== Deploy concluido em $REMOTE_SHA ==="
