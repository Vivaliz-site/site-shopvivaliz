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
$LogFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$DeviceFile = $null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
function Ensure-ProfileEnvironment {
    if (-not $env:USERPROFILE) {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $key = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $profile = (Get-ItemProperty -LiteralPath $key -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables($profile)
    }
    if (-not $env:APPDATA) { $env:APPDATA = Join-Path $env:USERPROFILE 'AppData\Roaming' }
    if (-not $env:LOCALAPPDATA) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
    $env:HOME = $env:USERPROFILE
    $script:DeviceFile = Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json'
}
function Get-DesktopCommanderRemoteLaunchers {
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        [string]$_.CommandLine -match '@wonderwhy-er[\\/]desktop-commander@[^\s]+.*\bremote\b'
    })
}
function Get-CanonicalRemoteLaunchers {
    return @(Get-DesktopCommanderRemoteLaunchers | Where-Object {
        [string]$_.CommandLine -match '@wonderwhy-er[\\/]desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
}
function Get-DesktopCommanderRemoteProcesses {
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $cmd = [string]$_.CommandLine
        ($cmd -match '@wonderwhy-er[\\/]desktop-commander@[^\s]+.*\bremote\b') -or ($cmd -match 'desktop-commander.*\bremote\b')
    })
}
function Stop-ProcessTree([int]$RootId) {
    foreach ($child in @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.ParentProcessId -eq $RootId })) {
        Stop-ProcessTree -RootId $child.ProcessId
    }
    Stop-Process -Id $RootId -Force -ErrorAction SilentlyContinue
}
function Stop-RemoteProcesses {
    foreach ($p in @(Get-DesktopCommanderRemoteLaunchers | Sort-Object ProcessId -Unique)) {
        Stop-ProcessTree -RootId $p.ProcessId
        Log ('Stopped Desktop Commander remote launcher tree pid=' + $p.ProcessId)
    }
    foreach ($p in @(Get-DesktopCommanderRemoteProcesses | Sort-Object ProcessId -Unique)) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }
}
function Test-RecentCooldown {
    if (-not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    $age = (Get-Date).ToUniversalTime() - (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc
    return ($age.TotalHours -lt 6)
}
function Remove-LegacyPersistence {
    $legacy = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\Startup\desktop-commander-remote.vbs'
    if (Test-Path -LiteralPath $legacy) {
        try {
            Move-Item -LiteralPath $legacy -Destination (Join-Path $LogDir 'desktop-commander-remote.vbs.disabled') -Force -ErrorAction Stop
            Log 'Removed legacy Startup desktop-commander-remote.vbs'
        } catch { Log ('WARNING unable to remove legacy Startup VBS: ' + $_.Exception.Message) }
    }
}
function Ensure-Agent {
    Ensure-ProfileEnvironment
    if (-not (Test-Path -LiteralPath $RunnerScript)) { throw 'sanitized runner not found' }
    if (-not (Test-Path -LiteralPath $DeviceFile)) {
        Log 'AUTH_REQUIRED device state missing; not starting interactive device flow'
        Write-Output 'AUTH_REQUIRED=true'
        exit 20
    }
    $launchers = Get-DesktopCommanderRemoteLaunchers
    $canonical = Get-CanonicalRemoteLaunchers
    $noncanonical = @($launchers | Where-Object { $_.ProcessId -notin $canonical.ProcessId })
    if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0) {
        Remove-LegacyPersistence
        Write-Output 'REMOTE_AGENT_RUNNING=true'
        return
    }
    if ($launchers.Count -gt 0 -or (Get-DesktopCommanderRemoteProcesses).Count -gt 0) {
        Stop-RemoteProcesses
        Start-Sleep -Seconds 2
    }
    if (Test-RecentCooldown) { Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$RunnerScript) -WorkingDirectory $Repo -WindowStyle Hidden
    Start-Sleep -Seconds 10
    if (Test-RecentCooldown) { Stop-RemoteProcesses; Write-Output 'AUTH_REQUIRED=true'; exit 20 }
    $running = Get-CanonicalRemoteLaunchers
    $all = Get-DesktopCommanderRemoteLaunchers
    $other = @($all | Where-Object { $_.ProcessId -notin $running.ProcessId })
    if ($running.Count -ne 1 -or $other.Count -ne 0) {
        Stop-RemoteProcesses
        throw ('singleton convergence failed canonical=' + $running.Count + ' noncanonical=' + $other.Count)
    }
    Remove-LegacyPersistence
    Write-Output 'REMOTE_AGENT_RUNNING=true'
}
function Install-Task {
    Ensure-ProfileEnvironment
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $PSCommandPath + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps DESKTOP-KOCEPSV Desktop Commander online without interactive logon.' -Force | Out-Null
    Write-Output 'TASK_INSTALLED=true'
}

switch ($Mode) {
    'InstallTask' { Install-Task; Stop-RemoteProcesses; if (Test-Path -LiteralPath $CooldownFile) { Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue }; Start-Sleep -Seconds 2; Ensure-Agent }
    'Restart' { Stop-RemoteProcesses; if (Test-Path -LiteralPath $CooldownFile) { Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue }; Start-Sleep -Seconds 2; Ensure-Agent }
    'KillForRecoveryTest' { Stop-RemoteProcesses; Write-Output 'REMOTE_AGENT_KILLED=true' }
    'Status' { & $StatusScript }
    default { Ensure-Agent }
}
