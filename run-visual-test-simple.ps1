# ============================================================
# Teste Visual - ShopVivaliz Homepage
# Execute em sua máquina para diagnosticar visualmente
# ============================================================

Write-Host "`n$('='*60)" -ForegroundColor Cyan
Write-Host "🧪 Teste Visual - ShopVivaliz Homepage" -ForegroundColor Cyan
Write-Host "$('='*60)`n" -ForegroundColor Cyan

# Verificar Python
$pythonCheck = python --version 2>$null
if ($null -eq $pythonCheck) {
    Write-Host "❌ Python não encontrado. Instale em: https://python.org/" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Python encontrado: $pythonCheck" -ForegroundColor Green

# Verificar/Instalar dependências
Write-Host "`n📦 Verificando dependências..." -ForegroundColor Cyan
python -m pip list | Select-String "selenium|pillow" -Quiet >$null
if ($?) {
    Write-Host "✅ Dependências encontradas" -ForegroundColor Green
} else {
    Write-Host "📥 Instalando Selenium e Pillow..." -ForegroundColor Yellow
    python -m pip install --upgrade selenium pillow --quiet
    Write-Host "✅ Dependências instaladas" -ForegroundColor Green
}

# Executar teste
Write-Host "`n🚀 Executando teste visual...`n" -ForegroundColor Green
python test-homepage-visual.py

# Verificar resultado
$reportPath = ".\playwright-report\visual-test-report.json"
$screenshotDir = ".\playwright-report\screenshots"

if (Test-Path $reportPath) {
    Write-Host "`n✅ Relatório criado:" -ForegroundColor Green
    Get-Content $reportPath | ConvertFrom-Json | ConvertTo-Json -Depth 10 | Write-Host -ForegroundColor Gray
}

if (Test-Path $screenshotDir) {
    $screenshots = Get-ChildItem $screenshotDir -Filter "*.png" | Measure-Object
    Write-Host "`n📸 Screenshots capturados: $($screenshots.Count)" -ForegroundColor Green
    Write-Host "📁 Localização: $screenshotDir`n" -ForegroundColor Gray

    # Tentar abrir o primeiro screenshot
    $firstScreenshot = Get-ChildItem $screenshotDir -Filter "*.png" | Select-Object -First 1
    if ($firstScreenshot) {
        Write-Host "🖼️  Abrindo screenshot..." -ForegroundColor Yellow
        Invoke-Item $firstScreenshot.FullName
    }
}

Write-Host "`n💡 Próximos passos:" -ForegroundColor Cyan
Write-Host "  1. Verifique o screenshot para ver como a página está" -ForegroundColor Gray
Write-Host "  2. Procure pelos elementos:" -ForegroundColor Gray
Write-Host "     ✓ hero-carousel (banners)" -ForegroundColor Gray
Write-Host "     ✓ home-categories (categorias)" -ForegroundColor Gray
Write-Host "     ✓ product-card (produtos)" -ForegroundColor Gray
Write-Host "  3. Se os elementos estiverem presentes, o problema é no CSS/JS do seu navegador" -ForegroundColor Gray
Write-Host "  4. Se faltarem, há um problema no servidor`n" -ForegroundColor Gray
