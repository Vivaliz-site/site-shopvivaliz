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
$CooldownFile = 'C:\site-shopvivaliz\logs\desktop-commander-auth-required.cooldown'

function Get-DesktopCommanderRemoteLaunchers {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $cmd = [string]$_.CommandLine
        $cmd -match '@wonderwhy-er/desktop-commander@[^ ]+.*\bremote\b'
    })
}
function Get-LauncherRoots([object[]]$Launchers) {
    $items = @($Launchers)
    if ($items.Count -le 1) { return $items }
    $ids = @($items | ForEach-Object { [int]$_.ProcessId })
    return @($items | Where-Object { $ids -notcontains [int]$_.ParentProcessId })
}
function Get-CanonicalRemoteLaunchers {
    $matches = @(Get-DesktopCommanderRemoteLaunchers | Where-Object {
        $cmd = [string]$_.CommandLine
        $cmd -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
    return @(Get-LauncherRoots $matches)
}
function Get-NonCanonicalRemoteLaunchers {
    $all = @(Get-DesktopCommanderRemoteLaunchers)
    $canonicalProcesses = @($all | Where-Object {
        $cmd = [string]$_.CommandLine
        $cmd -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
    $canonicalIds = @($canonicalProcesses.ProcessId)
    $noncanonical = @($all | Where-Object { $canonicalIds -notcontains $_.ProcessId })
    return @(Get-LauncherRoots $noncanonical)
}

Write-Output ('USER=' + [System.Security.Principal.WindowsIdentity]::GetCurrent().Name)
Write-Output ('USERPROFILE=' + $env:USERPROFILE)
Write-Output ('APPDATA=' + $env:APPDATA)
Write-Output ('LOCALAPPDATA=' + $env:LOCALAPPDATA)
Write-Output ('DEVICE_STATE_EXISTS=' + [bool]($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)))
if ($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)) {
    $item = Get-Item -LiteralPath $DeviceFile
    Write-Output ('DEVICE_STATE_MTIME=' + $item.LastWriteTimeUtc.ToString('o'))
}
$canonical = @(Get-CanonicalRemoteLaunchers)
$noncanonical = @(Get-NonCanonicalRemoteLaunchers)
Write-Output ('CANONICAL_AGENT_COUNT=' + $canonical.Count)
Write-Output ('NONCANONICAL_AGENT_COUNT=' + $noncanonical.Count)
if ($canonical.Count -gt 0) { Write-Output ('CANONICAL_AGENT_PIDS=' + (($canonical.ProcessId | Sort-Object) -join ',')) }
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Output ('TASK_EXISTS=' + [bool]$task)
if ($task) {
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Output ('TASK_STATE=' + $task.State)
    Write-Output ('TASK_LAST_RESULT=' + $info.LastTaskResult)
    Write-Output ('TASK_LAST_RUN=' + $info.LastRunTime.ToUniversalTime().ToString('o'))
    Write-Output ('TASK_NEXT_RUN=' + $info.NextRunTime.ToUniversalTime().ToString('o'))
    Write-Output ('TASK_LOGON_TYPE=' + $task.Principal.LogonType)
    Write-Output ('TASK_RUN_LEVEL=' + $task.Principal.RunLevel)
}
$authRequired = (Test-Path -LiteralPath $CooldownFile)
Write-Output ('AUTH_REQUIRED=' + $authRequired)
