$Repo = "C:\site-shopvivaliz"
$AiDir = Join-Path $Repo ".ai"
$LogDir = Join-Path $Repo "logs"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

# Inicia o Ollama somente se não estiver ativo
$ollamaRunning = Get-Process -Name "ollama" -ErrorAction SilentlyContinue

if (-not $ollamaRunning) {
    Start-Process `
        -FilePath "ollama" `
        -ArgumentList "serve" `
        -WindowStyle Hidden `
        -RedirectStandardOutput "$LogDir\ollama-output.log" `
        -RedirectStandardError "$LogDir\ollama-error.log"
}

Start-Sleep -Seconds 5

# Inicia o MCP local existente
Start-Process `
    -FilePath "python" `
    -ArgumentList "`"$Repo\scripts\mcp-local-autostart.py`" --start" `
    -WorkingDirectory $Repo `
    -WindowStyle Hidden `
    -RedirectStandardOutput "$LogDir\mcp-autostart-output.log" `
    -RedirectStandardError "$LogDir\mcp-autostart-error.log"

# Evita iniciar duas instâncias da IA
$aiRunning = Get-CimInstance Win32_Process |
    Where-Object {
        $_.CommandLine -match "\\.ai\\main\.js"
    }

if (-not $aiRunning) {
    Start-Process `
        -FilePath "node" `
        -ArgumentList "main.js" `
        -WorkingDirectory $AiDir `
        -WindowStyle Hidden `
        -RedirectStandardOutput "$LogDir\local-ai-output.log" `
        -RedirectStandardError "$LogDir\local-ai-error.log"
}