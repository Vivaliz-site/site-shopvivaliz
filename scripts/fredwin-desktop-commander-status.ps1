param()
$ErrorActionPreference = 'Continue'
$TaskName = 'ShopVivaliz Desktop Commander 24h'
if (-not $env:USERPROFILE) {
    try {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath).ProfileImagePath)
    } catch { }
}
if (-not $env:APPDATA -and $env:USERPROFILE) { $env:APPDATA = Join-Path $env:USERPROFILE 'AppData\Roaming' }
if (-not $env:LOCALAPPDATA -and $env:USERPROFILE) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
$DeviceFile = if ($env:USERPROFILE) { Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json' } else { $null }
$LogFile = 'C:\site-shopvivaliz\logs\desktop-commander-remote.log'
$CooldownFile = 'C:\site-shopvivaliz\logs\desktop-commander-auth-required.cooldown'

Write-Output ('USER=' + [System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
Write-Output ('USERPROFILE=' + $env:USERPROFILE)
Write-Output ('APPDATA=' + $env:APPDATA)
Write-Output ('LOCALAPPDATA=' + $env:LOCALAPPDATA)
Write-Output ('NODE=' + ((Get-Command node -ErrorAction SilentlyContinue).Source))
Write-Output ('NPX=' + ((Get-Command npx -ErrorAction SilentlyContinue).Source))
Write-Output ('DEVICE_STATE_EXISTS=' + [bool]($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)))
if ($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)) {
    $item = Get-Item -LiteralPath $DeviceFile
    Write-Output ('DEVICE_STATE_MTIME=' + $item.LastWriteTimeUtc.ToString('o'))
}
$processes = @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object { [string]$_.CommandLine -match 'desktop-commander.*remote' })
Write-Output ('REMOTE_AGENT_COUNT=' + $processes.Count)
if ($processes.Count -gt 0) { Write-Output ('REMOTE_AGENT_PIDS=' + (($processes.ProcessId | Sort-Object) -join ',')) }
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Output ('TASK_EXISTS=' + [bool]$task)
if ($task) {
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Output ('TASK_STATE=' + $task.State)
    Write-Output ('TASK_LAST_RESULT=' + $info.LastTaskResult)
    Write-Output ('TASK_LAST_RUN=' + $info.LastRunTime.ToUniversalTime().ToString('o'))
    Write-Output ('TASK_NEXT_RUN=' + $info.NextRunTime.ToUniversalTime().ToString('o'))
}
$authRequired = (Test-Path -LiteralPath $CooldownFile)
if (-not $authRequired -and (Test-Path -LiteralPath $LogFile)) {
    $tail = Get-Content -LiteralPath $LogFile -Tail 80 -ErrorAction SilentlyContinue
    $authRequired = [bool]($tail -match 'Please complete authentication|Starting device authorization flow|device code|AUTH_REQUIRED')
}
Write-Output ('AUTH_REQUIRED=' + $authRequired)
