#!/bin/bash
#
# TESTE DO AUTO-SYNC SHOPVIVALIZ
# Script para validar que o auto-sync funciona corretamente
#
# Uso:
#   bash test-auto-sync.sh (local)
#   ou na VM: sudo bash /path/to/test-auto-sync.sh
#

set -Eeuo pipefail

echo "════════════════════════════════════════════════════════════"
echo "🧪 TESTE DE AUTO-SYNC SHOPVIVALIZ"
echo "════════════════════════════════════════════════════════════"
echo ""

REPO_DIR="/home/ubuntu/site-shopvivaliz"
LOG_FILE="/var/log/shopvivaliz-auto-sync.log"

# ============================================================================
# TESTE 1: Service Status
# ============================================================================

echo "[TESTE 1] Service e Timer Status"
echo "────────────────────────────────────────────────────────────"

echo ""
echo "Service status:"
sudo systemctl status shopvivaliz-auto-sync.service --no-pager -l | head -10

echo ""
echo "Timer status:"
sudo systemctl status shopvivaliz-auto-sync.timer --no-pager -l | head -10

echo ""
echo "Próximas execuções:"
sudo systemctl list-timers shopvivaliz-auto-sync.timer --no-pager

# ============================================================================
# TESTE 2: Execução Manual
# ============================================================================

echo ""
echo "[TESTE 2] Executar sincronização manual"
echo "────────────────────────────────────────────────────────────"

echo ""
echo "Executando: sudo /usr/local/bin/shopvivaliz-auto-sync.sh"

if sudo /usr/local/bin/shopvivaliz-auto-sync.sh; then
    echo "✅ Execução bem-sucedida"
else
    EXIT_CODE=$?
    echo "❌ Execução falhou com código: $EXIT_CODE"
fi

# ============================================================================
# TESTE 3: Git Status
# ============================================================================

echo ""
echo "[TESTE 3] Status do Repositório Git"
echo "────────────────────────────────────────────────────────────"

cd "$REPO_DIR"

echo ""
echo "Branch:"
git branch -v | head -2

echo ""
echo "Comparação HEAD vs origin/main:"
HEAD=$(git rev-parse HEAD)
ORIGIN=$(git rev-parse origin/main)

if [[ "$HEAD" == "$ORIGIN" ]]; then
    echo "✅ HEAD == origin/main (sincronizado)"
else
    echo "❌ HEAD != origin/main (não sincronizado)"
    echo "  HEAD:        $HEAD"
    echo "  origin/main: $ORIGIN"
fi

echo ""
echo "Arquivos alterados:"
git status --porcelain | head -10 || echo "(nenhum)"

# ============================================================================
# TESTE 4: Arquivos de Runtime
# ============================================================================

echo ""
echo "[TESTE 4] Arquivos de Runtime"
echo "────────────────────────────────────────────────────────────"

for file in .agent-heartbeats/claude.heartbeat .agent-heartbeats/gemini.heartbeat .agent-heartbeats/gpt.heartbeat tasks-queue.json; do
    if [[ -f "$REPO_DIR/$file" ]]; then
        SIZE=$(stat -f%z "$REPO_DIR/$file" 2>/dev/null || stat -c%s "$REPO_DIR/$file" 2>/dev/null || echo "?")
        echo "✓ $file ($SIZE bytes)"
    else
        echo "✗ $file (não encontrado)"
    fi
done

# ============================================================================
# TESTE 5: Logs
# ============================================================================

echo ""
echo "[TESTE 5] Últimos Logs"
echo "────────────────────────────────────────────────────────────"

if [[ -f "$LOG_FILE" ]]; then
    echo ""
    echo "Últimas 30 linhas de $LOG_FILE:"
    tail -30 "$LOG_FILE"
else
    echo "❌ Log file não encontrado: $LOG_FILE"
fi

# ============================================================================
# TESTE 6: Simular Mudança em Origin
# ============================================================================

echo ""
echo "[TESTE 6] Cenários de Teste"
echo "────────────────────────────────────────────────────────────"

echo ""
echo "CENÁRIO A: Arquivo de runtime modificado (deve ignorar)"
echo "─────────────────────────────────────────────────────────────"

# Modificar arquivo de runtime
echo "timestamp: $(date)" >> "$REPO_DIR/.agent-heartbeats/claude.heartbeat"
echo "✓ Modificado: .agent-heartbeats/claude.heartbeat"

echo ""
echo "Executando sync..."
if sudo /usr/local/bin/shopvivaliz-auto-sync.sh; then
    echo "✅ Sync ignorou arquivo de runtime e prosseguiu"
else
    echo "❌ Sync falhou com arquivo de runtime"
fi

echo ""
echo "CENÁRIO B: Arquivo real modificado (deve bloquear)"
echo "─────────────────────────────────────────────────────────────"

# Criar arquivo fictício
touch "$REPO_DIR/test-blocking-file.php"
echo "<?php // test" > "$REPO_DIR/test-blocking-file.php"
git add "$REPO_DIR/test-blocking-file.php"
echo "✓ Criado: test-blocking-file.php"

echo ""
echo "Executando sync..."
if sudo /usr/local/bin/shopvivaliz-auto-sync.sh; then
    echo "❌ Sync deveria ter bloqueado, mas não bloqueou"
else
    echo "✅ Sync corretamente bloqueou arquivo real"
fi

# Limpar
git reset --hard HEAD
rm -f "$REPO_DIR/test-blocking-file.php"
echo "✓ Limpeza concluída"

# ============================================================================
# RESUMO
# ============================================================================

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✅ TESTES CONCLUÍDOS"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "Se todos os testes passaram (❌ = 0):"
echo "  ✓ Service está ativo e rodando"
echo "  ✓ Timer está configurado"
echo "  ✓ Sincronização funciona corretamente"
echo "  ✓ Arquivos de runtime são ignorados"
echo "  ✓ Arquivos reais bloqueiam o sync"
echo ""
echo "O auto-sync está 100% operacional!"
echo ""
