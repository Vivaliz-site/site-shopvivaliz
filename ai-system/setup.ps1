# Setup Script for ShopVivaliz Hybrid AI System
# Run as: Set-ExecutionPolicy -ExecutionPolicy Bypass -Scope Process; .\ai-system\setup.ps1

Write-Host "╔════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║     ShopVivaliz Hybrid AI System - Setup                  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

$repo_path = "C:\site-shopvivaliz"
$ai_system = "$repo_path\ai-system"

Write-Host "`n[1/5] Creating Python virtual environment..." -ForegroundColor Yellow
Set-Location $repo_path
python -m venv venv
Write-Host "✅ Virtual environment created" -ForegroundColor Green

Write-Host "`n[2/5] Activating virtual environment..." -ForegroundColor Yellow
& ".\venv\Scripts\Activate.ps1"
Write-Host "✅ Virtual environment activated" -ForegroundColor Green

Write-Host "`n[3/5] Installing Python dependencies..." -ForegroundColor Yellow
pip install -q -r ai-system/requirements.txt
Write-Host "✅ Dependencies installed" -ForegroundColor Green

Write-Host "`n[4/5] Creating directories..." -ForegroundColor Yellow
@(
    "$ai_system\orchestrator",
    "$ai_system\agents",
    "$ai_system\memory",
    "$ai_system\tools",
    "$ai_system\api-integrations",
    "$ai_system\monitoring",
    "$ai_system\config"
) | ForEach-Object {
    if (!(Test-Path $_)) { New-Item -ItemType Directory -Path $_ | Out-Null }
}
Write-Host "✅ Directories created" -ForegroundColor Green

Write-Host "`n[5/5] Initializing databases..." -ForegroundColor Yellow
python $ai_system/orchestrator/core.py | Out-Null
python $ai_system/memory/vector_memory.py | Out-Null
Write-Host "✅ Databases initialized" -ForegroundColor Green

Write-Host "`n╔════════════════════════════════════════════════════════════╗" -ForegroundColor Green
Write-Host "║                   ✨ SETUP COMPLETE ✨                    ║" -ForegroundColor Green
Write-Host "╚════════════════════════════════════════════════════════════╝" -ForegroundColor Green

Write-Host "`n📋 Next steps:" -ForegroundColor Cyan
Write-Host "1. Install Docker Desktop & Ollama: .\SETUP-DOCKER-OLLAMA.ps1" -ForegroundColor White
Write-Host "2. Start dashboard: python ai-system/monitoring/dashboard.py" -ForegroundColor White
Write-Host "3. View at: http://127.0.0.1:8000" -ForegroundColor White

Write-Host "`n📝 Environment variables needed:" -ForegroundColor Cyan
Write-Host "   OPENAI_API_KEY" -ForegroundColor Gray
Write-Host "   ANTHROPIC_API_KEY" -ForegroundColor Gray
Write-Host "   GOOGLE_API_KEY" -ForegroundColor Gray

Write-Host "`n💡 Configure in: .env file at repo root" -ForegroundColor Cyan

Write-Host "`n✅ System ready for initialization!" -ForegroundColor Green
