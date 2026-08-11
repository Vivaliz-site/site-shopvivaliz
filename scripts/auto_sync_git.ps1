$ErrorActionPreference = "Stop"

param(
    [int]$Interval = 5
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$TargetScript = Join-Path $ScriptDir "local-auto-sync.ps1"

if (!(Test-Path $TargetScript)) {
    Write-Error "Missing target script: $TargetScript"
}

# Legacy task compatibility: keep accepting -Interval even though the
# canonical script now owns its own timing and logging behavior.
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $TargetScript
exit $LASTEXITCODE
