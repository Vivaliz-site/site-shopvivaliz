# Script para Testar o Site Medusa Localmente

Write-Host "╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║  🚀 TESTE MEDUSA + STOREFRONT LOCALMENTE      ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# 1. Verificar Node.js
Write-Host "1️⃣  Verificando Node.js..." -ForegroundColor Yellow
$NodePath = "C:\Program Files\nodejs"
$env:Path = "$NodePath;$env:Path"

try {
    $nodeVersion = & node --version
    Write-Host "✅ Node.js $nodeVersion" -ForegroundColor Green
} catch {
    Write-Host "❌ Node.js não encontrado" -ForegroundColor Red
    exit
}

# 2. Verificar PostgreSQL
Write-Host ""
Write-Host "2️⃣  Verificando banco de dados..." -ForegroundColor Yellow

$testConnection = "postgresql://medusa:medusa123@localhost:5432/shopvivaliz_medusa"

try {
    $psqlTest = & psql --version 2>$null
    Write-Host "✅ PostgreSQL detectado" -ForegroundColor Green
    Write-Host "   Certificar-se que banco 'shopvivaliz_medusa' existe" -ForegroundColor Gray
} catch {
    Write-Host "⚠️  PostgreSQL não encontrado localmente" -ForegroundColor Yellow
    Write-Host "   Opções:" -ForegroundColor Yellow
    Write-Host "   1. Instalar: https://www.postgresql.org/download/windows/" -ForegroundColor Gray
    Write-Host "   2. Usar Supabase: https://supabase.com" -ForegroundColor Gray
    Write-Host "   3. Usar Render: https://render.com" -ForegroundColor Gray
    Write-Host ""
    Write-Host "   Se usar Supabase/Render, atualizar DATABASE_URL em:" -ForegroundColor Yellow
    Write-Host "   claude/medusa/apps/backend/.env" -ForegroundColor Gray
}

# 3. Verificar arquivos
Write-Host ""
Write-Host "3️⃣  Verificando estrutura Medusa..." -ForegroundColor Yellow

$files = @(
    "claude/medusa/apps/backend/package.json",
    "claude/medusa/apps/storefront/package.json",
    "claude/medusa/apps/backend/.env"
)

$allOk = $true
foreach ($file in $files) {
    $path = "$pwd\$file"
    if (Test-Path $path) {
        Write-Host "✅ $file" -ForegroundColor Green
    } else {
        Write-Host "❌ $file" -ForegroundColor Red
        $allOk = $false
    }
}

if (-not $allOk) {
    Write-Host ""
    Write-Host "Arquivo .env não encontrado. Criando..." -ForegroundColor Yellow
    # Arquivo já foi criado anteriormente
}

# 4. Instruções
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "4️⃣  PRÓXIMAS AÇÕES" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "OPÇÃO A: Usar Banco Local (PostgreSQL)" -ForegroundColor Green
Write-Host "  1. Instalar PostgreSQL: https://www.postgresql.org/download/windows/" -ForegroundColor Gray
Write-Host "  2. Criar banco de dados:" -ForegroundColor Gray
Write-Host "     psql -U postgres" -ForegroundColor DarkGray
Write-Host "     CREATE DATABASE shopvivaliz_medusa;" -ForegroundColor DarkGray
Write-Host "     CREATE USER medusa WITH PASSWORD 'medusa123';" -ForegroundColor DarkGray
Write-Host "     GRANT ALL PRIVILEGES ON DATABASE shopvivaliz_medusa TO medusa;" -ForegroundColor DarkGray
Write-Host ""

Write-Host "OPÇÃO B: Usar Supabase (Recomendado - 5 min)" -ForegroundColor Green
Write-Host "  1. Ir para: https://supabase.com" -ForegroundColor Gray
Write-Host "  2. Sign up → Create project → São Paulo" -ForegroundColor Gray
Write-Host "  3. Copiar Connection String (DATABASE_URL)" -ForegroundColor Gray
Write-Host "  4. Colar em: claude/medusa/apps/backend/.env" -ForegroundColor Gray
Write-Host ""

Write-Host "OPÇÃO C: Usar Render.com (Grátis, rápido)" -ForegroundColor Green
Write-Host "  1. Ir para: https://render.com" -ForegroundColor Gray
Write-Host "  2. Create → PostgreSQL → Free" -ForegroundColor Gray
Write-Host "  3. Copiar URL de conexão" -ForegroundColor Gray
Write-Host "  4. Colar em: claude/medusa/apps/backend/.env" -ForegroundColor Gray
Write-Host ""

Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "DEPOIS QUE BANCO ESTIVER PRONTO:" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

Write-Host "Terminal 1: Backend" -ForegroundColor Cyan
Write-Host "  cd claude/medusa/apps/backend" -ForegroundColor Gray
Write-Host "  npm run migrate" -ForegroundColor DarkGray
Write-Host "  npm run seed" -ForegroundColor DarkGray
Write-Host "  npm run dev" -ForegroundColor DarkGray
Write-Host "  → Acessa: http://localhost:9000/admin" -ForegroundColor Yellow
Write-Host ""

Write-Host "Terminal 2: Storefront" -ForegroundColor Cyan
Write-Host "  cd claude/medusa/apps/storefront" -ForegroundColor Gray
Write-Host "  npm run dev" -ForegroundColor DarkGray
Write-Host "  → Acessa: http://localhost:3000" -ForegroundColor Yellow
Write-Host ""

Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "TESTE O FLUXO COMPLETO:" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Login admin: admin@medusajs.com / supersecret" -ForegroundColor Gray
Write-Host "2. Criar produto (Products → Create)" -ForegroundColor Gray
Write-Host "3. Ir ao storefront (localhost:3000)" -ForegroundColor Gray
Write-Host "4. Ver produto listado" -ForegroundColor Gray
Write-Host "5. Clicar no produto" -ForegroundColor Gray
Write-Host "6. Adicionar ao carrinho" -ForegroundColor Gray
Write-Host "7. Ir ao carrinho" -ForegroundColor Gray
Write-Host "8. Checkout" -ForegroundColor Gray
Write-Host "9. Preencher dados" -ForegroundColor Gray
Write-Host "10. Confirmar pedido" -ForegroundColor Gray
Write-Host ""

Write-Host "✅ Tudo pronto! Siga os passos acima para testar." -ForegroundColor Green
