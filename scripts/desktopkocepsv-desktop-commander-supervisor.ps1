param(
    [ValidateSet('Ensure','InstallTask','Restart','KillForRecoveryTest','Status')]
    [string]$Mode = 'Ensure'
)
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h'
$LegacyStartupName = 'desktop-commander-remote.vbs'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$MarkerStaleSeconds = 240
$MaxLogBytes = 5MB
$DeviceFile = $null

function Ensure-ProfileEnvironment {
    if (-not $env:USERPROFILE) {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $profile = (Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables($profile)
    }
    if (-not $env:APPDATA) { $env:APPDATA = Join-Path $env:USERPROFILE 'AppData\Roaming' }
    if (-not $env:LOCALAPPDATA) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
    if (-not $env:TEMP) { $env:TEMP = Join-Path $env:LOCALAPPDATA 'Temp' }
    $env:HOME = $env:USERPROFILE
    $script:DeviceFile = Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json'
}

Ensure-ProfileEnvironment
$InstallRoot = Join-Path $env:LOCALAPPDATA 'ShopVivaliz\DesktopCommander'
$LogDir = Join-Path $InstallRoot 'logs'
$RunnerScript = Join-Path $PSScriptRoot 'desktopkocepsv-desktop-commander-runner.ps1'
$StatusScript = Join-Path $PSScriptRoot 'desktopkocepsv-desktop-commander-status.ps1'
$SupervisorLog = Join-Path $LogDir 'desktopkocepsv-desktop-commander.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktopkocepsv-desktop-commander-provider-connected.marker'
$WindowsPowerShell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $mutex = New-Object System.Threading.Mutex($false, 'Local\ShopVivalizDesktopCommanderLog')
    $locked = $false
    try {
        try { $locked = $mutex.WaitOne(5000) } catch [System.Threading.AbandonedMutexException] { $locked = $true }
        if (-not $locked) { return }
        if ((Test-Path -LiteralPath $SupervisorLog) -and (Get-Item -LiteralPath $SupervisorLog).Length -ge $MaxLogBytes) {
            $rotated = $SupervisorLog + '.1'
            Remove-Item -LiteralPath $rotated -Force -ErrorAction SilentlyContinue
            Move-Item -LiteralPath $SupervisorLog -Destination $rotated -Force
        }
        $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
    }
    finally {
        if ($locked) { try { $mutex.ReleaseMutex() } catch { } }
        $mutex.Dispose()
    }
}

$OwnerMutexName = 'Global\ShopVivalizDesktopCommander-DESKTOP-KOCEPSV'
function Enter-OwnerMutex {
    $mutex = New-Object System.Threading.Mutex($false, $OwnerMutexName)
    $acquired = $false
    try {
        try { $acquired = $mutex.WaitOne(0) } catch [System.Threading.AbandonedMutexException] { $acquired = $true }
        if (-not $acquired) {
            Log 'REMOTE_OWNER_CONFLICT mutex already held; refusing concurrent supervisor'
            Write-Output 'REMOTE_OWNER_CONFLICT=true reason=supervisor_mutex_held'
            $mutex.Dispose()
            exit 21
        }
        return $mutex
    }
    catch {
        if (-not $acquired) { $mutex.Dispose() }
        throw
    }
}

function Exit-OwnerMutex([System.Threading.Mutex]$Mutex) {
    if (-not $Mutex) { return }
    try { $Mutex.ReleaseMutex() } finally { $Mutex.Dispose() }
}

function Set-PrivateAcl([string]$Path, [bool]$IsFile = $false) {
    $owner = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    $allowed = @(
        $owner,
        (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-18')),
        (New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-544'))
    )
    if ($IsFile) {
        $security = New-Object System.Security.AccessControl.FileSecurity
        $inheritance = [System.Security.AccessControl.InheritanceFlags]::None
    }
    else {
        $security = New-Object System.Security.AccessControl.DirectorySecurity
        $inheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    }
    $security.SetOwner($owner)
    $security.SetAccessRuleProtection($true, $false)
    foreach ($sid in $allowed) {
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            $sid,
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
        [void]$security.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $Path -AclObject $security
}

function Protect-DeviceState {
    $deviceDir = Split-Path -Parent $DeviceFile
    if (Test-Path -LiteralPath $deviceDir) { Set-PrivateAcl -Path $deviceDir }
    if (Test-Path -LiteralPath $DeviceFile) { Set-PrivateAcl -Path $DeviceFile -IsFile $true }
}

function Deploy-OperationalFiles {
    New-Item -ItemType Directory -Force -Path $InstallRoot | Out-Null
    $files = @(
        'desktopkocepsv-desktop-commander-supervisor.ps1',
        'desktopkocepsv-desktop-commander-runner.ps1',
        'desktopkocepsv-desktop-commander-status.ps1',
        'patch-desktop-commander-session-persistence.mjs'
    )
    foreach ($name in $files) {
        $source = Join-Path $PSScriptRoot $name
        $destination = Join-Path $InstallRoot $name
        if (-not (Test-Path -LiteralPath $source)) { throw "operational source missing: $name" }
        if ([System.IO.Path]::GetFullPath($source) -ne [System.IO.Path]::GetFullPath($destination)) {
            $temporary = $destination + '.new'
            Copy-Item -LiteralPath $source -Destination $temporary -Force
            Move-Item -LiteralPath $temporary -Destination $destination -Force
        }
        if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash -ne (Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash) {
            throw "operational copy hash mismatch: $name"
        }
    }
    Set-PrivateAcl -Path $InstallRoot
    Set-PrivateAcl -Path $LogDir
    foreach ($name in $files) { Set-PrivateAcl -Path (Join-Path $InstallRoot $name) -IsFile $true }
    Protect-DeviceState
    Log 'Operational files deployed with private ACL'
    return (Join-Path $InstallRoot 'desktopkocepsv-desktop-commander-supervisor.ps1')
}

function Test-DeviceStateNewerThanCooldown {
    if (-not $DeviceFile -or -not (Test-Path -LiteralPath $DeviceFile) -or -not (Test-Path -LiteralPath $CooldownFile)) { return $false }
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

function Test-LauncherOwnedByRunner([object]$Launcher) {
    if (-not $Launcher) { return $false }
    $snapshot = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue)
    $byPid = @{}
    foreach ($p in $snapshot) { $byPid[[int]$p.ProcessId] = $p }
    $parentPid = [int]$Launcher.ParentProcessId
    for ($depth = 0; $depth -lt 12 -and $parentPid -gt 0; $depth++) {
        if (-not $byPid.ContainsKey($parentPid)) { break }
        $ancestor = $byPid[$parentPid]
        if ([string]$ancestor.CommandLine -match 'desktopkocepsv-desktop-commander-runner\.ps1') { return $true }
        $parentPid = [int]$ancestor.ParentProcessId
    }
    return $false
}

function Remove-DuplicateCanonicalLaunchers([object[]]$Canonical, [object[]]$NonCanonical) {
    $canonicalItems = @($Canonical)
    $managed = @($canonicalItems | Where-Object { Test-LauncherOwnedByRunner $_ })
    if ($managed.Count -ne 1) { return $false }
    $keepPid = [int]$managed[0].ProcessId
    foreach ($p in @($canonicalItems | Where-Object { [int]$_.ProcessId -ne $keepPid })) {
        Stop-LauncherTree -ProcessId $p.ProcessId
        Log ('Duplicate canonical launcher removed pid=' + $p.ProcessId + ' kept_managed_pid=' + $keepPid)
    }
    foreach ($p in @($NonCanonical)) {
        Stop-LauncherTree -ProcessId $p.ProcessId
        Log ('Noncanonical launcher removed pid=' + $p.ProcessId + ' kept_managed_pid=' + $keepPid)
    }
    return $true
}

function Get-MarkerAgeSeconds {
    try {
        $marker = Get-Item -LiteralPath $ConnectedMarker -ErrorAction Stop
        return (((Get-Date).ToUniversalTime() - $marker.LastWriteTimeUtc).TotalSeconds)
    } catch { return [double]::PositiveInfinity }
}

function Get-LauncherAgeSeconds([object]$Launcher) {
    try { return (((Get-Date) - [datetime]$Launcher.CreationDate).TotalSeconds) } catch { return [double]::PositiveInfinity }
}

function Stop-LauncherTree([int]$ProcessId) {
    try {
        & taskkill.exe /PID $ProcessId /T /F 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "taskkill exit $LASTEXITCODE" }
        Log ('Stopped Desktop Commander launcher tree pid=' + $ProcessId)
    }
    catch { Log ('WARNING stop failed pid=' + $ProcessId + ' reason=' + $_.Exception.Message) }
}

function Stop-RemoteProcesses {
    foreach ($process in (Get-LauncherRoots (Get-DesktopCommanderRemoteLaunchers))) { Stop-LauncherTree -ProcessId $process.ProcessId }
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

function Remove-LegacyRawCaptures {
    $tempRoot = [System.IO.Path]::GetFullPath($env:TEMP).TrimEnd('\')
    $removed = 0
    foreach ($file in (Get-ChildItem -LiteralPath $tempRoot -Filter 'shopvivaliz-dc-*' -File -ErrorAction SilentlyContinue)) {
        if ($file.DirectoryName.TrimEnd('\') -eq $tempRoot -and $file.Name -match '^shopvivaliz-dc-[0-9a-f]{32}\.(out|err)$') {
            Remove-Item -LiteralPath $file.FullName -Force -ErrorAction Stop
            $removed++
        }
    }
    Log ('Legacy raw provider captures removed count=' + $removed)
    return $removed
}

function Wait-AgentConvergence([int]$TimeoutSeconds = 60) {
    for ($attempt = 0; $attempt -lt $TimeoutSeconds; $attempt++) {
        Start-Sleep -Seconds 1
        if (Test-RecentCooldown) { return $false }
        $canonical = @(Get-CanonicalRemoteLaunchers)
        $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
        if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0 -and (Get-MarkerAgeSeconds) -le $MarkerStaleSeconds) { return $true }
    }
    return $false
}

function Ensure-Agent {
    if (-not (Test-Path -LiteralPath $RunnerScript)) { throw 'sanitized runner not found' }
    if (-not (Test-Path -LiteralPath $DeviceFile)) { Log 'AUTH_REQUIRED device state missing'; Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    if (Test-DeviceStateNewerThanCooldown) {
        Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
        Log 'Cleared stale auth cooldown because device state is newer'
    }
    $canonical = @(Get-CanonicalRemoteLaunchers)
    $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
    if ($canonical.Count -gt 0 -and ($canonical.Count -gt 1 -or $noncanonical.Count -gt 0)) {
        if (Remove-DuplicateCanonicalLaunchers -Canonical $canonical -NonCanonical $noncanonical) {
            Start-Sleep -Milliseconds 500
            $canonical = @(Get-CanonicalRemoteLaunchers)
            $noncanonical = @(Get-NonCanonicalRemoteLaunchers)
        }
    }
    if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0) {
        $markerAge = Get-MarkerAgeSeconds
        if ($markerAge -le $MarkerStaleSeconds) {
            Remove-LegacyStartup
            Write-Output 'REMOTE_AGENT_RUNNING=true'
            return
        }
        if ((Get-LauncherAgeSeconds $canonical[0]) -lt 120) {
            Write-Output 'REMOTE_AGENT_STARTING=true'
            return
        }
        Log ('Provider monitor stale; restarting marker_age_seconds=' + [math]::Round($markerAge))
    }
    if ($canonical.Count -gt 0 -or $noncanonical.Count -gt 0) { Stop-RemoteProcesses; Start-Sleep -Seconds 2 }
    if (Test-RecentCooldown) { Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
    $args = @('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$RunnerScript)
    Start-Process -FilePath $WindowsPowerShell -ArgumentList $args -WorkingDirectory $InstallRoot -WindowStyle Hidden
    if (-not (Wait-AgentConvergence -TimeoutSeconds 60)) {
        if (Test-RecentCooldown) { Stop-RemoteProcesses; Write-Output 'AUTH_REQUIRED=true'; exit 20 }
        throw 'provider connection convergence failed'
    }
    Remove-LegacyStartup
    Write-Output 'REMOTE_AGENT_RUNNING=true'
}

function Enable-TaskSchedulerOperationalLog {
    try {
        & wevtutil.exe sl 'Microsoft-Windows-TaskScheduler/Operational' /e:true 2>$null
        if ($LASTEXITCODE -eq 0) { Log 'Task Scheduler operational log enabled' }
        else { Log ('WARNING Task Scheduler operational log enable failed rc=' + $LASTEXITCODE) }
    }
    catch { Log ('WARNING Task Scheduler operational log enable failed reason=' + $_.Exception.Message) }
}

function Install-Task {
    $installMutex = Enter-OwnerMutex
    try {
        $installedSupervisor = Deploy-OperationalFiles
        $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        $arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $installedSupervisor + '" -Mode Ensure'
        $action = New-ScheduledTaskAction -Execute $WindowsPowerShell -Argument $arguments -WorkingDirectory $InstallRoot
        $startup = New-ScheduledTaskTrigger -AtStartup
        $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
        $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -WakeToRun -Hidden
        Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps DESKTOP-KOCEPSV Desktop Commander online without interactive logon.' -Force -ErrorAction Stop | Out-Null
        Enable-TaskSchedulerOperationalLog
        Stop-RemoteProcesses
        Start-Sleep -Seconds 3
        $removed = Remove-LegacyRawCaptures
        Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
    }
    finally { Exit-OwnerMutex $installMutex }
    Start-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    if (-not (Wait-AgentConvergence -TimeoutSeconds 75)) {
        if (Test-RecentCooldown) { Write-Output 'AUTH_REQUIRED=true'; exit 20 }
        throw 'installed task did not establish a monitored provider connection'
    }
    Remove-LegacyStartup
    Write-Output 'TASK_INSTALLED=true'
    Write-Output 'TASK_ACTION_SECURE=true'
    Write-Output ('LEGACY_RAW_CAPTURES_REMOVED=' + $removed)
}

$ownerMutex = $null
try {
    if ($Mode -ne 'InstallTask' -and $Mode -ne 'Status') { $ownerMutex = Enter-OwnerMutex }
    switch ($Mode) {
        'InstallTask' { Install-Task }
        'Restart' { Stop-RemoteProcesses; Start-Sleep -Seconds 2; Ensure-Agent }
        'KillForRecoveryTest' { Stop-RemoteProcesses; Write-Output 'REMOTE_AGENT_KILLED=true' }
        'Status' { & $StatusScript }
        default { Ensure-Agent }
    }
}
finally { Exit-OwnerMutex $ownerMutex }
