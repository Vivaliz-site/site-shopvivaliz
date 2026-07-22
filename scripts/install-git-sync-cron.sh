#!/bin/bash
# Instalar git-auto-sync como cron job na VM Oracle
# Executar: bash /home/ubuntu/site-shopvivaliz/scripts/install-git-sync-cron.sh

set -Eeuo pipefail

REPO_DIR="/home/ubuntu/site-shopvivaliz"
SCRIPT_PATH="$REPO_DIR/git-auto-sync.py"
LOG_DIR="/var/log/shopvivaliz"
CRON_JOB="*/30 * * * * /usr/bin/python3 $SCRIPT_PATH >> $LOG_DIR/cron.log 2>&1"

echo "🔧 INSTALANDO GIT AUTO-SYNC CRON JOB"
echo "======================================"
echo ""

# 1. Verificar se script existe
if [ ! -f "$SCRIPT_PATH" ]; then
    echo "❌ Script não encontrado: $SCRIPT_PATH"
    exit 1
fi
echo "✅ Script encontrado"

# 2. Tornar executável
chmod +x "$SCRIPT_PATH"
echo "✅ Script marcado como executável"

# 3. Criar diretório de logs
mkdir -p "$LOG_DIR"
echo "✅ Diretório de logs criado"

# 4. Verificar Python
if ! command -v python3 &> /dev/null; then
    echo "❌ Python3 não encontrado"
    exit 1
fi
echo "✅ Python3 encontrado: $(python3 --version)"

# 5. Testar script
echo ""
echo "🧪 Testando script..."
python3 "$SCRIPT_PATH"
TEST_RESULT=$?
if [ $TEST_RESULT -eq 0 ]; then
    echo "✅ Teste passou"
else
    echo "⚠️  Teste retornou código: $TEST_RESULT (pode ser normal)"
fi

# 6. Instalar cron job
echo ""
echo "📋 Instalando cron job (a cada 30 minutos)..."

# Verificar se job já existe
CURRENT_CRONTAB="$(crontab -l 2>/dev/null || printf '')"
if grep -q "git-auto-sync.py" <<<"$CURRENT_CRONTAB"; then
    echo "⚠️  Cron job já existe, removendo versão antiga..."
    (grep -v "git-auto-sync.py" <<<"$CURRENT_CRONTAB" || printf ''; printf '%s\n' "$CRON_JOB") | crontab -
else
    (printf '%s\n' "$CURRENT_CRONTAB"; printf '%s\n' "$CRON_JOB") | crontab -
fi

echo "✅ Cron job instalado"

# 7. Verificar instalação
echo ""
echo "📋 Cron jobs instalados:"
crontab -l | grep -E "git-auto-sync|auto-sync|*/30" || echo "Nenhum job encontrado"

# 8. Próximas execuções
echo ""
echo "⏰ Próximas execuções:"
echo "   - A cada 30 minutos"
echo "   - Logs: $LOG_DIR/git-auto-sync-*.log"
echo "   - Cron log: $LOG_DIR/cron.log"

echo ""
echo "======================================"
echo "✅ INSTALAÇÃO CONCLUÍDA"
echo ""
echo "Manual de sincronização:"
echo "  Testar: python3 $SCRIPT_PATH"
echo "  Ver logs: tail -f $LOG_DIR/git-auto-sync-*.log"
echo "  Remover cron: crontab -e (e delete a linha)"
