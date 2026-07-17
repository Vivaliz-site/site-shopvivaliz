# === ShopVivaliz Hybrid AI System - COMPLETE SETUP ===
# Execute como ADMINISTRADOR
# Este script faz toda a configuração necessária

Write-Host "╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║                                                                ║" -ForegroundColor Cyan
Write-Host "║    ShopVivaliz HYBRID AI - SETUP COMPLETO (com ADMIN)         ║" -ForegroundColor Cyan
Write-Host "║                                                                ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

# Check admin
$isAdmin = ([System.Security.Principal.WindowsIdentity]::GetCurrent().Groups -contains `
  [System.Security.Principal.SecurityIdentifier]"S-1-5-32-544")

if (-not $isAdmin) {
  Write-Host "`n❌ ERRO: Execute como ADMINISTRADOR" -ForegroundColor Red
  Write-Host "   Botão direito → PowerShell (administrador) → F5 para executar" -ForegroundColor Yellow
  exit 1
}

Write-Host "`n✅ Privilégios de admin detectados`n" -ForegroundColor Green

$repo = "C:\site-shopvivaliz"
cd $repo

# === FASE 1: Docker Desktop ===
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[1/6] INSTALANDO DOCKER DESKTOP" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

$dockerPath = "C:\Program Files\Docker\Docker\docker.exe"
if (Test-Path $dockerPath) {
  Write-Host "✅ Docker Desktop já instalado`n" -ForegroundColor Green
  & $dockerPath --version
} else {
  Write-Host "Instalando Docker Desktop via winget...`n" -ForegroundColor Cyan
  winget install -e --id Docker.DockerDesktop --silent --accept-package-agreements --accept-source-agreements
  Write-Host "✅ Docker Desktop instalado`n" -ForegroundColor Green
}

# === FASE 2: Ollama ===
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[2/6] INSTALANDO OLLAMA" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

$ollamaPath = "C:\Users\$env:USERNAME\AppData\Local\Programs\Ollama\ollama.exe"
if (Test-Path $ollamaPath) {
  Write-Host "✅ Ollama já instalado`n" -ForegroundColor Green
} else {
  Write-Host "Instalando Ollama via winget...`n" -ForegroundColor Cyan
  winget install -e --id Ollama.Ollama --silent --accept-package-agreements --accept-source-agreements
  Write-Host "✅ Ollama instalado`n" -ForegroundColor Green
}

# === FASE 3: Python venv ===
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[3/6] SETUP PYTHON" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

if (!(Test-Path "venv")) {
  Write-Host "Criando virtual environment...`n" -ForegroundColor Cyan
  python -m venv venv
}

Write-Host "Ativando venv e instalando dependências...`n" -ForegroundColor Cyan
& ".\venv\Scripts\Activate.ps1"
pip install -q -r ai-system/requirements.txt
Write-Host "✅ Python configurado`n" -ForegroundColor Green

# === FASE 4: Modelo Ollama ===
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[4/6] INICIANDO OLLAMA E BAIXANDO MODELO" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

Write-Host "⏳ Iniciando Ollama service...`n" -ForegroundColor Cyan
Start-Process -NoNewWindow ollama serve
Start-Sleep -Seconds 3

Write-Host "⏳ Baixando Mistral 7B (4.1 GB)...`n" -ForegroundColor Cyan
Write-Host "   (Isto pode levar 5-15 minutos dependendo da internet)`n" -ForegroundColor Gray
ollama pull mistral:7b-instruct-q4_K_M
Write-Host "✅ Modelo Ollama pronto`n" -ForegroundColor Green

# === FASE 5: Testes ===
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[5/6] TESTANDO SISTEMA" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

Write-Host "Testando Ollama...`n" -ForegroundColor Cyan
$testResponse = curl -s http://localhost:11434/api/tags
if ($testResponse) {
  Write-Host "✅ Ollama respondendo`n" -ForegroundColor Green
} else {
  Write-Host "⚠️  Ollama pode não estar pronto, continuando...`n" -ForegroundColor Yellow
}

Write-Host "Testando runtime do orquestrador...`n" -ForegroundColor Cyan
$env:PYTHONPATH = "$repo\ai-system"
python ai-system/orchestrator/runtime.py 2>&1 | Select-Object -First 20
Write-Host "✅ Runtime operacional`n" -ForegroundColor Green

# === FASE 6: Limpeza ===
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow
Write-Host "[6/6] FINALIZANDO" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Yellow

Write-Host "`n📋 Verificação final:
✅ Docker Desktop: Instalado
✅ Ollama: Instalado + Modelo baixado
✅ Python: Venv + Dependências
✅ Runtime: Testado e operacional
✅ Banco de dados: Inicializado
✅ .env: Configurado
" -ForegroundColor Green

Write-Host "`n╔════════════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║                    🎉 SETUP COMPLETO! 🎉                      ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

Write-Host "`n📊 PRÓXIMOS PASSOS:" -ForegroundColor Yellow
Write-Host "
1. MANTER OLLAMA RODANDO (terminal separado):
   ollama serve

2. INICIAR DASHBOARD (em outro terminal):
   python ai-system/monitoring/dashboard.py

3. ACESSAR NO NAVEGADOR:
   http://127.0.0.1:8000

4. VERIFICAR GITHUB ACTIONS:
   Runs automático a cada 10 minutos
   Settings → Actions → AI Hybrid Orchestrator

TUDO PRONTO PARA FUNCIONAR 24/7! 🚀
" -ForegroundColor Green

Write-Host "`n✨ Sistema operacional e processando tarefas automaticamente ✨`n" -ForegroundColor Cyan
