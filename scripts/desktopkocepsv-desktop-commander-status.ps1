param()
$ErrorActionPreference = 'Continue'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
$Repo = 'C:\site-shopvivaliz'
$CooldownFile = Join-Path $Repo 'logs\desktopkocepsv-desktop-commander-auth-required.cooldown'
if (-not $env:USERPROFILE) {
    try {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath).ProfileImagePath)
    } catch { }
}
$DeviceFile = if ($env:USERPROFILE) { Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json' } else { $null }
function Get-DesktopCommanderRemoteLaunchers {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.CommandLine) -match '@wonderwhy-er/desktop-commander@[^ ]+.*\bremote\b'
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
        ([string]$_.CommandLine) -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
    return @(Get-LauncherRoots $matches)
}
function Get-NonCanonicalRemoteLaunchers {
    $all = @(Get-DesktopCommanderRemoteLaunchers)
    $canonicalProcesses = @($all | Where-Object {
        ([string]$_.CommandLine) -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
    $canonicalIds = @($canonicalProcesses.ProcessId)
    $noncanonical = @($all | Where-Object { $canonicalIds -notcontains $_.ProcessId })
    return @(Get-LauncherRoots $noncanonical)
}
$canonical = Get-CanonicalRemoteLaunchers
$noncanonical = Get-NonCanonicalRemoteLaunchers
Write-Output ('DEVICE_STATE_EXISTS=' + [bool]($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)))
Write-Output ('CANONICAL_AGENT_COUNT=' + $canonical.Count)
Write-Output ('NONCANONICAL_AGENT_COUNT=' + $noncanonical.Count)
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Output ('TASK_EXISTS=' + [bool]$task)
if ($task) {
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Output ('TASK_STATE=' + $task.State)
    Write-Output ('TASK_LAST_RESULT=' + $info.LastTaskResult)
    Write-Output ('TASK_LOGON_TYPE=' + $task.Principal.LogonType)
    Write-Output ('TASK_RUN_LEVEL=' + $task.Principal.RunLevel)
}
Write-Output ('AUTH_REQUIRED=' + (Test-Path -LiteralPath $CooldownFile))
