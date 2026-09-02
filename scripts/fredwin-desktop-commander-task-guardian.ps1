$ErrorActionPreference = 'Stop'
$TaskName = 'ShopVivaliz Desktop Commander 24h'

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    Write-Output 'TASK_GUARDIAN_TARGET_MISSING=true'
    exit 2
}

if ($task.State -eq 'Disabled') {
    Enable-ScheduledTask -TaskName $TaskName | Out-Null
    Write-Output 'TASK_GUARDIAN_REENABLED=true'
    Start-ScheduledTask -TaskName $TaskName
    Write-Output 'TASK_GUARDIAN_STARTED=true'
    exit 0
}

Write-Output 'TASK_GUARDIAN_HEALTHY=true'
