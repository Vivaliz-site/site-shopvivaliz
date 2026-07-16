# API Olist Local - Launcher Script
# Executa a API Olist simulada em http://localhost:5000

param(
    [string]$Port = "5000",
    [switch]$Help
)

if ($Help) {
    Write-Host @"
🚀 API Olist Local - Launcher

Uso: .\api\olist\run-local-server.ps1 [-Port <porta>]

Opções:
  -Port <porta>    Porta para executar (padrão: 5000)
  -Help            Mostra esta mensagem

Exemplos:
  .\api\olist\run-local-server.ps1                 # Executa em localhost:5000
  .\api\olist\run-local-server.ps1 -Port 8000      # Executa em localhost:8000

Após iniciar, a API estará disponível em:
  • Health: http://localhost:$Port/health
  • Status: http://localhost:$Port/status
  • Orders: http://localhost:$Port/v2/orders
  • Products: http://localhost:$Port/v2/products
  • Webhooks: http://localhost:$Port/webhooks

Pressione CTRL+C para parar o servidor.
"@
    exit 0
}

$scriptPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$serverPath = Join-Path $scriptPath "local-server.py"

if (-not (Test-Path $serverPath)) {
    Write-Host "❌ Arquivo não encontrado: $serverPath" -ForegroundColor Red
    exit 1
}

# Verificar se Python está instalado
$pythonCmd = $null
@("python3", "python", "py") | ForEach-Object {
    if ((Get-Command $_ -ErrorAction SilentlyContinue)) {
        $pythonCmd = $_
    }
}

if (-not $pythonCmd) {
    Write-Host "❌ Python não encontrado no PATH" -ForegroundColor Red
    Write-Host ""
    Write-Host "Instale Python de https://www.python.org/downloads/"
    Write-Host "E certifique-se de adicionar ao PATH durante instalação."
    exit 1
}

# Verificar se Flask está instalado
Write-Host "Verificando dependências..." -ForegroundColor Cyan
$flaskCheck = & $pythonCmd -c "import flask; print('OK')" 2>&1
if ($flaskCheck -ne "OK") {
    Write-Host "Instalando Flask..." -ForegroundColor Yellow
    & $pythonCmd -m pip install flask -q
    if ($LASTEXITCODE -ne 0) {
        Write-Host "❌ Erro ao instalar Flask" -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host "═" * 60 -ForegroundColor Cyan
Write-Host "🚀 API Olist Local" -ForegroundColor Green
Write-Host "═" * 60 -ForegroundColor Cyan
Write-Host ""
Write-Host "Iniciando servidor em http://localhost:$Port" -ForegroundColor Green
Write-Host ""
Write-Host "Endpoints disponíveis:" -ForegroundColor Cyan
Write-Host "  • Health:   http://localhost:$Port/health"
Write-Host "  • Status:   http://localhost:$Port/status"
Write-Host "  • Orders:   http://localhost:$Port/v2/orders"
Write-Host "  • Products: http://localhost:$Port/v2/products"
Write-Host "  • Webhooks: http://localhost:$Port/webhooks"
Write-Host ""
Write-Host "Teste com curl ou em seu navegador." -ForegroundColor Yellow
Write-Host "Pressione CTRL+C para parar o servidor." -ForegroundColor Yellow
Write-Host ""

# Configurar variável de ambiente FLASK_ENV
$env:FLASK_ENV = "development"

# Executar o servidor
& $pythonCmd $serverPath
