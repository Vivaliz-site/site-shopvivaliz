$ErrorActionPreference = 'Stop'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
$Supervisor = Join-Path $PSScriptRoot 'desktopkocepsv-desktop-commander-supervisor.ps1'
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    Write-Output 'TASK_GUARDIAN_TARGET_MISSING=true'
    & powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $Supervisor -Mode InstallTask
    exit $LASTEXITCODE
}
if ($task.State -eq 'Disabled') {
    Enable-ScheduledTask -TaskName $TaskName | Out-Null
    Start-ScheduledTask -TaskName $TaskName
    Write-Output 'TASK_GUARDIAN_REENABLED=true'
    Write-Output 'TASK_GUARDIAN_STARTED=true'
} else {
    Write-Output 'TASK_GUARDIAN_HEALTHY=true'
}
exit 0
