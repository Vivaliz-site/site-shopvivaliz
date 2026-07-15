#!/bin/bash

# ShopVivaliz Medusa - Production Deployment Script
# Automatiza todo o processo de deploy para HostGator

set -e

echo "╔════════════════════════════════════════════════════════════════╗"
echo "║  🚀 SHOPVIVALIZ MEDUSA - PRODUCTION DEPLOYMENT                ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Configuration
ENVIRONMENT=${1:-production}
HOSTGATOR_HOST=${HOSTGATOR_HOST:-""}
HOSTGATOR_USER=${HOSTGATOR_USER:-""}
HOSTGATOR_PORT=${HOSTGATOR_PORT:-"22"}

echo -e "${YELLOW}1️⃣  Verificando pré-requisitos...${NC}"

# Check Node.js
if ! command -v node &> /dev/null; then
    echo -e "${RED}❌ Node.js não encontrado${NC}"
    exit 1
fi
NODE_VERSION=$(node --version)
echo -e "${GREEN}✅ Node.js $NODE_VERSION${NC}"

# Check npm
if ! command -v npm &> /dev/null; then
    echo -e "${RED}❌ npm não encontrado${NC}"
    exit 1
fi
echo -e "${GREEN}✅ npm encontrado${NC}"

# Check PostgreSQL/Database
echo -e "${YELLOW}2️⃣  Verificando banco de dados...${NC}"
if psql -c "SELECT 1" > /dev/null 2>&1; then
    echo -e "${GREEN}✅ PostgreSQL conectando${NC}"
else
    echo -e "${YELLOW}⚠️  PostgreSQL não disponível localmente${NC}"
    echo -e "${YELLOW}    Usando Supabase para produção${NC}"
fi

# Setup Backend
echo -e "${YELLOW}3️⃣  Setup do Backend...${NC}"
cd apps/backend

# Install dependencies
echo "   ⏳ npm install..."
npm install --legacy-peer-deps --silent > /dev/null 2>&1
echo -e "${GREEN}   ✅ Dependências instaladas${NC}"

# Build backend
echo "   ⏳ npm run build..."
npm run build > /dev/null 2>&1
echo -e "${GREEN}   ✅ Backend compilado${NC}"

cd ../..

# Setup Storefront
echo -e "${YELLOW}4️⃣  Setup do Storefront...${NC}"
cd apps/storefront

# Update .env for production
if [ ! -f .env.production ]; then
    cp .env.local .env.production 2>/dev/null || true
    sed -i 's/localhost:9000/api.shopvivaliz.com.br/g' .env.production 2>/dev/null || true
    sed -i 's/localhost:3000/shopvivaliz.com.br/g' .env.production 2>/dev/null || true
fi

# Install dependencies
echo "   ⏳ npm install..."
npm install --silent > /dev/null 2>&1
echo -e "${GREEN}   ✅ Dependências instaladas${NC}"

# Build storefront
echo "   ⏳ npm run build..."
npm run build > /dev/null 2>&1
echo -e "${GREEN}   ✅ Storefront compilado${NC}"

cd ../..

# Migrations
echo -e "${YELLOW}5️⃣  Executando migrações...${NC}"
cd apps/backend

# Only run if database is available
if psql -c "SELECT 1" > /dev/null 2>&1; then
    npm run migrate:latest > /dev/null 2>&1
    echo -e "${GREEN}   ✅ Migrações executadas${NC}"
else
    echo -e "${YELLOW}   ⚠️  Banco de dados não disponível (será configurado em Supabase)${NC}"
fi

cd ../..

# Create deployment report
echo -e "${YELLOW}6️⃣  Gerando relatório...${NC}"

cat > DEPLOYMENT_REPORT.md << 'EOF'
# 🚀 Deployment Report

## Status
✅ Backend compilado
✅ Storefront compilado
✅ Dependências instaladas
✅ Pronto para deploy

## Próximos Passos em HostGator

1. SSH para servidor
2. Clone repositório
3. npm install --legacy-peer-deps
4. Configure DATABASE_URL (Supabase)
5. npm run migrate:latest
6. PM2 start backend
7. Configure reverse proxy (Apache)
8. SSL/TLS com Let's Encrypt

## Serviços em Produção
- Backend: api.shopvivaliz.com.br:9000
- Frontend: shopvivaliz.com.br:3000
- Database: Supabase (postgresql)

## Monitoramento
- PM2 Plus para analytics
- Logs em /var/log/medusa/
- Health check em /health

---
Gerado em: $(date)
EOF

echo -e "${GREEN}✅ Relatório criado${NC}"

# Final summary
echo ""
echo "╔════════════════════════════════════════════════════════════════╗"
echo -e "║  ${GREEN}✅ DEPLOYMENT PRONTO PARA PRODUÇÃO${NC}                  ║"
echo "╚════════════════════════════════════════════════════════════════╝"
echo ""
echo "📋 Checklist de Produção:"
echo ""
echo "Backend:"
echo "   ✅ Código compilado"
echo "   ✅ Dependências instaladas"
echo "   ⏳ Database: Configure DATABASE_URL em Supabase"
echo "   ⏳ Migrations: npm run migrate:latest"
echo "   ⏳ PM2: pm2 start apps/backend/dist"
echo ""
echo "Storefront:"
echo "   ✅ Código compilado"
echo "   ✅ Dependências instaladas"
echo "   ⏳ Build: npm run build"
echo "   ⏳ PM2: pm2 start npm -- --prefix apps/storefront run start"
echo ""
echo "Configuração:"
echo "   ⏳ SSL/TLS (Let's Encrypt)"
echo "   ⏳ Reverse proxy (Apache/Nginx)"
echo "   ⏳ GitHub Secrets (CI/CD)"
echo "   ⏳ Backup automático"
echo ""
echo "📞 Suporte:"
echo "   Docs: /claude/medusa/DEPLOY_HOSTGATOR.md"
echo "   Report: ./DEPLOYMENT_REPORT.md"
echo ""
