#!/bin/bash
# Validate Release — Verificações pré-deploy

set -Eeuo pipefail

readonly RELEASE_PATH="${1:-.}"
readonly LOG_FILE="/tmp/validate-release-$$.log"

log() {
  local level="$1"
  shift
  echo "[$(date -u +'%Y-%m-%d %H:%M:%S UTC')] [$level] $@" | tee -a "$LOG_FILE"
}

log "INFO" "=== Validando release: $RELEASE_PATH ==="

# Check PHP files
log "INFO" "Validando sintaxe PHP..."
ERRORS=0
find "$RELEASE_PATH" -type f -name "*.php" | while read -r file; do
  if ! php -l "$file" > /dev/null 2>&1; then
    log "ERROR" "PHP error em: $file"
    ((ERRORS++))
  fi
done

if [ $ERRORS -gt 0 ]; then
  log "FATAL" "Encontrados $ERRORS erros PHP"
  exit 1
fi
log "INFO" "✓ PHP syntax OK"

# Check essential files
log "INFO" "Verificando arquivos essenciais..."
ESSENTIALS=(
  "index.php"
)

for file in "${ESSENTIALS[@]}"; do
  if [ ! -f "$RELEASE_PATH/$file" ]; then
    log "ERROR" "Arquivo essencial faltando: $file"
    exit 1
  fi
done
log "INFO" "✓ Arquivos essenciais presentes"

# Check permissions
log "INFO" "Verificando permissões..."
if [ ! -r "$RELEASE_PATH" ]; then
  log "ERROR" "Release não é legível"
  exit 1
fi
if [ ! -x "$RELEASE_PATH" ]; then
  log "ERROR" "Release não é executável (diretório)"
  exit 1
fi
log "INFO" "✓ Permissões OK"

# Check symlinks to shared
log "INFO" "Verificando symlinks para shared..."
SYMLINKS=".env uploads logs cache sessions storage"
for symlink in $SYMLINKS; do
  if [ -L "$RELEASE_PATH/$symlink" ]; then
    log "INFO" "✓ Symlink encontrado: $symlink"
  else
    log "WARN" "Symlink faltando (será criado no deploy): $symlink"
  fi
done

log "INFO" "=== Validação concluída com sucesso ==="
exit 0
