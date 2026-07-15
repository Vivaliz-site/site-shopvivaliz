#!/bin/bash
#
# INSTALAÇÃO E CORREÇÃO DO AUTO-SYNC DA SHOPVIVALIZ
# VM Oracle: 137.131.156.17
# Repositório: /home/ubuntu/site-shopvivaliz
#
# Uso: sudo bash shopvivaliz-auto-sync-install.sh
#

set -Eeuo pipefail

echo "════════════════════════════════════════════════════════════"
echo "🔧 INSTALAÇÃO/CORREÇÃO DO AUTO-SYNC SHOPVIVALIZ"
echo "════════════════════════════════════════════════════════════"
echo ""

# ============================================================================
# PARTE 1: DIAGNOSTICAR
# ============================================================================
echo "📋 PARTE 1: DIAGNOSTICANDO ESTADO ATUAL..."
echo ""

echo "[1.1] Logs recentes do service:"
journalctl -u shopvivaliz-auto-sync.service -n 30 --no-pager -l 2>&1 | tail -20 || echo "(Não encontrado ou sem permissão)"

echo ""
echo "[1.2] Status do repositório Git:"
cd /home/ubuntu/site-shopvivaliz || { echo "❌ Repositório não encontrado"; exit 1; }
echo "Branch atual: $(git branch --show-current)"
echo "HEAD: $(git rev-parse HEAD)"
echo "Origin/main: $(git rev-parse origin/main 2>/dev/null || echo 'não sincronizado')"
echo ""
echo "Arquivos alterados:"
git status --porcelain 2>&1 || true

echo ""
echo "[1.3] Permissões atuais:"
ls -lah /usr/local/bin/shopvivaliz-auto-sync.sh 2>&1 || echo "(arquivo não existe)"
ls -lah /var/log/shopvivaliz-auto-sync.log 2>&1 || echo "(arquivo não existe)"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 2: CRIANDO NOVO SCRIPT (com correções)"
echo "════════════════════════════════════════════════════════════"
echo ""

# ============================================================================
# PARTE 2: CRIAR NOVO SCRIPT
# ============================================================================

cat > /usr/local/bin/shopvivaliz-auto-sync.sh << 'SCRIPT'
#!/bin/bash
#
# SHOPVIVALIZ AUTO-SYNC SCRIPT
# Sincroniza /home/ubuntu/site-shopvivaliz com GitHub main
#
# Behavior:
# - Ignora: .agent-heartbeats/*, tasks-queue.json
# - Bloqueia: qualquer outro arquivo alterado
# - Nunca faz commit/push da VM
# - Nunca apaga arquivos
# - Log: /var/log/shopvivaliz-auto-sync.log
#

set -Eeuo pipefail

# ============================================================================
# CONFIG
# ============================================================================

REPO_DIR="/home/ubuntu/site-shopvivaliz"
LOCK_FILE="/run/lock/shopvivaliz-auto-sync.lock"
LOG_FILE="/var/log/shopvivaliz-auto-sync.log"
ALLOWED_MODIFIED_FILES=(
    ".agent-heartbeats/claude.heartbeat"
    ".agent-heartbeats/gemini.heartbeat"
    ".agent-heartbeats/gpt.heartbeat"
    "tasks-queue.json"
)

# ============================================================================
# FUNCTIONS
# ============================================================================

log() {
    local msg="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $msg" | tee -a "$LOG_FILE"
}

log_error() {
    local msg="$1"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] ERROR: $msg" | tee -a "$LOG_FILE"
}

cleanup() {
    rm -f "$LOCK_FILE"
}

is_allowed_file() {
    local file="$1"
    local allowed_file
    for allowed_file in "${ALLOWED_MODIFIED_FILES[@]}"; do
        if [[ "$file" == "$allowed_file" ]]; then
            return 0
        fi
    done
    return 1
}

# ============================================================================
# SETUP
# ============================================================================

# Criar log se não existir
mkdir -p "$(dirname "$LOG_FILE")"
touch "$LOG_FILE"

# Criar lock se não existir
mkdir -p "$(dirname "$LOCK_FILE")"

trap cleanup EXIT

log "════════════════════════════════════════════════════════════"
log "🔄 Iniciando sincronização automática"
log "════════════════════════════════════════════════════════════"

# ============================================================================
# LOCK (evitar execução simultânea)
# ============================================================================

if ! mkdir -p "$(dirname "$LOCK_FILE")" 2>/dev/null; then
    log_error "Falha ao criar diretório de lock"
    exit 1
fi

if [[ ! -f "$LOCK_FILE" ]]; then
    log "Adquirindo lock..."
    touch "$LOCK_FILE"
else
    log_error "Outra sincronização em progresso (lock existe)"
    exit 1
fi

# ============================================================================
# VALIDAR REPOSITÓRIO
# ============================================================================

if [[ ! -d "$REPO_DIR/.git" ]]; then
    log_error "Repositório Git não encontrado em $REPO_DIR"
    exit 1
fi

cd "$REPO_DIR"
log "✓ Repositório encontrado: $REPO_DIR"

# ============================================================================
# VERIFICAR BRANCH
# ============================================================================

CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$CURRENT_BRANCH" != "main" ]]; then
    log_error "Branch atual é '$CURRENT_BRANCH', esperado 'main'"
    log "Checando out main..."
    git checkout main 2>&1 | tee -a "$LOG_FILE"
fi

log "✓ Branch: main"

# ============================================================================
# VERIFICAR ARQUIVOS MODIFICADOS
# ============================================================================

log ""
log "Verificando arquivos modificados..."

MODIFIED_FILES=$(git status --porcelain | awk '{print $2}' || true)

if [[ -z "$MODIFIED_FILES" ]]; then
    log "✓ Working tree limpo"
else
    log "Arquivos alterados encontrados:"

    BLOCKING_FILES=()

    while IFS= read -r file; do
        if is_allowed_file "$file"; then
            log "  ℹ️  $file (ignorado - arquivo de runtime)"
        else
            log "  ⚠️  $file (BLOQUEADO - arquivo real)"
            BLOCKING_FILES+=("$file")
        fi
    done <<< "$MODIFIED_FILES"

    if [[ ${#BLOCKING_FILES[@]} -gt 0 ]]; then
        log_error "Working tree tem alterações bloqueantes:"
        for bf in "${BLOCKING_FILES[@]}"; do
            log_error "  - $bf"
        done
        log_error "Abortando sincronização para evitar perda de trabalho"
        exit 1
    fi

    log "✓ Arquivos de runtime permitidos, prosseguindo..."
fi

# ============================================================================
# FETCH DO REMOTO
# ============================================================================

log ""
log "Fazendo git fetch origin main..."

if ! git fetch origin main 2>&1 | tee -a "$LOG_FILE"; then
    log_error "Falha no git fetch"
    exit 1
fi

log "✓ Fetch completo"

# ============================================================================
# COMPARAR BRANCHES
# ============================================================================

log ""
log "Comparando HEAD com origin/main..."

HEAD=$(git rev-parse HEAD)
ORIGIN_MAIN=$(git rev-parse origin/main)

log "  HEAD:         $HEAD"
log "  origin/main:  $ORIGIN_MAIN"

if [[ "$HEAD" == "$ORIGIN_MAIN" ]]; then
    log "✓ Já está sincronizado (sem alterações)"
    log "════════════════════════════════════════════════════════════"
    exit 0
fi

log "⚠️  Há diferenças entre HEAD e origin/main"

# ============================================================================
# VERIFICAR NOVAMENTE (safety check)
# ============================================================================

log ""
log "Safety check: verificando working tree novamente..."

MODIFIED_COUNT=$(git status --porcelain | wc -l)

if [[ $MODIFIED_COUNT -gt 0 ]]; then
    log "Working tree modificado no safety check:"
    git status --porcelain | tee -a "$LOG_FILE"

    # Permitir somente arquivos conhecidos
    BLOCKING_FOUND=0
    while IFS= read -r line; do
        file=$(echo "$line" | awk '{print $2}')
        if ! is_allowed_file "$file"; then
            log_error "Arquivo bloqueante encontrado: $file"
            BLOCKING_FOUND=1
        fi
    done < <(git status --porcelain)

    if [[ $BLOCKING_FOUND -eq 1 ]]; then
        log_error "Safety check falhou - abortando reset"
        exit 1
    fi

    log "✓ Apenas arquivos de runtime modificados, prosseguindo..."
fi

# ============================================================================
# RESET PARA ORIGIN/MAIN
# ============================================================================

log ""
log "Executando git reset --hard origin/main..."

if git reset --hard origin/main 2>&1 | tee -a "$LOG_FILE"; then
    log "✓ Reset executado com sucesso"
else
    log_error "Falha no git reset"
    exit 1
fi

# ============================================================================
# VERIFICAÇÃO FINAL
# ============================================================================

log ""
log "Verificação final..."

FINAL_HEAD=$(git rev-parse HEAD)
if [[ "$FINAL_HEAD" == "$ORIGIN_MAIN" ]]; then
    log "✓ HEAD e origin/main agora são idênticos"
    log "  HEAD: $FINAL_HEAD"
else
    log_error "Sincronização incompleta"
    log_error "  HEAD:        $FINAL_HEAD"
    log_error "  origin/main: $ORIGIN_MAIN"
    exit 1
fi

log ""
log "════════════════════════════════════════════════════════════"
log "✅ SINCRONIZAÇÃO COMPLETA"
log "════════════════════════════════════════════════════════════"

exit 0
SCRIPT

chmod 755 /usr/local/bin/shopvivaliz-auto-sync.sh
log "✓ Script criado em /usr/local/bin/shopvivaliz-auto-sync.sh"

# ============================================================================
# PARTE 3: CRIAR SERVICE
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 3: CRIANDO SERVICE SYSTEMD"
echo "════════════════════════════════════════════════════════════"
echo ""

sudo tee /etc/systemd/system/shopvivaliz-auto-sync.service > /dev/null << 'SERVICE'
[Unit]
Description=ShopVivaliz Auto-Sync from GitHub
Documentation=https://github.com/Vivaliz-site/site-shopvivaliz
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=ubuntu
Group=ubuntu
WorkingDirectory=/home/ubuntu/site-shopvivaliz

# Script
ExecStart=/usr/local/bin/shopvivaliz-auto-sync.sh

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=shopvivaliz-sync

# Safety
TimeoutStartSec=300
Restart=no

# Environment
Environment="PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
Environment="HOME=/home/ubuntu"

[Install]
WantedBy=multi-user.target
SERVICE

echo "✓ Service criado"

# ============================================================================
# PARTE 4: CRIAR TIMER
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 4: CRIANDO TIMER SYSTEMD"
echo "════════════════════════════════════════════════════════════"
echo ""

sudo tee /etc/systemd/system/shopvivaliz-auto-sync.timer > /dev/null << 'TIMER'
[Unit]
Description=ShopVivaliz Auto-Sync Timer
Documentation=https://github.com/Vivaliz-site/site-shopvivaliz

[Timer]
# Sincroniza a cada 5 minutos
OnBootSec=30s
OnUnitActiveSec=5min

# Randomizar para evitar burst
RandomizedDelaySec=10s

# Logs
LogNamespace=shopvivaliz

[Install]
WantedBy=timers.target
TIMER

echo "✓ Timer criado"

# ============================================================================
# PARTE 5: PERMISSÕES
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 5: CONFIGURANDO PERMISSÕES"
echo "════════════════════════════════════════════════════════════"
echo ""

# Script
sudo chmod 755 /usr/local/bin/shopvivaliz-auto-sync.sh
echo "✓ Script: 755"

# Log directory
sudo mkdir -p /var/log
sudo touch /var/log/shopvivaliz-auto-sync.log
sudo chmod 666 /var/log/shopvivaliz-auto-sync.log
echo "✓ Log: 666"

# Lock directory
sudo mkdir -p /run/lock
sudo chmod 777 /run/lock
echo "✓ Lock: 777"

# Repositório
sudo chown -R ubuntu:ubuntu /home/ubuntu/site-shopvivaliz
sudo chmod -R u+w /home/ubuntu/site-shopvivaliz
echo "✓ Repositório: ubuntu:ubuntu"

# ============================================================================
# PARTE 6: RECARREGAR SYSTEMD
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 6: ATIVANDO SERVICE"
echo "════════════════════════════════════════════════════════════"
echo ""

sudo systemctl daemon-reload
echo "✓ daemon-reload"

sudo systemctl enable shopvivaliz-auto-sync.service shopvivaliz-auto-sync.timer
echo "✓ Services habilitados"

sudo systemctl restart shopvivaliz-auto-sync.service shopvivaliz-auto-sync.timer
echo "✓ Services reiniciados"

# ============================================================================
# VALIDAÇÃO
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ PARTE 7: VALIDANDO"
echo "════════════════════════════════════════════════════════════"
echo ""

echo "[Validação 1] Syntax check do script:"
sudo bash -n /usr/local/bin/shopvivaliz-auto-sync.sh && echo "✓ Syntax OK" || echo "❌ Syntax ERROR"

echo ""
echo "[Validação 2] Status do service:"
sudo systemctl status shopvivaliz-auto-sync.service --no-pager -l || true

echo ""
echo "[Validação 3] Status do timer:"
sudo systemctl status shopvivaliz-auto-sync.timer --no-pager -l || true

echo ""
echo "[Validação 4] Próxima execução:"
sudo systemctl list-timers shopvivaliz-auto-sync.timer --no-pager || true

echo ""
echo "[Validação 5] Ultimos 20 logs:"
tail -n 20 /var/log/shopvivaliz-auto-sync.log 2>&1 || echo "(Log vazio)"

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ INSTALAÇÃO COMPLETA"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "Próximos passos:"
echo "1. Executar uma sincronização manual para testar:"
echo "   sudo /usr/local/bin/shopvivaliz-auto-sync.sh"
echo ""
echo "2. Ver logs:"
echo "   tail -f /var/log/shopvivaliz-auto-sync.log"
echo ""
echo "3. Ver status do timer:"
echo "   systemctl list-timers shopvivaliz-auto-sync.timer"
echo ""
