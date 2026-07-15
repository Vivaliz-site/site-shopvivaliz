#!/bin/bash
#
# ShopVivaliz auto-sync canonico
# Mantem o checkout do servidor alinhado com a branch remota oficial.
# Nao cria commits locais e nao faz push a partir do servidor.
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${SCRIPT_DIR}/logs/auto-sync-$(date +%Y-%m-%d).log"
SYNC_BRANCH="${SHOPVIVALIZ_SYNC_BRANCH:-main}"
SYNC_INTERVAL_SECONDS="${SHOPVIVALIZ_SYNC_INTERVAL_SECONDS:-300}"
STATUS_FILE="${SCRIPT_DIR}/logs/tri-environment-sync.json"

mkdir -p "${SCRIPT_DIR}/logs"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

sync_secrets() {
    log "Sincronizando .env.local seguro"
    cd "$SCRIPT_DIR"

    if bash scripts/sincronizar_secrets_github.sh >> "$LOG_FILE" 2>&1; then
        log "OK .env.local atualizado"
    else
        log "AVISO falha ao atualizar .env.local"
    fi

    if [ -f scripts/validar_secrets.py ]; then
        if python3 scripts/validar_secrets.py >> "$LOG_FILE" 2>&1; then
            log "OK validacao de secrets"
        else
            log "AVISO validacao de secrets com alertas"
        fi
    fi
}

sync_git() {
    log "Alinhando checkout com origin/${SYNC_BRANCH}"
    cd "$SCRIPT_DIR"

    if ! python3 git-auto-sync.py >> "$LOG_FILE" 2>&1; then
        log "ERRO git-auto-sync.py falhou"
        [ -f "$STATUS_FILE" ] && tail -n 20 "$STATUS_FILE" | tee -a "$LOG_FILE"
        return 1
    fi

    if [ -f "$STATUS_FILE" ]; then
        log "OK status salvo em logs/tri-environment-sync.json"
    fi

    return 0
}

main() {
    log "Auto-Sync canonico iniciado"
    log "Repositorio: $SCRIPT_DIR"
    log "Branch canonica: $SYNC_BRANCH"
    log "Intervalo: ${SYNC_INTERVAL_SECONDS}s"

    while true; do
        log ""
        log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
        sync_secrets
        sync_git || true
        log "Ciclo concluido"
        sleep "$SYNC_INTERVAL_SECONDS"
    done
}

trap "log 'Auto-Sync parado'; exit 0" SIGTERM SIGINT

main
