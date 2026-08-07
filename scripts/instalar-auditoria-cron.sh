#!/bin/bash
# Instalar auditoria 24/7 em cron sem substituir entradas existentes.

set -euo pipefail

echo "📋 Instalando Auditoria 24/7 em Cron..."

# Criar diretórios de logs
ROOT="${ROOT:-/home/ubuntu/shopvivaliz-deploy/current}"
mkdir -p "$ROOT/logs/reports"

# Tornar script executável
chmod +x "$ROOT/scripts/auditoria-24-7.py"

# Adicionar cron job (executar a cada 30 minutos) preservando todo o crontab.
MARKER="# shopvivaliz-auditoria-24-7"
CRON_JOB="*/30 * * * * cd $ROOT && python3 scripts/auditoria-24-7.py >> logs/auditoria-cron.log 2>&1 $MARKER"
CURRENT_CRONTAB=""
if CURRENT_CRONTAB="$(crontab -l 2>/dev/null)"; then
    :
else
    CRONTAB_STATUS=$?
    if [[ "$CRONTAB_STATUS" -ne 1 ]]; then
        echo "❌ Não foi possível ler o crontab atual (status $CRONTAB_STATUS)" >&2
        exit "$CRONTAB_STATUS"
    fi
fi

if printf '%s\n' "$CURRENT_CRONTAB" | grep -Fq "$MARKER"; then
    echo "⚠️ Cron job já existe"
else
    {
        if [[ -n "$CURRENT_CRONTAB" ]]; then
            printf '%s\n' "$CURRENT_CRONTAB"
        fi
        printf '%s\n' "$CRON_JOB"
    } | crontab -
    echo "✅ Cron job adicionado para executar a cada 30 minutos sem remover entradas existentes"
fi

# Verificar se está funcionando
echo ""
echo "📊 Próximas execuções agendadas:"
crontab -l | grep -F "$MARKER"

echo ""
echo "✅ Auditoria 24/7 instalada com sucesso!"
echo ""
echo "📝 Logs em: $ROOT/logs/auditoria-cron.log"
echo "📊 Relatórios em: $ROOT/logs/reports/"
