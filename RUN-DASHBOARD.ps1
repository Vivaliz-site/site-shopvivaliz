# === Dashboard do Sistema Híbrido de IA ===
# Execute em PowerShell (não precisa de admin)

Write-Host "╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║        ShopVivaliz Hybrid AI - Dashboard Web                  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

$repo = "C:\site-shopvivaliz"
cd $repo

# Ativar venv
Write-Host "`nAtivando Python venv..." -ForegroundColor Yellow
& ".\venv\Scripts\Activate.ps1"

# Iniciar dashboard
Write-Host "`n🚀 Iniciando Dashboard (FastAPI)..." -ForegroundColor Green
Write-Host "`n📊 Acesse em: http://127.0.0.1:8000`n" -ForegroundColor Cyan
Write-Host "Pressione CTRL+C para interromper`n" -ForegroundColor Gray

$env:PYTHONPATH = "$repo\ai-system"
python ai-system/monitoring/dashboard.py
