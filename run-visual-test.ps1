# Test Visual com Playwright - Homepage ShopVivaliz
# Execute em sua máquina para testar a página visualmente

Write-Host "🧪 Iniciando Teste Visual com Playwright..." -ForegroundColor Cyan
Write-Host "📍 Testando: https://shopvivaliz.com.br/" -ForegroundColor Gray

# Verificar se Node.js está instalado
$nodeCheck = node --version 2>$null
if ($null -eq $nodeCheck) {
    Write-Host "❌ Node.js não encontrado. Instale em: https://nodejs.org/" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Node.js encontrado: $nodeCheck" -ForegroundColor Green

# Verificar/Instalar Playwright
Write-Host "`n📦 Verificando Playwright..." -ForegroundColor Cyan
$playwrightCheck = npm list playwright 2>$null | Select-String "playwright" -Quiet
if (-not $playwrightCheck) {
    Write-Host "📥 Instalando Playwright (primeira execução)..." -ForegroundColor Yellow
    npm install --save-dev playwright
    npx playwright install
}

# Executar teste
Write-Host "`n🚀 Executando teste..." -ForegroundColor Green
node test-homepage-visual.mjs

# Abrir resultado
$screenshotPath = ".\playwright-report\screenshots"
if (Test-Path $screenshotPath) {
    Write-Host "`n📸 Screenshots salvos em: $screenshotPath" -ForegroundColor Green
    Write-Host "📊 Relatório em: .\playwright-report\visual-test-report.json" -ForegroundColor Green

    # Abrir relatório
    $reportJson = ".\playwright-report\visual-test-report.json"
    if (Test-Path $reportJson) {
        Write-Host "`n📖 Abrindo relatório..." -ForegroundColor Cyan
        Get-Content $reportJson | ConvertFrom-Json | Format-List
    }
}

Write-Host "`n✅ Teste concluído!" -ForegroundColor Green
Write-Host "`n💡 Próximo passo: Verifique o screenshot para ver como a página está sendo renderizada." -ForegroundColor Yellow
