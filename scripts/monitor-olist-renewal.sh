#!/bin/bash
# Monitor Olist token renewal in real-time

set -u

shopt -s nullglob

check_token() {
    if [ -f .env ]; then
        TOKEN=$(grep "^TINY_REFRESH_TOKEN=" .env | cut -d= -f2)
        if [ -n "$TOKEN" ]; then
            PAYLOAD=$(echo "$TOKEN" | cut -d. -f2)
            PADDED="${PAYLOAD}=="
            EXP=$(echo "$PADDED" | base64 -d 2>/dev/null | grep -o '"exp":[0-9]*' | cut -d: -f2)

            if [ -n "$EXP" ]; then
                NOW=$(date +%s)
                EXPIRES_IN=$((EXP - NOW))

                if [ "$EXPIRES_IN" -gt 0 ]; then
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

check_logs() {
    echo ""
    echo "Últimas execuções do workflow:"
    if [ -f logs/olist-live-sync-response.json ]; then
        printf '  %s\n' "$(tail -n 1 logs/olist-live-sync-response.json | head -c 100)"
    fi

    if [ -f logs/olist-sync.log ]; then
        echo "  Últimas linhas do olist-sync.log:"
        tail -n 3 logs/olist-sync.log | sed 's/^/    /'
    fi
}

check_email() {
    local email_logs=(logs/email-*.log)

    echo ""
    echo "Status Email SMTP:"
    if grep -q "SMTP_HOST=smtp.gmail.com" .env 2>/dev/null; then
        echo "  ✅ Gmail configurado (shopvivaliz@gmail.com)"
    fi

    if (( ${#email_logs[@]} > 0 )); then
        echo "  Últimas tentativas:"
        tail -n 2 "${email_logs[@]}" | sed 's/^/    /'
    fi
}

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
