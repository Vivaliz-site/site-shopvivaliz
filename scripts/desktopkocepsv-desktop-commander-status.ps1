param()
$ErrorActionPreference = 'Continue'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'

if (-not $env:USERPROFILE) {
    try {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath).ProfileImagePath)
    } catch { }
}
if (-not $env:LOCALAPPDATA -and $env:USERPROFILE) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
if (-not $env:TEMP -and $env:LOCALAPPDATA) { $env:TEMP = Join-Path $env:LOCALAPPDATA 'Temp' }

$InstallRoot = if ($env:LOCALAPPDATA) { Join-Path $env:LOCALAPPDATA 'ShopVivaliz\DesktopCommander' } else { $null }
$LogDir = if ($InstallRoot) { Join-Path $InstallRoot 'logs' } else { $null }
$CooldownFile = if ($LogDir) { Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown' } else { $null }
$ConnectedMarker = if ($LogDir) { Join-Path $LogDir 'desktopkocepsv-desktop-commander-provider-connected.marker' } else { $null }
$DeviceFile = if ($env:USERPROFILE) { Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json' } else { $null }

function Test-DeviceStateNewerThanCooldown {
    if (-not $DeviceFile -or -not (Test-Path -LiteralPath $DeviceFile) -or -not $CooldownFile -or -not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    return ((Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc -gt (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc)
}

function Get-DesktopCommanderRemoteLaunchers {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.Name) -in @('node.exe','cmd.exe') -and
        ([string]$_.CommandLine) -match '(@wonderwhy-er/desktop-commander@[^ ]+|@wonderwhy-er[\\/]desktop-commander[\\/]dist[\\/]index\.js).*\bremote\b'
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
        $command = [string]$_.CommandLine
        ($command -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session') -or
        ($command -match '@wonderwhy-er[\\/]desktop-commander[\\/]dist[\\/]index\.js.*\bremote\b.*--persist-session')
    })
    return @(Get-LauncherRoots $matches)
}

function Get-NonCanonicalRemoteLaunchers {
    $all = @(Get-DesktopCommanderRemoteLaunchers)
    $canonicalProcesses = @($all | Where-Object {
        $command = [string]$_.CommandLine
        ($command -match '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session') -or
        ($command -match '@wonderwhy-er[\\/]desktop-commander[\\/]dist[\\/]index\.js.*\bremote\b.*--persist-session')
    })
    $canonicalIds = @($canonicalProcesses.ProcessId)
    $noncanonical = @($all | Where-Object { $canonicalIds -notcontains $_.ProcessId })
    return @(Get-LauncherRoots $noncanonical)
}

function Test-PrivateAcl([string]$Path) {
    if (-not $Path -or -not (Test-Path -LiteralPath $Path)) { return $false }
    $allowed = @(
        [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value,
        'S-1-5-18',
        'S-1-5-32-544'
    )
    try {
        foreach ($entry in (Get-Acl -LiteralPath $Path).Access) {
            if ($entry.AccessControlType -ne [System.Security.AccessControl.AccessControlType]::Allow) { continue }
            $sid = $entry.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value
            if ($allowed -notcontains $sid) { return $false }
        }
        return $true
    }
    catch { return $false }
}

$canonical = @(Get-CanonicalRemoteLaunchers)
$noncanonical = @(Get-NonCanonicalRemoteLaunchers)
$deviceExists = [bool]($DeviceFile -and (Test-Path -LiteralPath $DeviceFile))
$markerExists = [bool]($ConnectedMarker -and (Test-Path -LiteralPath $ConnectedMarker))
$markerAge = if ($markerExists) { [math]::Round((((Get-Date).ToUniversalTime() - (Get-Item -LiteralPath $ConnectedMarker).LastWriteTimeUtc).TotalSeconds), 1) } else { -1 }

Write-Output ('DEVICE_STATE_EXISTS=' + $deviceExists)
if ($deviceExists) { Write-Output ('DEVICE_STATE_LAST_WRITE_UTC=' + (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc.ToString('o')) }
Write-Output ('CANONICAL_AGENT_COUNT=' + $canonical.Count)
Write-Output ('NONCANONICAL_AGENT_COUNT=' + $noncanonical.Count)
Write-Output ('MONITOR_MARKER_EXISTS=' + $markerExists)
Write-Output ('MONITOR_MARKER_AGE_SECONDS=' + $markerAge)
Write-Output ('MONITOR_HEALTHY=' + [bool]($markerExists -and $markerAge -le 150))
Write-Output ('INSTALL_ROOT_EXISTS=' + [bool]($InstallRoot -and (Test-Path -LiteralPath $InstallRoot)))
Write-Output ('INSTALL_ROOT_ACL_PRIVATE=' + (Test-PrivateAcl $InstallRoot))
Write-Output ('DEVICE_STATE_ACL_PRIVATE=' + (Test-PrivateAcl $DeviceFile))

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
Write-Output ('TASK_EXISTS=' + [bool]$task)
if ($task) {
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction SilentlyContinue
    $actionSecure = [bool]($InstallRoot -and ([string]$task.Actions[0].Arguments).Contains($InstallRoot))
    Write-Output ('TASK_STATE=' + $task.State)
    Write-Output ('TASK_LAST_RESULT=' + $info.LastTaskResult)
    Write-Output ('TASK_LOGON_TYPE=' + $task.Principal.LogonType)
    Write-Output ('TASK_RUN_LEVEL=' + $task.Principal.RunLevel)
    Write-Output ('TASK_ACTION_SECURE=' + $actionSecure)
    Write-Output ('TASK_HIDDEN=' + $task.Settings.Hidden)
    Write-Output ('TASK_WAKE_TO_RUN=' + $task.Settings.WakeToRun)
}

$authRequired = [bool]($CooldownFile -and (Test-Path -LiteralPath $CooldownFile) -and -not (Test-DeviceStateNewerThanCooldown))
Write-Output ('AUTH_REQUIRED=' + $authRequired)
$rawCaptureCount = 0
if ($env:TEMP -and (Test-Path -LiteralPath $env:TEMP)) {
    $rawCaptureCount = @(Get-ChildItem -LiteralPath $env:TEMP -Filter 'shopvivaliz-dc-*' -File -ErrorAction SilentlyContinue | Where-Object { $_.Name -match '^shopvivaliz-dc-[0-9a-f]{32}\.(out|err)$' }).Count
}
Write-Output ('LEGACY_RAW_CAPTURE_COUNT=' + $rawCaptureCount)

$taskLog = Get-WinEvent -ListLog 'Microsoft-Windows-TaskScheduler/Operational' -ErrorAction SilentlyContinue
Write-Output ('TASK_OPERATIONAL_LOG_ENABLED=' + [bool]($taskLog -and $taskLog.IsEnabled))
