# Script que inicia RCE Server em background (silenciosamente)
# Usado para inicialização automática ao login

$port = 5557
$ip = "127.0.0.1"
$token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
$logDir = Join-Path $PSScriptRoot "logs"
$logFile = Join-Path $logDir "rce-startup.log"

# Criar pasta de logs se não existir
if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$timestamp - $Message" | Tee-Object -Append -FilePath $logFile
}

# Verificar se servidor já está rodando
Log "Verificando se servidor já está rodando..."

try {
    $uri = "http://${ip}:${port}/status"
    $response = Invoke-WebRequest -Uri $uri `
        -Headers @{"Authorization" = "Bearer $token"} `
        -Method GET `
        -TimeoutSec 2 `
        -ErrorAction Stop

    if ($response.StatusCode -eq 200) {
        Log "✅ RCE Server já rodando"
        exit 0
    }
} catch {
    Log "Servidor não está respondendo, iniciando..."
}

Log "🚀 Iniciando RCE Server..."

# Configurar variáveis de ambiente
$env:PORT = $port
$env:BIND_IP = $ip
$env:COMMAND_SERVER_TOKEN = $token

# Iniciar servidor em background (em novo processo PowerShell)
$processStartInfo = New-Object System.Diagnostics.ProcessStartInfo
$processStartInfo.FileName = "powershell.exe"
$processStartInfo.Arguments = "-NoProfile -Command `"cd '$PSScriptRoot'; node rce-command-server.js`""
$processStartInfo.UseShellExecute = $false
$processStartInfo.RedirectStandardOutput = $true
$processStartInfo.RedirectStandardError = $true
$processStartInfo.CreateNoWindow = $true

$process = [System.Diagnostics.Process]::Start($processStartInfo)
Log "✅ RCE Server iniciado (PID: $($process.Id))"

Start-Sleep -Milliseconds 500

# Verificar se iniciou corretamente
try {
    $uri = "http://${ip}:${port}/status"
    $response = Invoke-WebRequest -Uri $uri `
        -Headers @{"Authorization" = "Bearer $token"} `
        -Method GET `
        -TimeoutSec 2 `
        -ErrorAction Stop

    if ($response.StatusCode -eq 200) {
        Log "✅ RCE Server respondendo em http://${ip}:${port}"
        Log "📝 Token: $token"
        Log "🔐 Servidor rodando em background"
        exit 0
    }
} catch {
    Log "⚠️ Servidor iniciado mas aguardando resposta..."
    Start-Sleep -Seconds 1
}

exit 0
