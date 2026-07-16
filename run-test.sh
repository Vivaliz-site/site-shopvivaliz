#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${BLUE}🧪 TESTE DE VALIDAÇÃO DA HOMEPAGE${NC}"
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"

# 1. Iniciar servidor PHP em background
echo -e "\n${YELLOW}[1/3] Iniciando servidor PHP...${NC}"
cd /c/site-shopvivaliz
php -S localhost:8080 > /tmp/php-server.log 2>&1 &
PHP_PID=$!
echo "      PID: $PHP_PID"

# Aguardar servidor iniciar
echo -e "${YELLOW}      Aguardando servidor ficar pronto...${NC}"
sleep 3

# Verificar se servidor está respondendo
if curl -s http://localhost:8080 > /dev/null 2>&1; then
    echo -e "${GREEN}      ✅ Servidor PHP rodando em http://localhost:8080${NC}"
else
    echo -e "${RED}      ❌ Servidor PHP não respondeu${NC}"
    kill $PHP_PID 2>/dev/null
    exit 1
fi

# 2. Rodar teste Playwright
echo -e "\n${YELLOW}[2/3] Executando teste visual com Playwright...${NC}"
mkdir -p test-results
npx playwright test --config=playwright.config.js test-homepage-visual.js --reporter=html 2>&1 || \
node test-homepage-visual.js

# 3. Limpar
echo -e "\n${YELLOW}[3/3] Limpando...${NC}"
kill $PHP_PID 2>/dev/null || true
echo -e "${GREEN}      ✅ Servidor PHP finalizado${NC}"

# Mostrar resultado
echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
if [ -f test-results/homepage-validation.png ]; then
    echo -e "${GREEN}✅ TESTES COMPLETOS${NC}"
    echo -e "   Screenshot: test-results/homepage-validation.png"
else
    echo -e "${YELLOW}⚠️  Teste executado, verifique os resultados acima${NC}"
fi
echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
