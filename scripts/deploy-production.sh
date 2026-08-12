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
readonly -a RUNTIME_SERVICES=(
  "shopvivaliz-token-renewer.service"
  "shopvivaliz-shopee-token-renewer.service"
)

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

restart_runtime_services() {
  local service
  if ! sudo systemctl restart "${RUNTIME_SERVICES[@]}"; then
    log ERROR "Reinicio dos servicos de integracao falhou"
    return 1
  fi
  for service in "${RUNTIME_SERVICES[@]}"; do
    if ! sudo systemctl is-active --quiet "$service"; then
      log ERROR "Servico de integracao inativo apos reinicio: $service"
      return 1
    fi
  done
}

assert_managed_release_path() {
  local release_path="$1"
  local releases_root canonical_path current_target

  releases_root="$(readlink -f "$RELEASES_DIR")"
  canonical_path="$(readlink -f "$release_path" 2>/dev/null || true)"
  current_target="$(readlink -f "$CURRENT_LINK" 2>/dev/null || true)"

  if [ -z "$canonical_path" ] || [ ! -d "$release_path" ]; then
    log ERROR "Caminho de release invalido para cleanup: $release_path"
    return 1
  fi

  case "$canonical_path" in
    "$releases_root"/*) ;;
    *)
      log ERROR "Cleanup recusado fora da raiz de releases: $release_path"
      return 1
      ;;
  esac

  if [ "$canonical_path" = "$releases_root" ]; then
    log ERROR "Cleanup recusado para a raiz de releases"
    return 1
  fi

  if [ -n "$current_target" ] && [ "$canonical_path" = "$current_target" ]; then
    log ERROR "Cleanup recusado para a release ativa: $release_path"
    return 1
  fi
}

repair_release_tree_permissions() {
  local release_path="$1"

  if ! assert_managed_release_path "$release_path"; then
    return 1
  fi

  if ! sudo find -P "$release_path" \( -type d -o -type f \) -exec chown ubuntu:ubuntu {} +; then
    log ERROR "Nao foi possivel reconciliar ownership da release $(basename "$release_path")"
    return 1
  fi
  if ! sudo find -P "$release_path" -type d -exec chmod u+rwx {} +; then
    log ERROR "Nao foi possivel reconciliar permissoes de diretorios em $(basename "$release_path")"
    return 1
  fi
  if ! sudo find -P "$release_path" -type f -exec chmod u+rw {} +; then
    log ERROR "Nao foi possivel reconciliar permissoes de arquivos em $(basename "$release_path")"
    return 1
  fi
}

ensure_release_tree_cleanup_safe() {
  local release_path="$1"
  local owner_drift mode_drift

  if ! assert_managed_release_path "$release_path"; then
    return 1
  fi

  owner_drift="$(find -P "$release_path" \( -type d -o -type f \) ! -user ubuntu -print -quit 2>/dev/null || true)"
  mode_drift="$(find -P "$release_path" -type d ! -perm -u+w -print -quit 2>/dev/null || true)"

  if [ -z "$owner_drift$mode_drift" ]; then
    return 0
  fi

  log WARN "Release $(basename "$release_path") contem ownership/permissoes fora do padrao; reconciliando"
  repair_release_tree_permissions "$release_path"
}

remove_release_tree() {
  local release_path="$1"

  if ! assert_managed_release_path "$release_path"; then
    return 1
  fi

  if rm -rf -- "$release_path"; then
    return 0
  fi

  log WARN "Falha ao remover $(basename "$release_path") com o usuario atual; tentando recuperar permissoes"
  if ! repair_release_tree_permissions "$release_path"; then
    return 1
  fi
  if rm -rf -- "$release_path"; then
    return 0
  fi

  log WARN "Remocao de $(basename "$release_path") ainda falhou; tentando sudo como ultimo recurso"
  if ! sudo rm -rf -- "$release_path"; then
    log ERROR "Nao foi possivel remover a release $(basename "$release_path")"
    return 1
  fi
}

rollback_to() {
  local previous_release="$1"
  if [ -z "$previous_release" ] || [ ! -d "$RELEASES_DIR/$previous_release" ]; then
    log ERROR "Rollback indisponivel: release anterior ausente"
    return 1
  fi
  if ! ln -sfn "releases/$previous_release" "$CURRENT_LINK.tmp"; then
    log ERROR "Rollback falhou ao preparar o symlink anterior"
    return 1
  fi
  if ! mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"; then
    log ERROR "Rollback falhou ao restaurar o symlink current"
    return 1
  fi
  if ! restart_runtime_services; then
    log ERROR "Rollback restaurou o symlink, mas nao reiniciou as integracoes"
    return 1
  fi
  if ! sudo systemctl reload apache2; then
    log ERROR "Rollback restaurou o symlink, mas nao recarregou o Apache"
    return 1
  fi
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

reconcile_runtime_secrets() {
  local release_path="$1"
  local materializer="$release_path/scripts/materialize-runtime-secrets.php"
  local runtime="$SHARED_DIR/runtime-secrets.php"
  local runtime_link="$release_path/config/runtime-secrets.php"
  local runtime_target

  if [ ! -r "$materializer" ]; then
    log ERROR "Materializador de runtime secrets ausente em $release_path"
    return 1
  fi
  if [ ! -f "$SHARED_DIR/.env" ]; then
    log ERROR "Configuracao compartilhada ausente: $SHARED_DIR/.env"
    return 1
  fi

  if ! php "$materializer" >> "$LOG_FILE" 2>&1; then
    log ERROR "Materializacao do runtime minimo falhou"
    return 1
  fi
  # O materializador reescreve as permissoes do .env. Reconcilie os dois
  # arquivos depois dele para garantir leitura somente por ubuntu/www-data.
  if ! sudo chown ubuntu:www-data "$SHARED_DIR/.env" "$runtime" \
    || ! chmod 0640 "$SHARED_DIR/.env" "$runtime"; then
    log ERROR "Nao foi possivel aplicar proprietario/permissoes ao runtime compartilhado"
    return 1
  fi

  runtime_target="$(readlink -f "$runtime_link" 2>/dev/null || true)"
  if [ "$runtime_target" != "$runtime" ]; then
    if ! rm -f -- "$runtime_link"; then
      log ERROR "Nao foi possivel remover o runtime link anterior"
      return 1
    fi
    if ! ln -s "../../../shared/runtime-secrets.php" "$runtime_link"; then
      log ERROR "Nao foi possivel criar o runtime link"
      return 1
    fi
  fi
  if [ "$(readlink -f "$runtime_link" 2>/dev/null || true)" != "$runtime" ]; then
    log ERROR "Symlink de runtime secrets invalido"
    return 1
  fi

  if ! sudo -u www-data php -r '
    $values = require $argv[1];
    $user = (string)($values["DB_USER"] ?? $values["DB_USERNAME"] ?? "");
    $key = (string)($values["QUOTE_SIGNING_KEY"] ?? $values["APP_KEY"] ?? $values["SHOPVIVALIZ_APP_KEY"] ?? $values["SHOPVIVALIZ_AGENT_KEY"] ?? "");
    exit(is_array($values) && $user !== "" && strtolower($user) !== "root" && strlen($key) >= 32 ? 0 : 1);
  ' "$runtime_link"; then
    log ERROR "Runtime minimo invalido ou ilegivel pelo SAPI web"
    return 1
  fi

  log INFO "Runtime minimo reconciliado para o SAPI web"
}

verify_runtime_health() {
  local orders_body olist_body orders_code olist_code
  orders_body="$(mktemp)"
  olist_body="$(mktemp)"

  orders_code="$(curl --silent --show-error --output "$orders_body" --max-time 15 -H 'Host: shopvivaliz.com.br' --write-out '%{http_code}' 'http://127.0.0.1/api/orders/health.php' || true)"
  olist_code="$(curl --silent --show-error --output "$olist_body" --max-time 15 -H 'Host: shopvivaliz.com.br' --write-out '%{http_code}' 'http://127.0.0.1/api/olist/webhook-health.php' || true)"

  if python3 - "$orders_code" "$olist_code" "$orders_body" "$olist_body" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

orders_code, olist_code, orders_path, olist_path = sys.argv[1:]
orders = json.loads(Path(orders_path).read_text(encoding="utf-8"))
olist = json.loads(Path(olist_path).read_text(encoding="utf-8"))
valid = (
    int(orders_code) == 200
    and orders.get("ok") is True
    and orders.get("checks", {}).get("quote_signing_configured") is True
    and int(olist_code) == 200
    and olist.get("ok") is True
    and olist.get("checks", {}).get("database_connected") is True
)
if not valid:
    raise SystemExit(1)
PY
  then
    rm -f -- "$orders_body" "$olist_body"
    log INFO "Health runtime confirmado: pedidos, assinatura, Olist e banco"
    return 0
  fi

  log ERROR "Health runtime nao confirmou pedidos/Olist"
  cat "$orders_body" "$olist_body" >> "$LOG_FILE" 2>/dev/null || :
  rm -f -- "$orders_body" "$olist_body"
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
  if ! reconcile_runtime_secrets "$CURRENT_LINK" || ! verify_runtime_health; then
    write_status failure "$REMOTE_SHA" "$ACTIVE_RELEASE" "release alinhada, mas runtime compartilhado invalido"
    exit 1
  fi
  log INFO "Producao e runtime ja alinhados em $REMOTE_SHA"
  write_status success "$REMOTE_SHA" "$ACTIVE_RELEASE" "release e runtime ja estavam alinhados"
  exit 0
fi

RELEASE_TIME="$(date +%Y%m%d-%H%M%S)"
NEW_RELEASE="$RELEASE_TIME-${REMOTE_SHA:0:8}"
NEW_RELEASE_PATH="$RELEASES_DIR/$NEW_RELEASE"
log INFO "Criando release $NEW_RELEASE"
mkdir -p "$NEW_RELEASE_PATH"
if ! (cd "$REPO_DIR" && git archive "$REMOTE_SHA") | tar -xf - -C "$NEW_RELEASE_PATH"; then
  if ! remove_release_tree "$NEW_RELEASE_PATH"; then
    log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de extracao"
  fi
  log ERROR "Extracao da release falhou"
  exit 1
fi
printf '%s\n' "$REMOTE_SHA" > "$NEW_RELEASE_PATH/.release-sha"

declare -a SYMLINKS=(
  ".env"
  "uploads"
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
      if ! remove_release_tree "$NEW_RELEASE_PATH"; then
        log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de configuracao"
      fi
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
    if ! remove_release_tree "$NEW_RELEASE_PATH"; then
      log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de symlink"
    fi
    log ERROR "Symlink compartilhado invalido: $name"
    exit 1
  fi
done

if [ -f "$NEW_RELEASE_PATH/scripts/apply-storefront-hardening-migration.php" ]; then
  log INFO "Aplicando migracao idempotente de estoque e newsletter"
  if ! php "$NEW_RELEASE_PATH/scripts/apply-storefront-hardening-migration.php" >> "$LOG_FILE" 2>&1; then
    if ! remove_release_tree "$NEW_RELEASE_PATH"; then
      log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de migracao"
    fi
    log ERROR "Migracao de storefront falhou; release nao foi ativada"
    write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "migracao de storefront falhou"
    exit 1
  fi
fi

if ! reconcile_runtime_secrets "$NEW_RELEASE_PATH"; then
  if ! remove_release_tree "$NEW_RELEASE_PATH"; then
    log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de runtime"
  fi
  exit 1
fi

if [ -f "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" ]; then
  php "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" >> "$LOG_FILE" 2>&1
fi

# Reconcile Apache perimeter rules from the exact release being deployed.
# This keeps the OAuth allowlist in sync without making the rest of /olist public.
if [ -x "$NEW_RELEASE_PATH/scripts/install-apache-hardening.sh" ]; then
  sudo bash "$NEW_RELEASE_PATH/scripts/install-apache-hardening.sh" >> "$LOG_FILE" 2>&1
fi

if ! ensure_release_tree_cleanup_safe "$NEW_RELEASE_PATH"; then
  if ! remove_release_tree "$NEW_RELEASE_PATH"; then
    log WARN "Falha ao limpar a release incompleta $NEW_RELEASE apos erro de ownership/permissoes"
  fi
  write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "release criada com ownership/permissoes invalidos"
  exit 1
fi

php -l "$NEW_RELEASE_PATH/index.php" > /dev/null
php -l "$NEW_RELEASE_PATH/api/health/version.php" > /dev/null
if [ -f "$NEW_RELEASE_PATH/scripts/apply-storefront-hardening-migration.php" ]; then
  php -l "$NEW_RELEASE_PATH/scripts/apply-storefront-hardening-migration.php" > /dev/null
fi

ln -sfn "releases/$NEW_RELEASE" "$CURRENT_LINK.tmp"
mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"

if ! restart_runtime_services; then
  if ! rollback_to "$ACTIVE_RELEASE"; then
    log ERROR "Rollback apos falha dos servicos de integracao tambem falhou"
  fi
  write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "reinicio dos servicos de integracao falhou"
  exit 1
fi

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

if ! verify_runtime_health; then
  rollback_to "$ACTIVE_RELEASE"
  write_status failure "$REMOTE_SHA" "$NEW_RELEASE" "health runtime de pedidos/Olist falhou"
  exit 1
fi

mapfile -t OLD_RELEASES < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r | tail -n +$((RETENTION_COUNT + 1)))
for old_release in "${OLD_RELEASES[@]}"; do
  if [ "$old_release" != "$NEW_RELEASE" ] && [ "$old_release" != "$ACTIVE_RELEASE" ]; then
    if ! remove_release_tree "$RELEASES_DIR/$old_release"; then
      log WARN "Retencao nao conseguiu remover $old_release; deploy seguira sem bloquear a release ativa"
    fi
  fi
done

write_status success "$REMOTE_SHA" "$NEW_RELEASE" "deploy, sync, migracao e health confirmados"
log INFO "=== Deploy concluido em $REMOTE_SHA ==="
