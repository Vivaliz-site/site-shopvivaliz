$ErrorActionPreference = "Stop"

Write-Host "ShopVivaliz MCP setup" -ForegroundColor Cyan

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot ".")).Path
$bridgeScript = Join-Path $repoRoot "scripts\codex-mesh-bridge.py"

if (-not (Test-Path $bridgeScript)) {
    Write-Host "Bridge nao encontrado: $bridgeScript" -ForegroundColor Red
    exit 1
}

$pythonCheck = Get-Command python -ErrorAction SilentlyContinue
if (-not $pythonCheck) {
    Write-Host "Python nao encontrado no PATH." -ForegroundColor Red
    exit 1
}

$claudeConfigDir = Join-Path $env:APPDATA "Claude"
$claudeConfigPath = Join-Path $claudeConfigDir "claude_desktop_config.json"
New-Item -ItemType Directory -Force -Path $claudeConfigDir | Out-Null

if (Test-Path $claudeConfigPath) {
    $rawConfig = Get-Content $claudeConfigPath -Raw -Encoding UTF8
    if ([string]::IsNullOrWhiteSpace($rawConfig)) {
        $config = @{}
    } else {
        $config = $rawConfig | ConvertFrom-Json -AsHashtable
    }
} else {
    $config = @{}
}

if (-not $config.ContainsKey("mcpServers")) {
    $config["mcpServers"] = @{}
}

$config["mcpServers"]["shopvivaliz-codex-bridge"] = @{
    command = "python"
    args = @($bridgeScript)
}

$json = $config | ConvertTo-Json -Depth 20
[System.IO.File]::WriteAllText($claudeConfigPath, $json + [Environment]::NewLine, [System.Text.Encoding]::UTF8)

Write-Host ""
Write-Host "Configuracao atualizada com sucesso." -ForegroundColor Green
Write-Host "Claude config: $claudeConfigPath"
Write-Host "Bridge script: $bridgeScript"
Write-Host ""
Write-Host "Servidor configurado: shopvivaliz-codex-bridge" -ForegroundColor Yellow
Write-Host "Reabra o Claude Desktop para recarregar a configuracao." -ForegroundColor Yellow
