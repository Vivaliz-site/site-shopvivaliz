$ErrorActionPreference = "Stop"

$Repo = "C:\site-shopvivaliz"
$AiDir = Join-Path $Repo ".ai"
$LogDir = Join-Path $Repo "logs"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

$StartupLog = Join-Path $LogDir "local-ai-startup.log"
"=== $(Get-Date -Format o) - Inicio do Startup ===" | Out-File $StartupLog -Append

try {
    # 1. Localizar executáveis
    $OllamaExe = (Get-Command ollama.exe -ErrorAction SilentlyContinue).Source
    if (-not $OllamaExe) {
        $PossiblePaths = @(
            "C:\Users\FRED\AppData\Local\Programs\Ollama\ollama.exe",
            "C:\Program Files\Ollama\ollama.exe"
        )
        foreach ($Path in $PossiblePaths) {
            if (Test-Path $Path) {
                $OllamaExe = $Path
                break
            }
        }
    }

    $PythonExe = (Get-Command python.exe -ErrorAction SilentlyContinue).Source
    $NodeExe = (Get-Command node.exe -ErrorAction SilentlyContinue).Source

    "OLLAMA=$OllamaExe" | Out-File $StartupLog -Append
    "PYTHON=$PythonExe" | Out-File $StartupLog -Append
    "NODE=$NodeExe" | Out-File $StartupLog -Append

    if (-not $OllamaExe) {
        throw "Erro: Ollama nao encontrado em nenhum caminho conhecido."
    }
    if (-not $PythonExe) {
        throw "Erro: Python nao encontrado no PATH."
    }
    if (-not $NodeExe) {
        throw "Erro: Node nao encontrado no PATH."
    }

    # 2. Iniciar Ollama se necessário
    $ollamaRunning = Get-Process -Name "ollama" -ErrorAction SilentlyContinue
    if (-not $ollamaRunning) {
        "Iniciando Ollama serve..." | Out-File $StartupLog -Append
        Start-Process `
          -FilePath $OllamaExe `
          -ArgumentList "serve" `
          -WindowStyle Hidden `
          -RedirectStandardOutput "$LogDir\ollama-output.log" `
          -RedirectStandardError "$LogDir\ollama-error.log"
        Start-Sleep -Seconds 5
    } else {
        "Ollama ja esta rodando." | Out-File $StartupLog -Append
    }

    # 3. Iniciar MCP local usando mcp-local-autostart.py
    $McpAutostart = Join-Path $Repo "scripts\mcp-local-autostart.py"
    if (Test-Path $McpAutostart) {
        "Iniciando MCP Server via autostart..." | Out-File $StartupLog -Append
        Start-Process `
          -FilePath $PythonExe `
          -ArgumentList "`"$McpAutostart`" --start" `
          -WorkingDirectory $Repo `
          -WindowStyle Hidden `
          -RedirectStandardOutput "$LogDir\mcp-autostart-output.log" `
          -RedirectStandardError "$LogDir\mcp-autostart-error.log"
        Start-Sleep -Seconds 3
    } else {
        throw "Erro: mcp-local-autostart.py nao encontrado."
    }

    # 4. Remover PIDs stale antigos de Worker e Server
    $WorkerPidFile = Join-Path $LogDir "local-ai-service.pid"
    $ServerPidFile = Join-Path $LogDir "local-ai-server.pid"

    foreach ($PidFile in @($WorkerPidFile, $ServerPidFile)) {
        if (Test-Path $PidFile) {
            try {
                $PidVal = [int](Get-Content $PidFile -Raw).Trim()
                if ($PidVal) {
                    $Proc = Get-Process -Id $PidVal -ErrorAction SilentlyContinue
                    if ($Proc) {
                        "Processo antigo PID $PidVal ativo. Parando..." | Out-File $StartupLog -Append
                        Stop-Process -Id $PidVal -Force
                    }
                }
                Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
            } catch {
                Remove-Item $PidFile -Force -ErrorAction SilentlyContinue
            }
        }
    }

    # 5. Iniciar Worker e Server persistentes
    $WorkerJs = Join-Path $AiDir "worker.js"
    $ServerJs = Join-Path $AiDir "server.js"

    if (-not (Test-Path $WorkerJs)) {
        throw "Erro: worker.js nao encontrado em $WorkerJs"
    }
    if (-not (Test-Path $ServerJs)) {
        throw "Erro: server.js nao encontrado em $ServerJs"
    }

    "Iniciando Worker de IA Local..." | Out-File $StartupLog -Append
    $WorkerProc = Start-Process `
      -FilePath $NodeExe `
      -ArgumentList "`"$WorkerJs`"" `
      -WorkingDirectory $AiDir `
      -WindowStyle Hidden `
      -PassThru `
      -RedirectStandardOutput "$LogDir\local-ai-worker-output.log" `
      -RedirectStandardError "$LogDir\local-ai-worker-error.log"

    "Iniciando API/Dashboard Server..." | Out-File $StartupLog -Append
    $ServerProc = Start-Process `
      -FilePath $NodeExe `
      -ArgumentList "`"$ServerJs`"" `
      -WorkingDirectory $AiDir `
      -WindowStyle Hidden `
      -PassThru `
      -RedirectStandardOutput "$LogDir\local-ai-server-output.log" `
      -RedirectStandardError "$LogDir\local-ai-server-error.log"

    # 6. Validar se continuam ativos após 5 segundos
    Start-Sleep -Seconds 5

    if ($WorkerProc.HasExited) {
        throw "Erro: O Worker de IA Local encerrou prematuramente."
    }
    if ($ServerProc.HasExited) {
        throw "Erro: O Servidor API/Dashboard encerrou prematuramente."
    }

    "SUCESSO: Todos os servicos de IA Local iniciados com sucesso!" | Out-File $StartupLog -Append
    "=== $(Get-Date -Format o) - Fim do Startup ===" | Out-File $StartupLog -Append
    exit 0

} catch {
    $ErrMessage = $_.Exception.Message
    "FALHA: $ErrMessage" | Out-File $StartupLog -Append
    "=== $(Get-Date -Format o) - Fim com Erro ===" | Out-File $StartupLog -Append
    Write-Error $ErrMessage
    exit 1
}