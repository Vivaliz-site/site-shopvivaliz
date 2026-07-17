$ErrorActionPreference = "SilentlyContinue"

$Repo = "C:\site-shopvivaliz"
$LogDir = Join-Path $Repo "logs"
$WorkerPidFile = Join-Path $LogDir "local-ai-service.pid"
$ServerPidFile = Join-Path $LogDir "local-ai-server.pid"
$HeartbeatFile = Join-Path $LogDir "local-ai-heartbeat.json"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📊 STATUS DO SISTEMA DE IA LOCAL - SHOPVIVALIZ" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan

# 1. Windows Scheduled Task
$Task = Get-ScheduledTask -TaskName "ShopVivaliz Local AI"
$TaskInfo = Get-ScheduledTaskInfo -TaskName "ShopVivaliz Local AI"
if ($Task) {
    Write-Host "• Tarefa Agendada: " -NoNewline
    Write-Host $Task.State -ForegroundColor Green -NoNewline
    Write-Host " (Last Result: $($TaskInfo.LastTaskResult), Run: $($TaskInfo.LastRunTime))"
} else {
    Write-Host "• Tarefa Agendada: NAO ENCONTRADA" -ForegroundColor Red
}

# 2. Ollama Status
$ollamaProc = Get-Process -Name "ollama"
if ($ollamaProc) {
    Write-Host "• Processo Ollama: ATIVO (PID: $($ollamaProc.Id))" -ForegroundColor Green
} else {
    Write-Host "• Processo Ollama: INATIVO" -ForegroundColor Red
}

# 3. Modelos Instalados no Ollama
try {
    $models = Invoke-RestMethod -Uri "http://127.0.0.1:11434/api/tags" -TimeoutSec 3
    $modelNames = ($models.models | ForEach-Object { $_.name }) -join ", "
    Write-Host "• Modelos no Ollama: $modelNames" -ForegroundColor Gray
} catch {
    Write-Host "• Conexao com Ollama API: FALHOU" -ForegroundColor Red
}

# 4. MCP Server Status
try {
    $mcpHealth = Invoke-RestMethod -Uri "http://127.0.0.1:5555/health" -TimeoutSec 3
    Write-Host "• MCP Health: " -NoNewline
    Write-Host $mcpHealth.status -ForegroundColor Green -NoNewline
    Write-Host " (Port: 5555, Env: $($mcpHealth.environment))"
} catch {
    Write-Host "• MCP Health: OFFLINE" -ForegroundColor Red
}

# 5. Worker PID
if (Test-Path $WorkerPidFile) {
    $workerPid = (Get-Content $WorkerPidFile -Raw).Trim()
    $proc = Get-Process -Id $workerPid
    if ($proc) {
        Write-Host "• Worker Process: ATIVO (PID: $workerPid)" -ForegroundColor Green
    } else {
        Write-Host "• Worker Process: INATIVO (PID stale: $workerPid)" -ForegroundColor Yellow
    }
} else {
    Write-Host "• Worker Process: OFFLINE" -ForegroundColor Red
}

# 6. Server PID
if (Test-Path $ServerPidFile) {
    $serverPid = (Get-Content $ServerPidFile -Raw).Trim()
    $proc = Get-Process -Id $serverPid
    if ($proc) {
        Write-Host "• Server Process: ATIVO (PID: $serverPid)" -ForegroundColor Green
    } else {
        Write-Host "• Server Process: INATIVO (PID stale: $serverPid)" -ForegroundColor Yellow
    }
} else {
    Write-Host "• Server Process: OFFLINE" -ForegroundColor Red
}

# 7. Heartbeat
if (Test-Path $HeartbeatFile) {
    try {
        $heartbeat = Get-Content $HeartbeatFile -Raw | ConvertFrom-Json
        Write-Host "• Heartbeat Time: $($heartbeat.timestamp)" -ForegroundColor Gray
        Write-Host "• Fila de Tarefas: $($heartbeat.queue_pending) pendentes, $($heartbeat.queue_running) executando" -ForegroundColor Cyan
        if ($heartbeat.last_task_id) {
            Write-Host "• Ultima Tarefa: $($heartbeat.last_task_id)" -ForegroundColor Gray
        }
        if ($heartbeat.last_error) {
            Write-Host "• Ultimo Erro: $($heartbeat.last_error)" -ForegroundColor Red
        }
    } catch {
        Write-Host "• Heartbeat File: Erro na leitura" -ForegroundColor Yellow
    }
} else {
    Write-Host "• Heartbeat File: NAO LOCALIZADO" -ForegroundColor Red
}

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
