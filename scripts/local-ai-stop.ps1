$ErrorActionPreference = "SilentlyContinue"

$Repo = "C:\site-shopvivaliz"
$LogDir = Join-Path $Repo "logs"
$WorkerPidFile = Join-Path $LogDir "local-ai-service.pid"
$ServerPidFile = Join-Path $LogDir "local-ai-server.pid"
$McpPidFile = Join-Path $LogDir "mcp-local.pid"

Write-Host "⏹️ Parando servicos de IA Local..." -ForegroundColor Yellow

# Parar Worker
if (Test-Path $WorkerPidFile) {
    $pidVal = (Get-Content $WorkerPidFile -Raw).Trim()
    if ($pidVal) {
        $proc = Get-Process -Id $pidVal
        if ($proc) {
            Write-Host "• Parando Worker PID $pidVal..." -ForegroundColor Gray
            Stop-Process -Id $pidVal -Force
        }
    }
    Remove-Item $WorkerPidFile -Force
}

# Parar Server
if (Test-Path $ServerPidFile) {
    $pidVal = (Get-Content $ServerPidFile -Raw).Trim()
    if ($pidVal) {
        $proc = Get-Process -Id $pidVal
        if ($proc) {
            Write-Host "• Parando Server PID $pidVal..." -ForegroundColor Gray
            Stop-Process -Id $pidVal -Force
        }
    }
    Remove-Item $ServerPidFile -Force
}

# Parar MCP local
if (Test-Path $McpPidFile) {
    $pidVal = (Get-Content $McpPidFile -Raw).Trim()
    if ($pidVal) {
        $proc = Get-Process -Id $pidVal
        if ($proc) {
            Write-Host "• Parando MCP local PID $pidVal..." -ForegroundColor Gray
            Stop-Process -Id $pidVal -Force
        }
    }
    Remove-Item $McpPidFile -Force
}

Write-Host "✅ Todos os servicos de IA Local foram parados." -ForegroundColor Green
