#!/bin/bash
# Script para ativar envio de emails em produção (VM Oracle)
# Executar na VM Oracle após sincronização de secrets

set -e

REPO_DIR="/home/ubuntu/site-shopvivaliz"
LOG_FILE="/var/log/shopvivaliz/email-setup-$(date +%Y%m%d-%H%M%S).log"

echo "🔧 CONFIGURANDO SISTEMA DE EMAILS"
echo "=================================="
echo ""

# Criar diretório de logs se não existir
mkdir -p /var/log/shopvivaliz

# 1. Verificar PHP
echo "1️⃣  Verificando PHP..."
PHP_BIN=$(which php)
if [ -z "$PHP_BIN" ]; then
    echo "❌ PHP não encontrado" >> "$LOG_FILE"
    exit 1
fi
echo "✅ PHP encontrado: $PHP_BIN" | tee -a "$LOG_FILE"

# 2. Verificar configuração SMTP no .env
echo ""
echo "2️⃣  Verificando credenciais SMTP..."
if grep -q "SMTP_HOST\|SMTP_USER\|SMTP_PASS" "$REPO_DIR/.env"; then
    echo "✅ Credenciais SMTP presentes" | tee -a "$LOG_FILE"
    grep "^SMTP_\|^EMAIL_" "$REPO_DIR/.env" >> "$LOG_FILE"
else
    echo "⚠️  Credenciais SMTP não encontradas em .env" | tee -a "$LOG_FILE"
fi

# 3. Verificar runtime-secrets.php
echo ""
echo "3️⃣  Verificando runtime-secrets.php..."
if [ -f "$REPO_DIR/config/runtime-secrets.php" ]; then
    echo "✅ runtime-secrets.php presente" | tee -a "$LOG_FILE"
    if grep -q "SMTP_HOST" "$REPO_DIR/config/runtime-secrets.php"; then
        echo "✅ SMTP_HOST configurada em runtime-secrets" | tee -a "$LOG_FILE"
    fi
else
    echo "⚠️  runtime-secrets.php não encontrado" | tee -a "$LOG_FILE"
fi

# 4. Testar envio de email de teste
echo ""
echo "4️⃣  Testando envio de email..."
TEST_EMAIL=$(grep "^ADMIN_EMAIL\|^EMAIL_TO\|^EMAIL_USER" "$REPO_DIR/config/runtime-secrets.php" 2>/dev/null | head -1 | cut -d'=' -f2 | tr -d "'\"" || echo "admin@shopvivaliz.com.br")

$PHP_BIN "$REPO_DIR/api/send-order-confirmation-email.php" \
  "TEST-EMAIL-$(date +%s)" \
  "$TEST_EMAIL" \
  "Teste ShopVivaliz" \
  "1.00" \
  "Produto de Teste" >> "$LOG_FILE" 2>&1 || true

echo "✅ Teste de email executado (ver log)" | tee -a "$LOG_FILE"

# 5. Verificar hooks de email em scripts de pedido
echo ""
echo "5️⃣  Verificando hooks de email em api/orders/..."
if grep -r "send-order-confirmation-email\|mail(" "$REPO_DIR/api/orders/" 2>/dev/null; then
    echo "✅ Hooks de email encontrados" | tee -a "$LOG_FILE"
else
    echo "⚠️  Nenhum hook de email encontrado em api/orders/" | tee -a "$LOG_FILE"
    echo "   Você precisa adicionar chamada para send-order-confirmation-email.php" | tee -a "$LOG_FILE"
fi

# 6. Criar cron job para limpeza de logs de email
echo ""
echo "6️⃣  Configurando limpeza de logs..."
(crontab -l 2>/dev/null | grep -v "shopvivaliz.*email"; echo "0 0 * * 0 find /var/log/shopvivaliz -name 'email-setup-*.log' -mtime +30 -delete") | crontab -

echo ""
echo "=================================="
echo "✅ CONFIGURAÇÃO DE EMAILS CONCLUÍDA"
echo "=================================="
echo ""
echo "📋 Log salvo em: $LOG_FILE"
echo ""
echo "📧 Próximos passos:"
echo "   1. Verificar que SMTP está configurado em config/runtime-secrets.php"
echo "   2. Adicionar chamada para send-order-confirmation-email.php após criar pedido"
echo "   3. Testar envio de email criando um pedido teste"
echo ""
