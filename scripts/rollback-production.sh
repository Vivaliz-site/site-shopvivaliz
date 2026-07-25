#!/bin/bash
# Rollback Production — Restaurar release anterior
# Uso: sudo rollback-production.sh [<release>]

set -Eeuo pipefail

readonly RELEASES_DIR="/home/ubuntu/shopvivaliz-deploy/releases"
readonly CURRENT_LINK="/home/ubuntu/shopvivaliz-deploy/current"
readonly LOG_FILE="/var/log/shopvivaliz-deploy.log"

# Logging
log() {
  local level="$1"
  shift
  local msg="$*"
  echo "[$(date -u +'%Y-%m-%d %H:%M:%S UTC')] [$level] $msg" | tee -a "$LOG_FILE"
}

cleanup() {
  local exit_code=$?
  if [ $exit_code -eq 0 ]; then
    log "INFO" "Rollback concluído com sucesso"
  else
    log "ERROR" "Rollback falhou com código $exit_code"
  fi
}
trap cleanup EXIT

# Pre-checks
if [ ! -d "$RELEASES_DIR" ]; then
  log "FATAL" "Releases dir não existe: $RELEASES_DIR"
  exit 1
fi

log "INFO" "=== Rollback iniciado ==="

# List releases
RELEASES=$(ls -1t "$RELEASES_DIR"/ | head -10)
if [ -z "$RELEASES" ]; then
  log "FATAL" "Nenhuma release encontrada"
  exit 1
fi

# Get current active
if [ -L "$CURRENT_LINK" ] && [ -e "$CURRENT_LINK" ]; then
  CURRENT_RELEASE=$(basename "$(readlink -f "$CURRENT_LINK")")
  log "INFO" "Release ativa: $CURRENT_RELEASE"
else
  CURRENT_RELEASE=""
  log "WARN" "Nenhuma release ativa encontrada"
fi

# Determine target release
if [ -n "${1:-}" ]; then
  # User specified release
  TARGET_RELEASE="$1"
  if [ ! -d "$RELEASES_DIR/$TARGET_RELEASE" ]; then
    log "ERROR" "Release especificada não existe: $TARGET_RELEASE"
    log "INFO" "Releases disponíveis:"
    ls -1t "$RELEASES_DIR"/ | head -10 | sed 's/^/  /'
    exit 1
  fi
else
  # Use previous release (first different from current)
  TARGET_RELEASE=""
  for release in $RELEASES; do
    if [ "$release" != "$CURRENT_RELEASE" ]; then
      TARGET_RELEASE="$release"
      break
    fi
  done

  if [ -z "$TARGET_RELEASE" ]; then
    log "ERROR" "Nenhuma release anterior encontrada"
    exit 1
  fi
fi

log "INFO" "Target release: $TARGET_RELEASE"
log "INFO" "Releases disponíveis:"
ls -1t "$RELEASES_DIR"/ | head -10 | sed 's/^/  /'

# Validate target
if [ ! -d "$RELEASES_DIR/$TARGET_RELEASE" ]; then
  log "ERROR" "Release target não existe: $TARGET_RELEASE"
  exit 1
fi

if [ ! -f "$RELEASES_DIR/$TARGET_RELEASE/index.php" ]; then
  log "ERROR" "index.php não encontrado em $TARGET_RELEASE"
  exit 1
fi

# Backup current symlink
BACKUP_SYMLINK_TARGET=$(readlink -f "$CURRENT_LINK" 2>/dev/null || echo "")
log "INFO" "Backup do symlink atual: $BACKUP_SYMLINK_TARGET"

# Atomic swap
log "INFO" "Trocando symlink para $TARGET_RELEASE (atômico)..."

if ! ln -sfn "releases/$TARGET_RELEASE" "$CURRENT_LINK.tmp"; then
  log "ERROR" "Falha ao criar symlink temporário"
  exit 1
fi

if ! mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"; then
  log "ERROR" "Falha ao trocar symlink (mv -Tf)"
  rm -f "$CURRENT_LINK.tmp"
  exit 1
fi

log "INFO" "✓ Symlink trocado"

# Reload Apache
log "INFO" "Recarregando Apache..."
if ! sudo systemctl reload apache2; then
  log "ERROR" "systemctl reload falhou. Restaurando..."
  if [ -n "$BACKUP_SYMLINK_TARGET" ]; then
    BACKUP_RELEASE=$(basename "$BACKUP_SYMLINK_TARGET")
    if ln -sfn "releases/$BACKUP_RELEASE" "$CURRENT_LINK.tmp"; then
      mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
      sudo systemctl reload apache2 || log "ERROR" "Restauração Apache falhou"
      log "WARN" "Release anterior restaurada"
    fi
  fi
  exit 1
fi
log "INFO" "✓ Apache recarregado"

# Health check
log "INFO" "Executando health check..."
sleep 2

if curl -s -f http://127.0.0.1 > /dev/null 2>&1; then
  log "INFO" "✓ Health check OK"
else
  log "ERROR" "Health check falhou. Restaurando..."
  if [ -n "$BACKUP_SYMLINK_TARGET" ]; then
    BACKUP_RELEASE=$(basename "$BACKUP_SYMLINK_TARGET")
    if ln -sfn "releases/$BACKUP_RELEASE" "$CURRENT_LINK.tmp"; then
      mv -Tf "$CURRENT_LINK.tmp" "$CURRENT_LINK"
      sudo systemctl reload apache2 || log "ERROR" "Restauração Apache falhou"
      log "WARN" "Release anterior restaurada"
    fi
  fi
  exit 1
fi

log "INFO" "✓ Rollback concluído com sucesso"
log "INFO" "Release ativa: $TARGET_RELEASE"
exit 0
