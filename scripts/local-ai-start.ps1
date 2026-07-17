$ErrorActionPreference = "Stop"
$Repo = "C:\site-shopvivaliz"
$StartScript = Join-Path $Repo "scripts\start-local-ai.ps1"

Write-Host "🚀 Iniciando servicos de IA Local..." -ForegroundColor Yellow
& $StartScript
Write-Host "✅ Servicos de IA Local iniciados com sucesso!" -ForegroundColor Green
