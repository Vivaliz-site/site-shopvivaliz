#!/bin/bash
# Monitor Olist token renewal in real-time

echo "╔════════════════════════════════════════════════════════════╗"
echo "║          OLIST TOKEN RENEWAL MONITOR                      ║"
echo "║          (Verificando a cada 30 segundos)                 ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

# Função para verificar token em .env
check_token() {
    if [ -f .env ]; then
        TOKEN=$(grep "^OLIST_REFRESH_TOKEN=" .env | cut -d= -f2)
        if [ ! -z "$TOKEN" ]; then
            # Extrair exp do JWT (payload é a segunda parte)
            PAYLOAD=$(echo "$TOKEN" | cut -d. -f2)
            # Adicionar padding se necessário
            PADDED="${PAYLOAD}=="
            EXP=$(echo "$PADDED" | base64 -d 2>/dev/null | grep -o '"exp":[0-9]*' | cut -d: -f2)

            if [ ! -z "$EXP" ]; then
                NOW=$(date +%s)
                EXPIRES_IN=$((EXP - NOW))

                if [ $EXPIRES_IN -gt 0 ]; then
                    HOURS=$((EXPIRES_IN / 3600))
                    echo "✅ Token VÁLIDO"
                    echo "   Expira em: $HOURS horas ($EXP)"
                else
                    echo "🔴 Token EXPIRADO (há $((EXPIRES_IN * -1)) segundos)"
                fi
            fi
        fi
    fi
}

# Função para checar logs
check_logs() {
    echo ""
    echo "Últimas execuções do workflow:"
    if [ -f logs/olist-live-sync-response.json ]; then
        echo "  $(tail -1 logs/olist-live-sync-response.json | head -c 100)"
    fi

    if [ -f logs/olist-sync.log ]; then
        echo "  Últimas linhas do olist-sync.log:"
        tail -3 logs/olist-sync.log | sed 's/^/    /'
    fi
}

# Função para checar email
check_email() {
    echo ""
    echo "Status Email SMTP:"
    if grep -q "SMTP_HOST=smtp.gmail.com" .env; then
        echo "  ✅ Gmail configurado (shopvivaliz@gmail.com)"
    fi

    if [ -f logs/email-*.log ]; then
        echo "  Últimas tentativas:"
        tail -2 logs/email-*.log | sed 's/^/    /'
    fi
}

# Loop de monitoramento
COUNTER=0
while true; do
    COUNTER=$((COUNTER + 1))

    clear
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║          OLIST TOKEN RENEWAL MONITOR - #$COUNTER                    ║"
    echo "║          $(date '+%Y-%m-%d %H:%M:%S')                            ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""

    echo "📊 STATUS ATUAL:"
    check_token

    echo ""
    echo "📋 LOGS:"
    check_logs

    echo ""
    echo "📧 EMAIL:"
    check_email

    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Próxima verificação em 30 segundos... (Ctrl+C para sair)"
    echo "Workflow executa a cada 2 minutos (teste)"

    sleep 30
done
