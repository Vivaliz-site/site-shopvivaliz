#!/bin/bash
#
# Auto-sincronização automática (Linux/Ubuntu/Cloud)
# ===================================================
#
# Roda como daemon sincronizando secrets a cada 5 minutos
# Coloque em ~/.bashrc ou crontab para rodar no boot
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_FILE="${SCRIPT_DIR}/logs/auto-sync-$(date +%Y-%m-%d).log"
ENV_FILE="${SCRIPT_DIR}/.env.local"

# Criar diretório de logs
mkdir -p "${SCRIPT_DIR}/logs"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Sincronizar secrets
sync_secrets() {
    log "🔄 Sincronizando secrets do GitHub..."

    cd "$SCRIPT_DIR"

    # Verificar gh CLI
    if ! command -v gh &> /dev/null; then
        log "❌ GitHub CLI não está instalado. Pulando sincronização."
        return 1
    fi

    # Sincronizar
    if bash scripts/sincronizar_secrets_github.sh >> "$LOG_FILE" 2>&1; then
        log "✅ Secrets sincronizados com sucesso"

        # Validar (não fazer fail se falhar)
        if python3 scripts/validar_secrets.py >> "$LOG_FILE" 2>&1; then
            log "✅ Validação passou"
        else
            log "⚠️  Validação teve aviso (continuando...)"
        fi
        return 0
    else
        log "❌ Erro ao sincronizar"
        return 1
    fi
}

# Sincronizar git
sync_git() {
    log "🔄 Sincronizando repositório..."

    cd "$SCRIPT_DIR"

    # Pull
    if git pull origin main >> "$LOG_FILE" 2>&1; then
        log "✅ Git pull OK"
    else
        log "⚠️  Git pull teve aviso"
    fi

    # Commit local
    if git diff-index --quiet HEAD -- 2>/dev/null; then
        log "✓ Sem mudanças locais"
    else
        log "📝 Commitando mudanças..."
        git add -A
        if git commit -m "auto: sincronizar $(date +'%Y-%m-%d %H:%M:%S')" >> "$LOG_FILE" 2>&1; then
            log "✅ Commit OK"
        fi
    fi

    # Push
    COMMITS=$(git rev-list --count origin/main..HEAD)
    if [ "$COMMITS" -gt 0 ]; then
        log "📤 Enviando $COMMITS commit(s)..."
        if git push origin main >> "$LOG_FILE" 2>&1; then
            log "✅ Push OK"
        else
            log "⚠️  Push teve aviso"
        fi
    fi
}

# Main loop
main() {
    log "🚀 Auto-Sync iniciado"
    log "   Repositório: $SCRIPT_DIR"
    log "   Log: $LOG_FILE"
    log "   Intervalo: 5 minutos"

    while true; do
        log ""
        log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

        # Sincronizar
        sync_secrets
        sync_git

        log "✅ Ciclo concluído"
        log "⏰ Próximo em 5 minutos..."

        sleep 300
    done
}

# Trap signals
trap "log '⏹️  Auto-Sync parado'; exit 0" SIGTERM SIGINT

# Executar
main
