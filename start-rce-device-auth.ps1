# Script para iniciar RCE Server com Device Auth em background
# Executado automaticamente ao fazer login

$port = 5557
$ip = "127.0.0.1"
$token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
$logDir = Join-Path $PSScriptRoot "logs"
$logFile = Join-Path $logDir "rce-startup.log"

# Criar pasta de logs
if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

function Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$timestamp - $Message" | Tee-Object -Append -FilePath $logFile
}

Log "🚀 Iniciando RCE Server com Device Auth..."

# Configurar variáveis de ambiente
$env:PORT = $port
$env:BIND_IP = $ip
$env:COMMAND_SERVER_TOKEN = $token

# Iniciar servidor em background
$processStartInfo = New-Object System.Diagnostics.ProcessStartInfo
$processStartInfo.FileName = "powershell.exe"
$processStartInfo.Arguments = "-NoProfile -Command `"cd '$PSScriptRoot'; node rce-command-server-device-auth.js`""
$processStartInfo.UseShellExecute = $false
$processStartInfo.RedirectStandardOutput = $true
$processStartInfo.RedirectStandardError = $true
$processStartInfo.CreateNoWindow = $true

$process = [System.Diagnostics.Process]::Start($processStartInfo)
Log "✅ RCE Server iniciado (PID: $($process.Id))"

Start-Sleep -Milliseconds 1000

# Verificar se iniciou
try {
    $uri = "http://${ip}:${port}/status"
    $response = Invoke-WebRequest -Uri $uri `
        -Headers @{"Authorization" = "Bearer $token"; "X-Device-ID" = "iphone-3cc2c19459524e3cb79d7bdfaa1b456a"} `
        -Method GET `
        -TimeoutSec 2 `
        -ErrorAction Stop

    if ($response.StatusCode -eq 200) {
        Log "✅ RCE Server respondendo (Device Auth ativo)"
    }
} catch {
    Log "⚠️ Aguardando resposta do servidor..."
}

exit 0
