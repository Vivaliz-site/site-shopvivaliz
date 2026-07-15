$ErrorActionPreference = "Continue"
$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$syncScript = Join-Path $scriptRoot 'local-auto-sync.ps1'

if (-not (Test-Path $syncScript)) {
    Write-Error "Script base nao encontrado: $syncScript"
    exit 1
}

while ($true) {
    & $syncScript
    Start-Sleep -Seconds 1800
}
