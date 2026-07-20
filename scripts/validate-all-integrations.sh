#!/bin/bash
# ShopVivaliz - Validar Todas as Integrações
# Execução: bash scripts/validate-all-integrations.sh

set -e

echo "════════════════════════════════════════════════════════════"
echo "VALIDAÇÃO COMPLETA - SHOP VIVALIZ"
echo "════════════════════════════════════════════════════════════"
echo ""

# Cores
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

passed=0
failed=0

# Função para resultado
test_result() {
  if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ $1${NC}"
    ((passed++))
  else
    echo -e "${RED}❌ $1${NC}"
    ((failed++))
  fi
}

# 1. Verificar PHP Lint
echo "1. PHP LINT"
echo "==========="
php -l checkout.php > /dev/null 2>&1
test_result "checkout.php válido"

php -l api/webhook-mercadopago.php > /dev/null 2>&1
test_result "webhook-mercadopago.php válido"

php -l api/process-payment.php > /dev/null 2>&1
test_result "process-payment.php válido"

# 2. Verificar Conflitos Git
echo ""
echo "2. GIT CONFLICTS"
echo "================"
conflict_count=$(grep -r "^<<<<<<<" . --include="*.php" --include="*.py" 2>/dev/null | wc -l)
if [ "$conflict_count" -eq 0 ]; then
  echo -e "${GREEN}✅ Nenhum marcador de conflito${NC}"
  ((passed++))
else
  echo -e "${RED}❌ $conflict_count marcadores de conflito encontrados${NC}"
  ((failed++))
fi

# 3. Verificar Mercado Pago
echo ""
echo "3. MERCADO PAGO"
echo "==============="
if [ ! -z "$MERCADOPAGO_ACCESS_TOKEN" ]; then
  echo -e "${GREEN}✅ MERCADOPAGO_ACCESS_TOKEN configurada${NC}"
  ((passed++))
else
  echo -e "${YELLOW}⚠️  MERCADOPAGO_ACCESS_TOKEN não configurada${NC}"
  ((failed++))
fi

if [ ! -z "$MERCADOPAGO_WEBHOOK_SECRET" ]; then
  echo -e "${GREEN}✅ MERCADOPAGO_WEBHOOK_SECRET configurada${NC}"
  ((passed++))
else
  echo -e "${YELLOW}⚠️  MERCADOPAGO_WEBHOOK_SECRET não configurada${NC}"
  ((failed++))
fi

# 4. Verificar Olist/Tiny
echo ""
echo "4. OLIST/TINY ERP"
echo "================="
if [ ! -z "$OLIST_ACCESS_TOKEN" ]; then
  echo -e "${GREEN}✅ OLIST_ACCESS_TOKEN configurada${NC}"
  ((passed++))
else
  echo -e "${YELLOW}⚠️  OLIST_ACCESS_TOKEN não configurada${NC}"
  ((failed++))
fi

# 5. Verificar Site em Produção
echo ""
echo "5. SITE EM PRODUÇÃO"
echo "==================="
home_status=$(curl -s -o /dev/null -w "%{http_code}" https://shopvivaliz.com.br/ 2>/dev/null || echo "000")
if [ "$home_status" = "200" ]; then
  echo -e "${GREEN}✅ Home Page respondendo (HTTP $home_status)${NC}"
  ((passed++))
else
  echo -e "${RED}❌ Home Page com erro (HTTP $home_status)${NC}"
  ((failed++))
fi

checkout_status=$(curl -s -o /dev/null -w "%{http_code}" https://shopvivaliz.com.br/checkout 2>/dev/null || echo "000")
if [ "$checkout_status" = "200" ]; then
  echo -e "${GREEN}✅ Checkout respondendo (HTTP $checkout_status)${NC}"
  ((passed++))
else
  echo -e "${RED}❌ Checkout com erro (HTTP $checkout_status)${NC}"
  ((failed++))
fi

webhook_status=$(curl -s -X POST https://shopvivaliz.com.br/api/webhook-mercadopago.php \
  -H "Content-Type: application/json" \
  -o /dev/null -w "%{http_code}" 2>/dev/null || echo "000")
if [ "$webhook_status" = "401" ]; then
  echo -e "${GREEN}✅ Webhook rejeita sem assinatura (HTTP $webhook_status)${NC}"
  ((passed++))
else
  echo -e "${YELLOW}⚠️  Webhook status inesperado (HTTP $webhook_status)${NC}"
  ((failed++))
fi

# Resumo Final
echo ""
echo "════════════════════════════════════════════════════════════"
echo "RESUMO"
echo "════════════════════════════════════════════════════════════"
total=$((passed + failed))
percentage=$((passed * 100 / total))
echo "Testes passados: $passed/$total ($percentage%)"
echo ""

if [ "$failed" -eq 0 ]; then
  echo -e "${GREEN}✅ TODOS OS TESTES PASSARAM${NC}"
  exit 0
else
  echo -e "${RED}❌ $failed TESTES FALHARAM${NC}"
  exit 1
fi
