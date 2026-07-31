# === Executar Orquestrador Continuamente (24/7) ===
# Execute em PowerShell (não precisa de admin)
# Processa tarefas a cada 5 minutos

Write-Host "╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║     ShopVivaliz Hybrid AI - Orchestrador Contínuo (24/7)      ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

$repo = "C:\site-shopvivaliz"
cd $repo

# Ativar venv
Write-Host "`nAtivando Python venv..." -ForegroundColor Yellow
& ".\venv\Scripts\Activate.ps1"

Write-Host "`n🤖 Iniciando Orquestrador (ciclo contínuo)..." -ForegroundColor Green
Write-Host "`n📋 Processando fila de tarefas a cada 5 minutos" -ForegroundColor Cyan
Write-Host "💾 Atualizando memória e banco de dados" -ForegroundColor Cyan
Write-Host "💰 Monitorando custos em tempo real" -ForegroundColor Cyan
Write-Host "`nPressione CTRL+C para interromper`n" -ForegroundColor Gray

$env:PYTHONPATH = "$repo\ai-system"

# Loop contínuo
$cycle = 1
while ($true) {
    Write-Host "`n═════════════════════════════════════════════════════════════════" -ForegroundColor Yellow
    Write-Host "CICLO #$cycle - $(Get-Date)" -ForegroundColor Yellow
    Write-Host "═════════════════════════════════════════════════════════════════" -ForegroundColor Yellow

    python ai-system/orchestrator/runtime.py

    Write-Host "`n⏳ Próximo ciclo em 5 minutos..." -ForegroundColor Gray
    Start-Sleep -Seconds 300
    $cycle++
}
