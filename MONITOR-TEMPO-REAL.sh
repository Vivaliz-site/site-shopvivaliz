#!/bin/bash
# MONITOR EM TEMPO REAL - Execute isso enquanto faz a compra
# Uso: bash MONITOR-TEMPO-REAL.sh

clear
echo "🔍 MONITOR TEMPO REAL - ShopVivaliz"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "Este script monitora:"
echo "  ✓ Email chegando"
echo "  ✓ Olist sync acontecendo"
echo "  ✓ Logs de erro"
echo "  ✓ Status do site"
echo ""
echo "Comece AGORA e deixe rodando enquanto faz a compra"
echo ""
echo "Press CTRL+C para parar"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Cores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Contadores
EMAIL_COUNT=0
OLIST_COUNT=0
ERRORS_COUNT=0

# Loop de monitoramento
while true; do
  clear

  # Header
  echo -e "${BLUE}════════════════════════════════════════════${NC}"
  echo -e "${BLUE}🕐 TEMPO REAL: $(date '+%H:%M:%S')${NC}"
  echo -e "${BLUE}════════════════════════════════════════════${NC}"
  echo ""

  # 1. Verificar site online
  echo -e "${YELLOW}1️⃣  SITE:${NC}"
  if curl -s -I https://shopvivaliz.com.br/ | grep -q "200\|301"; then
    echo -e "   ${GREEN}✅ Online${NC}"
  else
    echo -e "   ${RED}❌ Offline${NC}"
  fi
  echo ""

  # 2. Último email
  echo -e "${YELLOW}2️⃣  EMAIL:${NC}"
  if [ -f logs/email-*.log ]; then
    EMAIL_TIME=$(tail -1 logs/email-*.log 2>/dev/null | head -c 19)
    if [ ! -z "$EMAIL_TIME" ]; then
      echo -e "   ${GREEN}✅ Último: $EMAIL_TIME${NC}"
      EMAIL_COUNT=$((EMAIL_COUNT + 1))
    fi
  else
    echo -e "   ${YELLOW}⏳ Aguardando email...${NC}"
  fi
  echo ""

  # 3. Sincronização Olist
  echo -e "${YELLOW}3️⃣  OLIST SYNC:${NC}"
  if [ -f logs/olist-sync.log ]; then
    OLIST_TIME=$(tail -1 logs/olist-sync.log 2>/dev/null | head -c 19)
    if [ ! -z "$OLIST_TIME" ]; then
      echo -e "   ${GREEN}✅ Último sync: $OLIST_TIME${NC}"
      OLIST_COUNT=$((OLIST_COUNT + 1))
    fi
  fi
  echo ""

  # 4. Erros nos logs
  echo -e "${YELLOW}4️⃣  ERROS:${NC}"
  ERROR_COUNT=$(grep -i "error\|fail" logs/*.log 2>/dev/null | wc -l)
  if [ $ERROR_COUNT -eq 0 ]; then
    echo -e "   ${GREEN}✅ Nenhum erro${NC}"
  else
    echo -e "   ${RED}❌ $ERROR_COUNT erros encontrados${NC}"
    echo -e "   ${RED}   (veja abaixo)${NC}"
  fi
  echo ""

  # 5. Git sync
  echo -e "${YELLOW}5️⃣  DAEMON SYNC:${NC}"
  LAST_COMMIT=$(git log -1 --format="%h - %ai" 2>/dev/null)
  if [ ! -z "$LAST_COMMIT" ]; then
    echo -e "   ${GREEN}✅ $LAST_COMMIT${NC}"
  fi
  echo ""

  # 6. Estatísticas
  echo -e "${BLUE}════════════════════════════════════════════${NC}"
  echo -e "${YELLOW}📊 ESTATÍSTICAS:${NC}"
  echo "   Emails detectados: $EMAIL_COUNT"
  echo "   Syncs Olist: $OLIST_COUNT"
  echo "   Total erros: $ERROR_COUNT"
  echo ""

  # 7. Últimas linhas de log (status)
  echo -e "${YELLOW}📋 ÚLTIMOS EVENTOS:${NC}"
  echo "   (Email)"
  tail -1 logs/email-*.log 2>/dev/null | tail -c 80 || echo "   (aguardando...)"
  echo ""
  echo "   (Olist)"
  tail -1 logs/olist-sync.log 2>/dev/null | tail -c 80 || echo "   (aguardando...)"
  echo ""

  # 8. Instruções
  echo -e "${BLUE}════════════════════════════════════════════${NC}"
  echo -e "${YELLOW}⚡ O QUE FAZER AGORA:${NC}"
  echo "   1. Abra: https://shopvivaliz.com.br/"
  echo "   2. Faça a compra com seus dados"
  echo "   3. Gere o boleto"
  echo "   4. Este monitor atualizará a cada 5 segundos"
  echo "   5. Quando terminar, abra seu email"
  echo ""
  echo -e "${BLUE}════════════════════════════════════════════${NC}"

  # Aguardar 5 segundos
  sleep 5
done
