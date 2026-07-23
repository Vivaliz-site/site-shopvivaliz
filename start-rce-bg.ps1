# Script que inicia RCE Server em background (silenciosamente)
# Usado pelo VS Code para inicializar servidor automaticamente

$port = 5557
$ip = "127.0.0.1"
$token = "hBu-3gs3meFOp82AnXLzljmIvNaf-7ih"
$logFile = "logs/rce-startup.log"

# Verificar se servidor já está rodando
try {
    $response = curl -X GET "http://$ip:$port/status" `
        -H "Authorization: Bearer $token" `
        -ErrorAction SilentlyContinue

    if ($response) {
        Write-Host "✅ RCE Server já rodando" | Tee-Object -Append $logFile
        exit 0
    }
} catch {
    # Servidor não está rodando, iniciar
}

Write-Host "🚀 Iniciando RCE Server..." | Tee-Object -Append $logFile

# Iniciar servidor em background
$env:PORT = $port
$env:BIND_IP = $ip
$env:COMMAND_SERVER_TOKEN = $token

# Rodar em background sem janela visível
$process = Start-Process powershell -ArgumentList `
    "-NoProfile -Command `"cd '$PSScriptRoot'; node rce-command-server.js`"" `
    -WindowStyle Hidden `
    -PassThru `
    -RedirectStandardOutput "$logFile" `
    -RedirectStandardError "$logFile"

Write-Host "✅ RCE Server iniciado (PID: $($process.Id))" | Tee-Object -Append $logFile
Start-Sleep -Milliseconds 500

# Verificar se iniciou corretamente
try {
    $response = curl -X GET "http://$ip:$port/status" `
        -H "Authorization: Bearer $token" `
        -ErrorAction SilentlyContinue

    if ($response) {
        Write-Host "✅ RCE Server respondendo em http://$ip:$port" | Tee-Object -Append $logFile
        Write-Host "📝 Token: $token" | Tee-Object -Append $logFile
        exit 0
    }
} catch {
    Write-Host "⚠️ Servidor iniciado mas não respondeu ainda. Aguardando..." | Tee-Object -Append $logFile
    Start-Sleep -Seconds 2
}

exit 0
