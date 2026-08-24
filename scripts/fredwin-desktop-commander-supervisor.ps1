param(
    [ValidateSet('Ensure','InstallTask','Restart','KillForRecoveryTest','Status')]
    [string]$Mode = 'Ensure'
)
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$TaskName = 'ShopVivaliz Desktop Commander 24h'
$StatusScript = Join-Path $Repo 'scripts\fredwin-desktop-commander-status.ps1'
$RunnerScript = Join-Path $Repo 'scripts\fredwin-desktop-commander-runner.ps1'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktop-commander-auth-required.cooldown'
$DeviceFile = $null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
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

function Get-DesktopCommanderRemoteLaunchers {
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $cmd = [string]$_.CommandLine
        $cmd -match '@wonderwhy-er[\\/]desktop-commander@[^\s]+.*\bremote\b'
    })
}

function Get-CanonicalRemoteLaunchers {
    return @(Get-DesktopCommanderRemoteLaunchers | Where-Object {
        $cmd = [string]$_.CommandLine
        $cmd -match '@wonderwhy-er[\\/]desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
    })
}

function Get-DesktopCommanderRemoteProcesses {
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object {
        $cmd = [string]$_.CommandLine
        ($cmd -match '@wonderwhy-er[\\/]desktop-commander@[^\s]+.*\bremote\b') -or
        ($cmd -match 'desktop-commander.*\bremote\b')
    })
}

function Stop-ProcessTree([int]$RootId) {
    foreach ($child in @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.ParentProcessId -eq $RootId })) {
        Stop-ProcessTree -RootId $child.ProcessId
    }
    Stop-Process -Id $RootId -Force -ErrorAction SilentlyContinue
}

function Stop-RemoteProcesses {
    $roots = @(Get-DesktopCommanderRemoteLaunchers | Sort-Object ProcessId -Unique)
    foreach ($p in $roots) {
        Stop-ProcessTree -RootId $p.ProcessId
        Log ('Stopped Desktop Commander remote launcher tree pid=' + $p.ProcessId)
    }
    foreach ($p in @(Get-DesktopCommanderRemoteProcesses | Sort-Object ProcessId -Unique)) {
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }
}

function Remove-LegacyPersistence {
    foreach ($name in @('DesktopCommanderHidden','DesktopCommanderUser24x7')) {
        try {
            if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
                Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction Stop
                Log ('Removed legacy task ' + $name)
            }
        } catch {
            Log ('WARNING unable to remove legacy task ' + $name + ': ' + $_.Exception.Message)
        }
    }
    $startup = Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\Startup\desktop-commander.vbs'
    if (Test-Path -LiteralPath $startup) {
        try {
            $disabled = Join-Path $LogDir 'desktop-commander-startup.vbs.disabled'
            Move-Item -LiteralPath $startup -Destination $disabled -Force -ErrorAction Stop
            Log 'Removed legacy Startup desktop-commander.vbs'
        } catch {
            Log ('WARNING unable to remove legacy Startup VBS: ' + $_.Exception.Message)
        }
    }
}

function Test-RecentCooldown {
    if (-not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    $age = (Get-Date).ToUniversalTime() - (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc
    return ($age.TotalHours -lt 6)
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
        Log ('Remote agent healthy singleton pid=' + $canonical[0].ProcessId)
        Write-Output 'REMOTE_AGENT_RUNNING=true'
        return
    }

    if ($launchers.Count -gt 0 -or (Get-DesktopCommanderRemoteProcesses).Count -gt 0) {
        Log ('Repairing remote agent launchers=' + $launchers.Count + ' canonical=' + $canonical.Count + ' noncanonical=' + $noncanonical.Count)
        Stop-RemoteProcesses
        Start-Sleep -Seconds 2
    }

    if (Test-RecentCooldown) {
        Log 'AUTH_REQUIRED recent provider device-flow request; retry cooldown active'
        Write-Output 'AUTH_REQUIRED=true'
        exit 20
    }

    $args = @('-NoLogo','-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$RunnerScript)
    Start-Process -FilePath 'powershell.exe' -ArgumentList $args -WorkingDirectory $Repo -WindowStyle Hidden
    Start-Sleep -Seconds 10

    if (Test-RecentCooldown) {
        Stop-RemoteProcesses
        Log 'AUTH_REQUIRED provider requested device authorization; cooldown active'
        Write-Output 'AUTH_REQUIRED=true'
        exit 20
    }

    $running = Get-CanonicalRemoteLaunchers
    $allRunning = Get-DesktopCommanderRemoteLaunchers
    $otherRunning = @($allRunning | Where-Object { $_.ProcessId -notin $running.ProcessId })
    if ($running.Count -ne 1 -or $otherRunning.Count -ne 0) {
        Stop-RemoteProcesses
        throw ('Remote Desktop Commander singleton convergence failed canonical=' + $running.Count + ' noncanonical=' + $otherRunning.Count)
    }

    Remove-LegacyPersistence
    Log ('Remote agent started singleton pid=' + $running[0].ProcessId)
    Write-Output 'REMOTE_AGENT_RUNNING=true'
}

function Install-Task {
    Ensure-ProfileEnvironment
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\fredwin-desktop-commander-supervisor.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps official Remote Desktop Commander online under the persistent user profile without interactive startup.' -Force | Out-Null
    Log ('Scheduled task installed user=' + $user + ' logon=S4U watchdog=1m')
    Write-Output 'TASK_INSTALLED=true'
}

switch ($Mode) {
    'InstallTask' { Install-Task; Stop-RemoteProcesses; if (Test-Path -LiteralPath $CooldownFile) { Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue }; Start-Sleep -Seconds 2; Ensure-Agent }
    'Restart' { Stop-RemoteProcesses; if (Test-Path -LiteralPath $CooldownFile) { Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue }; Start-Sleep -Seconds 2; Ensure-Agent }
    'KillForRecoveryTest' { Stop-RemoteProcesses; Write-Output 'REMOTE_AGENT_KILLED=true' }
    'Status' { & $StatusScript }
    default { Ensure-Agent }
}
