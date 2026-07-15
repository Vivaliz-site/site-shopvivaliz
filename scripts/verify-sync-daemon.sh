#!/bin/bash
# VERIFICAÇÃO RIGOROSA DO DAEMON DE SINCRONIZAÇÃO
# Regras: set -Eeuo pipefail, validar SHAs, nenhuma simulação

set -Eeuo pipefail

REPO_DIR="$(pwd)"
TEST_NAME="sync-daemon-verify-$(date +%s)"
LOG_FILE="/tmp/sync-daemon-test-$TEST_NAME.log"

echo "╔════════════════════════════════════════════════════════════╗"
echo "║  TESTE DE SINCRONIZAÇÃO DO DAEMON                         ║"
echo "║  Regras: Sem simulação, validação de SHAs, auditoria       ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# FASE 1: PREPARAÇÃO
echo "=== FASE 1: PREPARAÇÃO ===" | tee -a "$LOG_FILE"
BEFORE_SHA=$(git rev-parse HEAD)
BEFORE_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)
echo "SHA antes: $BEFORE_SHA" | tee -a "$LOG_FILE"
echo "Hora: $BEFORE_TIME" | tee -a "$LOG_FILE"
echo "Branch: $(git rev-parse --abbrev-ref HEAD)" | tee -a "$LOG_FILE"

# Validar que estamos em main
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [[ "$CURRENT_BRANCH" != "main" ]]; then
    echo "❌ ERRO: Não estamos em main (estamos em $CURRENT_BRANCH)" | tee -a "$LOG_FILE"
    exit 1
fi

# Validar que working tree está limpa
if [[ -n "$(git status --porcelain)" ]]; then
    echo "❌ ERRO: Working tree sujo, não posso testar" | tee -a "$LOG_FILE"
    git status --porcelain | tee -a "$LOG_FILE"
    exit 1
fi

# FASE 2: DISPARO (criar commit de teste)
echo "" | tee -a "$LOG_FILE"
echo "=== FASE 2: DISPARO ===" | tee -a "$LOG_FILE"
git commit --allow-empty -m "test: sync daemon verification - $TEST_NAME" 2>&1 | tee -a "$LOG_FILE"

EXPECTED_SHA=$(git rev-parse HEAD)
echo "SHA esperado (origin/main): $EXPECTED_SHA" | tee -a "$LOG_FILE"

echo "Push para origin/main..." | tee -a "$LOG_FILE"
git push origin main 2>&1 | tee -a "$LOG_FILE" || {
    echo "❌ ERRO: git push falhou" | tee -a "$LOG_FILE"
    exit 1
}

PUSH_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)
echo "Push concluído em: $PUSH_TIME" | tee -a "$LOG_FILE"

# FASE 3: ESPERA (deixe daemon rodar)
echo "" | tee -a "$LOG_FILE"
echo "=== FASE 3: ESPERA ===" | tee -a "$LOG_FILE"
echo "Aguardando 4 minutos para cron */2 rodar 2 vezes..." | tee -a "$LOG_FILE"
echo "⚠️  NÃO FAÇA GIT FETCH/PULL/RESET/CHECKOUT!" | tee -a "$LOG_FILE"
echo "Começou em: $(date -u)" | tee -a "$LOG_FILE"

sleep 240

echo "Aguardo concluído em: $(date -u)" | tee -a "$LOG_FILE"

# FASE 4: OBSERVAÇÃO (verificar resultado NA VM via SSH)
echo "" | tee -a "$LOG_FILE"
echo "=== FASE 4: OBSERVAÇÃO ===" | tee -a "$LOG_FILE"

# Verificar SSH key
if [[ ! -f ~/.ssh/ssh-key-2026-07-04.key ]]; then
    echo "❌ ERRO: SSH key não encontrada em ~/.ssh/ssh-key-2026-07-04.key" | tee -a "$LOG_FILE"
    echo "INCONCLUSIVO: Não consegui verificar VM" | tee -a "$LOG_FILE"
    exit 1
fi

chmod 600 ~/.ssh/ssh-key-2026-07-04.key

# Verificar SHA na VM
echo "Verificando SHA na VM..." | tee -a "$LOG_FILE"
ACTUAL_SHA=$(ssh -i ~/.ssh/ssh-key-2026-07-04.key ubuntu@137.131.156.17 \
    "cd /home/ubuntu/site-shopvivaliz && git rev-parse HEAD" 2>&1) || {
    echo "❌ ERRO: Não consegui conectar à VM via SSH" | tee -a "$LOG_FILE"
    exit 1
}

echo "SHA na VM: $ACTUAL_SHA" | tee -a "$LOG_FILE"

# VALIDAÇÃO FINAL
echo "" | tee -a "$LOG_FILE"
echo "=== VALIDAÇÃO FINAL ===" | tee -a "$LOG_FILE"

if [[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]]; then
    echo "✅ COMPROVADO: SHA bate!" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
    echo "Resumo:" | tee -a "$LOG_FILE"
    echo "  Commit: $EXPECTED_SHA" | tee -a "$LOG_FILE"
    echo "  Tempo: $PUSH_TIME até $(date -u)" | tee -a "$LOG_FILE"
    echo "  Status: SINCRONIZADO" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
    exit 0
else
    echo "❌ FALHOU: SHA não bate!" | tee -a "$LOG_FILE"
    echo "  Esperado: $EXPECTED_SHA" | tee -a "$LOG_FILE"
    echo "  Encontrado: $ACTUAL_SHA" | tee -a "$LOG_FILE"
    echo "" | tee -a "$LOG_FILE"
    exit 1
fi
