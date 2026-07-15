#!/bin/bash

# Script de setup automático do Medusa
# Executa todos os passos necessários para ter o projeto 100% funcional

set -e

echo "╔═══════════════════════════════════════════════════════════════╗"
echo "║  🚀 SETUP AUTOMÁTICO - SHOPVIVALIZ MEDUSA                   ║"
echo "╚═══════════════════════════════════════════════════════════════╝"
echo ""

# 1. Verificar Node.js
echo "1️⃣  Verificando Node.js..."
if ! command -v node &> /dev/null; then
    echo "❌ Node.js não encontrado"
    exit 1
fi
NODE_VERSION=$(node --version)
echo "✅ Node.js $NODE_VERSION"
echo ""

# 2. Setup Backend
echo "2️⃣  Setup Backend..."
cd claude/medusa/apps/backend

# Copiar .env se não existir
if [ ! -f .env ]; then
    echo "   📝 Criando .env..."
    cp .env.example .env 2>/dev/null || true
fi

echo "   ⏳ npm install..."
npm install --silent > /dev/null 2>&1 &
INSTALL_PID=$!

# 3. Setup Storefront em paralelo
echo "3️⃣  Setup Storefront..."
cd ../../../apps/storefront

if [ ! -f .env.local ]; then
    echo "   📝 Criando .env.local..."
    cat > .env.local << 'EOF'
NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY=pk_live_default
NEXT_PUBLIC_MEDUSA_BACKEND_URL=http://localhost:9000
NEXT_PUBLIC_DEFAULT_REGION=br
NEXT_PUBLIC_BASE_URL=http://localhost:3000
NODE_ENV=development
EOF
fi

echo "   ⏳ npm install..."
npm install --silent > /dev/null 2>&1 &
STOREFRONT_INSTALL_PID=$!

# Aguardar instalações
echo ""
echo "⏳ Aguardando instalações (backend + storefront)..."
wait $INSTALL_PID
wait $STOREFRONT_INSTALL_PID
echo "✅ Instalações concluídas"
echo ""

# 4. Info final
echo "═══════════════════════════════════════════════════════════════"
echo "✅ SETUP AUTOMÁTICO CONCLUÍDO!"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "Próximos passos:"
echo ""
echo "1️⃣  Para testar agora (mock API):"
echo "    Abra: claude/medusa/test-checkout.html"
echo ""
echo "2️⃣  Para testar com banco de dados:"
echo "    • Configurar SUPABASE_URL em .env"
echo "    • npm run migrate"
echo "    • npm run seed"
echo "    • npm run dev"
echo ""
echo "3️⃣  Para rodar storefront:"
echo "    cd claude/medusa/apps/storefront"
echo "    npm run dev"
echo ""
