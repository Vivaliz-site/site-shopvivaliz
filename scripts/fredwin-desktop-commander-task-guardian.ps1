$ErrorActionPreference = 'Stop'
$TaskName = 'ShopVivaliz Desktop Commander 24h'
$RuntimeJanitorScript = 'C:\site-shopvivaliz\scripts\fredwin-runtime-janitor.ps1'
$ProtectedTaskNames = @(
    'ShopVivaliz Runtime Janitor',
    'ShopVivaliz AI Session Orphan Guard'
)
$missingPrimary = $false
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $task) {
    Write-Output 'TASK_GUARDIAN_TARGET_MISSING=true'
    $missingPrimary = $true
} elseif ($task.State -eq 'Disabled') {
    Enable-ScheduledTask -TaskName $TaskName | Out-Null
    Write-Output 'TASK_GUARDIAN_REENABLED=true'
    Start-ScheduledTask -TaskName $TaskName
    Write-Output 'TASK_GUARDIAN_STARTED=true'
} else {
    Write-Output 'TASK_GUARDIAN_HEALTHY=true'
}

foreach ($name in $ProtectedTaskNames) {
    $protected = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if (-not $protected -and $name -eq 'ShopVivaliz Runtime Janitor' -and (Test-Path -LiteralPath $RuntimeJanitorScript)) {
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $RuntimeJanitorScript -InstallTask | Out-Null
        $protected = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($protected) { Write-Output 'RUNTIME_JANITOR_RECREATED=true' }
    }
    if ($protected -and $protected.State -eq 'Disabled') {
        Enable-ScheduledTask -TaskName $name | Out-Null
        Start-ScheduledTask -TaskName $name
        Write-Output ('TASK_GUARDIAN_REENABLED_PROTECTED=' + $name)
    }
}
if ($missingPrimary) { exit 2 }
exit 0
