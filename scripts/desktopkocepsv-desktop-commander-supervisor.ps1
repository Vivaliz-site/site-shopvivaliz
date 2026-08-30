param(
    [ValidateSet('Ensure','InstallTask','Restart','KillForRecoveryTest','Status')]
    [string]$Mode = 'Ensure'
)
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
$RunnerScript = Join-Path $Repo 'scripts\desktopkocepsv-desktop-commander-runner.ps1'
$StatusScript = Join-Path $Repo 'scripts\desktopkocepsv-desktop-commander-status.ps1'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktopkocepsv-desktop-commander.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$LegacyStartupName = 'desktop-commander-remote.vbs'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$DeviceFile = $null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
}
$MutexName = 'Global\ShopVivalizDesktopCommander-DESKTOP-KOCEPSV'
$OwnerMutex = New-Object System.Threading.Mutex($false, $MutexName)
$OwnerMutexAcquired = $false
try {
    try { $OwnerMutexAcquired = $OwnerMutex.WaitOne(0) }
    catch [System.Threading.AbandonedMutexException] { $OwnerMutexAcquired = $true }
} catch {
    $OwnerMutex.Dispose()
    throw
}
if (-not $OwnerMutexAcquired) {
    Log 'REMOTE_OWNER_CONFLICT mutex already held; refusing concurrent supervisor'
    Write-Output 'REMOTE_OWNER_CONFLICT=true reason=supervisor_mutex_held'
    exit 21
}
function Ensure-ProfileEnvironment {
    if (-not $env:USERPROFILE) {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $profile = (Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables($profile)
    }
    if (-not $env:APPDATA) { $env:APPDATA = Join-Path $env:USERPROFILE 'AppData\Roaming' }
    if (-not $env:LOCALAPPDATA) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
    $env:HOME = $env:USERPROFILE
    $script:DeviceFile = Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json'
}
function Test-DeviceStateNewerThanCooldown {
    if (-not $DeviceFile -or -not (Test-Path -LiteralPath $DeviceFile) -or -not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    return ((Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc -gt (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc)
}
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
function Stop-LauncherTree([int]$ProcessId) {
    try { & taskkill.exe /PID $ProcessId /T /F 2>$null | Out-Null; Log ('Stopped Desktop Commander launcher tree pid=' + $ProcessId) }
    catch { Log ('WARNING stop failed pid=' + $ProcessId) }
}
function Stop-RemoteProcesses {
    foreach ($p in (Get-LauncherRoots (Get-DesktopCommanderRemoteLaunchers))) { Stop-LauncherTree -ProcessId $p.ProcessId }
}
function Test-RecentCooldown {
    if (-not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    if (Test-DeviceStateNewerThanCooldown) { return $false }
    $age = (Get-Date).ToUniversalTime() - (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc
    return ($age.TotalHours -lt 6)
}
function Remove-LegacyStartup {
    $startup = Join-Path $env:APPDATA ('Microsoft\Windows\Start Menu\Programs\Startup\' + $LegacyStartupName)
    if (Test-Path -LiteralPath $startup) {
        Remove-Item -LiteralPath $startup -Force -ErrorAction SilentlyContinue
        Log ('Removed legacy startup ' + $LegacyStartupName)
    }
}
function Ensure-Agent {
    Ensure-ProfileEnvironment
    if (-not (Test-Path -LiteralPath $RunnerScript)) { throw 'sanitized runner not found' }
    if (-not (Test-Path -LiteralPath $DeviceFile)) { Log 'AUTH_REQUIRED device state missing'; Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    if (Test-DeviceStateNewerThanCooldown) {
        Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
        Log 'Cleared stale auth cooldown because device state is newer'
    }
    $canonical = @(Get-CanonicalRemoteLaunchers)
    $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
    if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0) {
        Remove-LegacyStartup
        Write-Output 'REMOTE_AGENT_RUNNING=true'
        return
    }
    if ($canonical.Count -gt 0 -or $noncanonical.Count -gt 0) { Stop-RemoteProcesses; Start-Sleep -Seconds 2 }
    if (Test-RecentCooldown) { Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    $args = @('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$RunnerScript)
    Start-Process -FilePath 'powershell.exe' -ArgumentList $args -WorkingDirectory $Repo -WindowStyle Hidden
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        Start-Sleep -Seconds 1
        if (Test-RecentCooldown) { Stop-RemoteProcesses; Write-Output 'AUTH_REQUIRED=true'; exit 20 }
        $canonical = @(Get-CanonicalRemoteLaunchers)
        $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
        if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0) { break }
    }
    $canonical = @(Get-CanonicalRemoteLaunchers)
    $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
    if ($canonical.Count -ne 1 -or $noncanonical.Count -ne 0) { throw ('singleton convergence failed canonical=' + $canonical.Count + ' noncanonical=' + $noncanonical.Count) }
    Remove-LegacyStartup
    Write-Output 'REMOTE_AGENT_RUNNING=true'
}
function Install-Task {
    Ensure-ProfileEnvironment
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\desktopkocepsv-desktop-commander-supervisor.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps DESKTOP-KOCEPSV Desktop Commander online without interactive logon.' -Force -ErrorAction Stop | Out-Null
    Write-Output 'TASK_INSTALLED=true'
}

try {
    switch ($Mode) {
        'InstallTask' { Install-Task; Stop-RemoteProcesses; Start-Sleep -Seconds 2; Ensure-Agent }
        'Restart' { Stop-RemoteProcesses; Start-Sleep -Seconds 2; Ensure-Agent }
        'KillForRecoveryTest' { Stop-RemoteProcesses; Write-Output 'REMOTE_AGENT_KILLED=true' }
        'Status' { & $StatusScript }
        default { Ensure-Agent }
    }
} finally {
    if ($OwnerMutexAcquired) {
        $OwnerMutex.ReleaseMutex()
        $OwnerMutex.Dispose()
    }
}
