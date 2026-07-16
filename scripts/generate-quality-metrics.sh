#!/bin/bash
# Script para gerar métricas de qualidade da integração Mercado Pago
# Executado na VM Oracle após sincronização de secrets

set -e

REPO_DIR="/home/ubuntu/site-shopvivaliz"
LOG_FILE="/var/log/shopvivaliz/quality-metrics-$(date +%Y%m%d-%H%M%S).log"

echo "========================================" >> "$LOG_FILE"
echo "GERAÇÃO DE MÉTRICAS DE QUALIDADE" >> "$LOG_FILE"
echo "Data: $(date)" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"

cd "$REPO_DIR"

# 1. Verificar secrets configurados
echo "" >> "$LOG_FILE"
echo "1. Verificando secrets..." >> "$LOG_FILE"
if grep -q "MERCADOPAGO_ACCESS_TOKEN" config/runtime-secrets.php; then
    echo "✅ ACCESS_TOKEN configurado" >> "$LOG_FILE"
else
    echo "❌ ACCESS_TOKEN faltando" >> "$LOG_FILE"
    exit 1
fi

if grep -q "MERCADOPAGO_PUBLIC_KEY" config/runtime-secrets.php; then
    echo "✅ PUBLIC_KEY configurado" >> "$LOG_FILE"
else
    echo "❌ PUBLIC_KEY faltando" >> "$LOG_FILE"
    exit 1
fi

if grep -q "MERCADOPAGO_WEBHOOK_SECRET" config/runtime-secrets.php; then
    echo "✅ WEBHOOK_SECRET configurado" >> "$LOG_FILE"
else
    echo "❌ WEBHOOK_SECRET faltando" >> "$LOG_FILE"
    exit 1
fi

# 2. Executar testes de validação
echo "" >> "$LOG_FILE"
echo "2. Executando testes..." >> "$LOG_FILE"
bash scripts/validate-all-integrations.sh >> "$LOG_FILE" 2>&1 || true

# 3. Verificar MercadoPago.js V2 no checkout
echo "" >> "$LOG_FILE"
echo "3. Verificando MercadoPago.js V2..." >> "$LOG_FILE"
if grep -q "sdk.mercadopago.com/js/v2" checkout.php; then
    echo "✅ MercadoPago.js V2 presente no checkout" >> "$LOG_FILE"
else
    echo "❌ MercadoPago.js V2 não encontrado" >> "$LOG_FILE"
fi

# 4. Verificar Device ID
echo "" >> "$LOG_FILE"
echo "4. Verificando Device ID..." >> "$LOG_FILE"
if grep -q "deviceId()" checkout.php; then
    echo "✅ Device ID inicializado" >> "$LOG_FILE"
else
    echo "❌ Device ID não inicializado" >> "$LOG_FILE"
fi

# 5. Testar endpoint de webhook
echo "" >> "$LOG_FILE"
echo "5. Testando webhook..." >> "$LOG_FILE"
WEBHOOK_RESPONSE=$(curl -s -X POST https://dev.shopvivaliz.com.br/api/webhook-mercadopago.php \
  -H "Content-Type: application/json" \
  -H "X-Signature: invalid" \
  -H "X-Request-ID: test" \
  -w "\n%{http_code}" \
  -d '{"data":{"id":"123"}}' 2>/dev/null | tail -1)

if [ "$WEBHOOK_RESPONSE" = "401" ]; then
    echo "✅ Webhook rejeita requisições sem assinatura válida (HTTP 401)" >> "$LOG_FILE"
else
    echo "⚠️  Webhook retornou HTTP $WEBHOOK_RESPONSE (esperado 401)" >> "$LOG_FILE"
fi

# 6. Verificar site respondendo
echo "" >> "$LOG_FILE"
echo "6. Verificando site..." >> "$LOG_FILE"
HOME_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://dev.shopvivaliz.com.br/)
CHECKOUT_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://dev.shopvivaliz.com.br/checkout)

if [ "$HOME_CODE" = "200" ]; then
    echo "✅ Home respondendo (HTTP 200)" >> "$LOG_FILE"
else
    echo "❌ Home com erro (HTTP $HOME_CODE)" >> "$LOG_FILE"
fi

if [ "$CHECKOUT_CODE" = "200" ]; then
    echo "✅ Checkout respondendo (HTTP 200)" >> "$LOG_FILE"
else
    echo "❌ Checkout com erro (HTTP $CHECKOUT_CODE)" >> "$LOG_FILE"
fi

# 7. Gerar relatório
echo "" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"
echo "MÉTRICAS GERADAS COM SUCESSO" >> "$LOG_FILE"
echo "Log: $LOG_FILE" >> "$LOG_FILE"
echo "========================================" >> "$LOG_FILE"

cat "$LOG_FILE"
