#!/bin/bash
# Instalar auditoria 24/7 em cron

echo "📋 Instalando Auditoria 24/7 em Cron..."

# Criar diretórios de logs
mkdir -p /home/ubuntu/site-shopvivaliz/logs/reports

# Tornar script executável
chmod +x /home/ubuntu/site-shopvivaliz/scripts/auditoria-24-7.py

# Adicionar cron job (executar a cada 30 minutos)
# Verificar se já existe antes de adicionar
CRON_JOB="*/30 * * * * cd /home/ubuntu/site-shopvivaliz && python3 scripts/auditoria-24-7.py >> logs/auditoria-cron.log 2>&1"

if ! crontab -l 2>/dev/null | grep -q "auditoria-24-7.py"; then
    echo "$CRON_JOB" | crontab -
    echo "✅ Cron job adicionado para executar a cada 30 minutos"
else
    echo "⚠️ Cron job já existe"
fi

# Verificar se está funcionando
echo ""
echo "📊 Próximas execuções agendadas:"
crontab -l | grep auditoria-24-7

echo ""
echo "✅ Auditoria 24/7 instalada com sucesso!"
echo ""
echo "📝 Logs em: /home/ubuntu/site-shopvivaliz/logs/auditoria-24-7.log"
echo "📊 Relatórios em: /home/ubuntu/site-shopvivaliz/logs/reports/"
