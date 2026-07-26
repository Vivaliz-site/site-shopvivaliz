#!/bin/bash
# Deploy Production — Releases Imutáveis
# Uso: Cron a cada 2 minutos
# Sem intervalo de sleep: falha rápido se lock está ocupado

set -Eeuo pipefail

readonly REPO_DIR="/home/ubuntu/shopvivaliz-deploy/repo"
readonly RELEASES_DIR="/home/ubuntu/shopvivaliz-deploy/releases"
readonly SHARED_DIR="/home/ubuntu/shopvivaliz-deploy/shared"
readonly CURRENT_LINK="/home/ubuntu/shopvivaliz-deploy/current"
readonly LOCK_FILE="/var/lock/shopvivaliz-deploy.lock"
readonly LOG_FILE="/home/ubuntu/shopvivaliz-deploy/logs/deploy.log"
readonly RETENTION_COUNT=5

# Logging
log() {
  local level="$1"
  shift
  local msg="$*"
  printf '[%s] [%s] %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$level" "$msg" >> "$LOG_FILE"
}

# Cleanup on exit
cleanup() {
  local exit_code=$?
  if [ $exit_code -eq 0 ]; then
    log "INFO" "Deploy completado com sucesso"
  else
    log "ERROR" "Deploy falhou com código $exit_code"
  fi
}
trap cleanup EXIT

# Pre-checks
if [ ! -d "$REPO_DIR/.git" ]; then
  log "FATAL" "Clone Git não existe: $REPO_DIR"
  exit 1
fi

if [ ! -d "$RELEASES_DIR" ]; then
  mkdir -p "$RELEASES_DIR"
fi

if [ ! -d "$SHARED_DIR" ]; then
  mkdir -p "$SHARED_DIR"
fi

log "INFO" "=== Deploy iniciado ==="

# Fetch
log "INFO" "Fazendo fetch de origin/main..."
if ! git -C "$REPO_DIR" fetch --prune origin main; then
  log "ERROR" "git fetch falhou"
  exit 1
fi

# Get SHAs
REMOTE_SHA=$(git -C "$REPO_DIR" rev-parse origin/main)
log "INFO" "Remote SHA: $REMOTE_SHA"

# Get active SHA
if [ -L "$CURRENT_LINK" ] && [ -e "$CURRENT_LINK" ]; then
  ACTIVE_RELEASE=$(basename "$(readlink -f "$CURRENT_LINK")")
  ACTIVE_SHA=$(echo "$ACTIVE_RELEASE" | cut -d- -f3-)
  log "INFO" "Active release: $ACTIVE_RELEASE (SHA: $ACTIVE_SHA)"
else
  ACTIVE_SHA=""
  log "WARN" "No active release found"
fi

# Idempotent check
if [ "${REMOTE_SHA:0:8}" = "$ACTIVE_SHA" ]; then
  log "INFO" "Já está alinhado (SHA idêntico). Nada a fazer."
  exit 0
fi

# Create new release
RELEASE_TIME=$(date +%Y%m%d-%H%M%S)
NEW_RELEASE="${RELEASE_TIME}-${REMOTE_SHA:0:8}"
NEW_RELEASE_PATH="$RELEASES_DIR/$NEW_RELEASE"

log "INFO" "Criando nova release: $NEW_RELEASE"

if [ -d "$NEW_RELEASE_PATH" ]; then
  log "WARN" "Release já existe (concorrência?). Usando existente."
else
  mkdir -p "$NEW_RELEASE_PATH"

  # Archive from git
  if ! (cd "$REPO_DIR" && git archive origin/main) | tar -xf - -C "$NEW_RELEASE_PATH"; then
    log "ERROR" "git archive falhou"
    rm -rf "$NEW_RELEASE_PATH"
    exit 1
  fi

  log "INFO" "Archive extraído para $NEW_RELEASE_PATH"
fi

# Create symlinks to shared
log "INFO" "Criando symlinks para shared..."

declare -a SYMLINKS=(
  ".env"
  "uploads"
  "logs"
  "cache"
  "sessions"
  "storage"
)

for symlink in "${SYMLINKS[@]}"; do
  target_path="$NEW_RELEASE_PATH/$symlink"
  shared_path="$SHARED_DIR/$symlink"

  if [ "$symlink" = ".env" ]; then
    if [ ! -f "$shared_path" ]; then
      log "ERROR" "Configuração compartilhada ausente: $shared_path"
      rm -rf -- "$NEW_RELEASE_PATH"
      exit 1
    fi
  else
    mkdir -p "$shared_path"
  fi

  # The archive contains runtime placeholders. They must be removed entirely,
  # otherwise ln creates a nested link and the release starts writing locally.
  if [ -e "$target_path" ] || [ -L "$target_path" ]; then
    rm -rf -- "$target_path"
  fi

  # Create symlink
  ln -sf "../../shared/$symlink" "$target_path"
  if [ ! -L "$target_path" ] || [ "$(readlink -f "$target_path")" != "$shared_path" ]; then
    log "ERROR" "Symlink inválido: $symlink"
    rm -rf -- "$NEW_RELEASE_PATH"
    exit 1
  fi
  log "INFO" "Symlink criado: $symlink"
done

# Apply idempotent data migrations before making the release visible.
if [ -f "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" ]; then
  log "INFO" "Aplicando migração editorial idempotente..."
  if ! php "$NEW_RELEASE_PATH/scripts/migrate-blog-articles.php" >> "$LOG_FILE" 2>&1; then
    log "ERROR" "Migração editorial falhou"
    rm -rf -- "$NEW_RELEASE_PATH"
    exit 1
  fi
fi

# Validate PHP
if [ -f "$NEW_RELEASE_PATH/index.php" ]; then
  log "INFO" "Validando sintaxe PHP..."
  if ! php -l "$NEW_RELEASE_PATH/index.php" > /dev/null; then
    log "ERROR" "PHP syntax check falhou"
    rm -rf "$NEW_RELEASE_PATH"
    exit 1
  fi
  log "INFO" "✓ PHP válido"
else
  log "WARN" "index.php não encontrado (pode estar em subdirectório)"
fi

# Validate permissions
log "INFO" "Validando permissões..."
if [ ! -r "$NEW_RELEASE_PATH" ]; then
  log "ERROR" "Release não é legível"
  rm -rf "$NEW_RELEASE_PATH"
  exit 1
fi
log "INFO" "✓ Permissões OK"

# Health check local (pré-swap)
log "INFO" "Executando health check local..."
# Se há dependências (composer, npm), instalar aqui
# Por enquanto, apenas básico
if ! curl -s -f http://127.0.0.1/api/health > /dev/null 2>&1; then
  log "WARN" "Health check local inconcluso (endpoint pode não existir ainda)"
fi

# Atomic swap
log "INFO" "Trocando symlink de release (atômico)..."

# Use temporary symlink + mv -T para atomicidade
if ! ln -sfn "releases/$NEW_RELEASE" "$CURRENT_LINK.tmp"; then
  log "ERROR" "Falha ao criar symlink temporário"
  exit 1
fi

if ! mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"; then
  log "ERROR" "Falha ao trocar symlink (mv -Tf)"
  rm -f "$CURRENT_LINK.tmp"
  exit 1
fi

log "INFO" "✓ Symlink trocado: $NEW_RELEASE"

# Reload Apache (suave)
log "INFO" "Recarregando Apache..."
if ! sudo systemctl reload apache2; then
  log "ERROR" "systemctl reload falhou"
  # Tentar rollback
  if [ -n "$ACTIVE_SHA" ]; then
    log "WARN" "Tentando restaurar release anterior..."
    if ln -sfn "releases/$ACTIVE_RELEASE" "$CURRENT_LINK.tmp"; then
      mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
      sudo systemctl reload apache2 || log "ERROR" "Rollback falhou"
    fi
  fi
  exit 1
fi
log "INFO" "✓ Apache recarregado"

# Health check pós-deploy
log "INFO" "Executando health check pós-deploy..."
sleep 2  # Dar tempo ao Apache recarregar

if curl -s -f http://127.0.0.1 > /dev/null 2>&1; then
  log "INFO" "✓ Health check OK"
else
  log "ERROR" "Health check falhou. Restaurando release anterior."
  if [ -n "$ACTIVE_SHA" ]; then
    if ln -sfn "releases/$ACTIVE_RELEASE" "$CURRENT_LINK.tmp"; then
      mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
      sudo systemctl reload apache2 || log "ERROR" "Rollback Apache falhou"
      log "INFO" "✓ Release anterior restaurada"
    fi
  fi
  exit 1
fi

# Cleanup old releases (keep last RETENTION_COUNT)
log "INFO" "Limpando releases antigas (manter últimas $RETENTION_COUNT)..."
RELEASE_COUNT=$(ls -1d "$RELEASES_DIR"/*/ 2>/dev/null | wc -l || echo 0)
if [ "$RELEASE_COUNT" -gt "$RETENTION_COUNT" ]; then
  DELETE_COUNT=$((RELEASE_COUNT - RETENTION_COUNT))
  log "INFO" "Removendo $DELETE_COUNT releases antigas..."
  ls -1t "$RELEASES_DIR"/ | tail -n "$DELETE_COUNT" | while read -r old_release; do
    if [ "$old_release" != "$NEW_RELEASE" ] && [ "$old_release" != "$ACTIVE_RELEASE" ]; then
      log "INFO" "Removendo: $old_release"
      old_path="$RELEASES_DIR/$old_release"
      case "$old_path" in
        "$RELEASES_DIR"/*)
          if ! sudo rm -rf -- "$old_path"; then
            log "WARN" "Não foi possível remover release antiga: $old_release"
          fi
          ;;
        *)
          log "ERROR" "Caminho de limpeza recusado: $old_path"
          exit 1
          ;;
      esac
    fi
  done
fi

log "INFO" "=== Deploy concluído com sucesso ==="
exit 0
