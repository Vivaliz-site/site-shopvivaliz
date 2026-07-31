#!/bin/bash
# Health Check — Validação pós-deploy

set -Eeuo pipefail

log() {
  printf '[%s] %s\n' "$(date -u +'%Y-%m-%d %H:%M:%S UTC')" "$*"
}

log "=== Health Check iniciado ==="

# Básico
CHECKS=0
PASSED=0

# Check 1: localhost HTTP
log "Check 1: HTTP local..."
((CHECKS++))
if curl -s -f http://127.0.0.1 > /dev/null 2>&1; then
  log "✓ HTTP local OK"
  ((PASSED++))
else
  log "✗ HTTP local falhou"
fi

# Check 2: HTTPS público
log "Check 2: HTTPS público..."
((CHECKS++))
if curl -s -f https://shopvivaliz.com.br > /dev/null 2>&1; then
  log "✓ HTTPS público OK"
  ((PASSED++))
else
  log "✗ HTTPS público falhou"
fi

# Check 3: Apache status
log "Check 3: Apache status..."
((CHECKS++))
if sudo systemctl is-active --quiet apache2; then
  log "✓ Apache ativo"
  ((PASSED++))
else
  log "✗ Apache inativo"
fi

# Check 4: Symlink current
log "Check 4: Symlink current..."
((CHECKS++))
if [ -L /home/ubuntu/shopvivaliz-deploy/current ] && [ -e /home/ubuntu/shopvivaliz-deploy/current ]; then
  log "✓ Symlink válido: $(readlink /home/ubuntu/shopvivaliz-deploy/current)"
  ((PASSED++))
else
  log "✗ Symlink inválido"
fi

# Resultado
log "=== Resultado: $PASSED/$CHECKS checks passaram ==="

if [ "$PASSED" -eq "$CHECKS" ]; then
  exit 0
else
  exit 1
fi
