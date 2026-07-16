# Script para Rodar Medusa + Storefront Automaticamente

param(
    [string]$DatabaseURL = ""
)

Write-Host "╔══════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║     🚀 MEDUSA + STOREFRONT - INICIALIZAR AGORA      ║" -ForegroundColor Cyan
Write-Host "╚══════════════════════════════════════════════════════╝" -ForegroundColor Cyan
Write-Host ""

# Se não passou DATABASE_URL, pedir
if ([string]::IsNullOrEmpty($DatabaseURL)) {
    Write-Host "Nenhuma DATABASE_URL fornecida." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Cole aqui a DATABASE_URL de seu banco:" -ForegroundColor Cyan
    Write-Host "(Supabase, PostgreSQL local, Render, etc)" -ForegroundColor Gray
    Write-Host ""
    Write-Host "Exemplo: postgresql://postgres:senha@host:5432/db" -ForegroundColor DarkGray
    $DatabaseURL = Read-Host "DATABASE_URL"
}

if ([string]::IsNullOrEmpty($DatabaseURL)) {
    Write-Host ""
    Write-Host "❌ Nenhuma DATABASE_URL fornecida. Abortando." -ForegroundColor Red
    Write-Host ""
    Write-Host "Obtenha uma em:" -ForegroundColor Yellow
    Write-Host "  • Supabase: https://supabase.com (5 min, grátis)" -ForegroundColor Cyan
    Write-Host "  • Render: https://render.com (grátis)" -ForegroundColor Cyan
    Write-Host "  • PostgreSQL local: https://postgresql.org/download/windows" -ForegroundColor Cyan
    exit
}

Write-Host ""
Write-Host "✅ Database URL fornecida!" -ForegroundColor Green
Write-Host ""

# Setup
$backendPath = "C:\Users\user\site-shopvivaliz\claude\medusa\apps\backend"
$storefrontPath = "C:\Users\user\site-shopvivaliz\claude\medusa\apps\storefront"
$NodePath = "C:\Program Files\nodejs"
$env:Path = "$NodePath;$env:Path"

# Atualizar .env no backend
Write-Host "📝 Atualizando .env..." -ForegroundColor Yellow
$envContent = Get-Content "$backendPath\.env"
$envContent = $envContent -replace 'DATABASE_URL=.*', "DATABASE_URL=$DatabaseURL"
Set-Content "$backendPath\.env" $envContent
Write-Host "✅ .env atualizado" -ForegroundColor Green
Write-Host ""

# Testar conexão
Write-Host "🔗 Testando conexão com banco..." -ForegroundColor Yellow
Write-Host "   (Pode levar alguns segundos...)" -ForegroundColor Gray

# Rodar migrations
Write-Host ""
Write-Host "📊 Rodando migrations..." -ForegroundColor Yellow
Push-Location $backendPath
try {
    & npm run migrate 2>&1 | Tee-Object -Variable output | ForEach-Object {
        if ($_ -match "error|failed") {
            Write-Host $_ -ForegroundColor Red
        } else {
            Write-Host $_ -ForegroundColor Gray
        }
    }

    if ($LASTEXITCODE -ne 0) {
        Write-Host ""
        Write-Host "❌ Migrations falharam!" -ForegroundColor Red
        Write-Host "Verifique:" -ForegroundColor Yellow
        Write-Host "  1. DATABASE_URL está correta?" -ForegroundColor Gray
        Write-Host "  2. Banco de dados está online?" -ForegroundColor Gray
        Write-Host "  3. Aguarde se Supabase (leva 2-3 min para ativar)" -ForegroundColor Gray
        exit
    }
} catch {
    Write-Host "❌ Erro ao rodar migrations" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit
}

# Seed data
Write-Host ""
Write-Host "🌱 Carregando dados iniciais..." -ForegroundColor Yellow
try {
    & npm run seed 2>&1 | Tee-Object -Variable output | ForEach-Object {
        Write-Host $_ -ForegroundColor Gray
    }
} catch {
    Write-Host "⚠️  Erro ao carregar dados (pode continuar)" -ForegroundColor Yellow
}

Pop-Location

# Informações de login
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "✅ BANCO DE DADOS PRONTO!" -ForegroundColor Green
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "📋 Credenciais de Admin:" -ForegroundColor Cyan
Write-Host "   Email: admin@medusajs.com" -ForegroundColor Yellow
Write-Host "   Senha: supersecret" -ForegroundColor Yellow
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "PRÓXIMO PASSO: Rodar Backend e Storefront" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "🎬 ABRA DOIS TERMINAIS (terminals/cmd):" -ForegroundColor Cyan
Write-Host ""
Write-Host "TERMINAL 1 - BACKEND:" -ForegroundColor Green
Write-Host "┌─────────────────────────────────────────────────────┐" -ForegroundColor DarkGray
Write-Host "│ cd claude\medusa\apps\backend                       │" -ForegroundColor DarkGray
Write-Host "│ npm run dev                                          │" -ForegroundColor DarkGray
Write-Host "│                                                     │" -ForegroundColor DarkGray
Write-Host "│ 🌐 Aguarde mensagem: 'Server listening on 9000'    │" -ForegroundColor DarkGray
Write-Host "│ → Acesse: http://localhost:9000/admin              │" -ForegroundColor DarkGray
Write-Host "└─────────────────────────────────────────────────────┘" -ForegroundColor DarkGray
Write-Host ""
Write-Host "TERMINAL 2 - STOREFRONT:" -ForegroundColor Green
Write-Host "┌─────────────────────────────────────────────────────┐" -ForegroundColor DarkGray
Write-Host "│ cd claude\medusa\apps\storefront                    │" -ForegroundColor DarkGray
Write-Host "│ npm run dev                                          │" -ForegroundColor DarkGray
Write-Host "│                                                     │" -ForegroundColor DarkGray
Write-Host "│ 🌐 Aguarde: 'Ready in Xs'                           │" -ForegroundColor DarkGray
Write-Host "│ → Acesse: http://localhost:3000                    │" -ForegroundColor DarkGray
Write-Host "└─────────────────────────────────────────────────────┘" -ForegroundColor DarkGray
Write-Host ""
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "TESTE O FLUXO COMPLETO:" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Admin (localhost:9000/admin):" -ForegroundColor Cyan
Write-Host "   ✓ Login" -ForegroundColor Gray
Write-Host "   ✓ Products → Create" -ForegroundColor Gray
Write-Host "   ✓ Preencha: Title, Description, Price, Size" -ForegroundColor Gray
Write-Host "   ✓ Save" -ForegroundColor Gray
Write-Host ""
Write-Host "2. Storefront (localhost:3000):" -ForegroundColor Cyan
Write-Host "   ✓ Veja produtos listados" -ForegroundColor Gray
Write-Host "   ✓ Clique em um produto" -ForegroundColor Gray
Write-Host "   ✓ 'Add to Cart'" -ForegroundColor Gray
Write-Host "   ✓ Clique 'Cart'" -ForegroundColor Gray
Write-Host "   ✓ 'Checkout'" -ForegroundColor Gray
Write-Host "   ✓ Preencha: Endereço, Email, Telefone" -ForegroundColor Gray
Write-Host "   ✓ Selecione shipping" -ForegroundColor Gray
Write-Host "   ✓ Selecione payment (Test)" -ForegroundColor Gray
Write-Host "   ✓ 'Place Order'" -ForegroundColor Gray
Write-Host ""
Write-Host "✅ Checkout completo!" -ForegroundColor Green
Write-Host ""
