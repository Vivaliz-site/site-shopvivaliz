param([ValidateSet('Ensure','InstallTask')][string]$Mode = 'Ensure')
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$OpenSshRecoveryScript = Join-Path $Repo 'scripts\ensure-windows-openssh-server.ps1'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Remote Control SSH 24h'
$LogDir = Join-Path $Repo 'logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-remote-control-ssh.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

function Get-LegacyTunnel {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and
        ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*')
    } | Select-Object -First 1)
}

function Get-RemoteControlTunnel {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and
        ([string]$_.CommandLine -like '*-R*2223:127.0.0.1:22*')
    })
}

function Start-RemoteControlTunnel {
    $legacy = Get-LegacyTunnel
    if ($legacy.Count -ne 1) {
        Log 'Legacy 5558 tunnel not available yet'
        return $false
    }
    $exe = [string]$legacy[0].ExecutablePath
    $line = [string]$legacy[0].CommandLine
    if ([string]::IsNullOrWhiteSpace($line)) {
        Log 'Legacy tunnel command line unavailable'
        return $false
    }
    if ([string]::IsNullOrWhiteSpace($exe) -or -not (Test-Path -LiteralPath $exe)) {
        $candidates = @(
            'C:\Program Files\Git\usr\bin\ssh.exe',
            'C:\Windows\System32\OpenSSH\ssh.exe'
        )
        $exe = $candidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
    }
    if ([string]::IsNullOrWhiteSpace($exe)) {
        Log 'No usable ssh.exe found for sidecar'
        return $false
    }

    $args = [regex]::Replace(
        $line,
        '^\s*(?:"[^"]*\\ssh\.exe"|[^\s"]*\\ssh\.exe|ssh\.exe)\s*',
        '',
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    ).Trim()

    $oldForwardPattern = '(?i)-R\s*5558:127\.0\.0\.1:5557'
    if ($args -notmatch $oldForwardPattern) {
        Log 'Legacy tunnel command does not contain expected 5558 forward'
        return $false
    }
    $args = [regex]::Replace($args, $oldForwardPattern, '-R 2223:127.0.0.1:22', 1)

    Start-Process -FilePath $exe -ArgumentList $args -WindowStyle Hidden | Out-Null
    Start-Sleep -Seconds 3
    if ((Get-RemoteControlTunnel).Count -lt 1) {
        Log 'Remote-control 2223 sidecar did not stay running'
        return $false
    }
    Log 'Remote-control 2223 sidecar started from proven legacy tunnel parameters'
    return $true
}

function Ensure-Bridge {
    if (!(Test-Path -LiteralPath $OpenSshRecoveryScript)) { throw 'OpenSSH recovery script missing' }
    & $OpenSshRecoveryScript | ForEach-Object { Log ([string]$_) }
    if ((Get-RemoteControlTunnel).Count -ge 1) {
        Log 'Remote-control 2223 sidecar already healthy'
        return
    }
    if (-not (Start-RemoteControlTunnel)) {
        throw 'REMOTE_CONTROL_KOCEPSV_SIDECAR_NOT_READY'
    }
    Write-Output 'REMOTE_CONTROL_KOCEPSV_SIDECAR=PASS'
}

function Install-Task {
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\desktopkocepsv-remote-control-ssh-bridge.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps the private KOCEPSV 2223 reverse SSH bridge alive by cloning the proven 5558 tunnel parameters.' -Force | Out-Null
    Write-Output 'REMOTE_CONTROL_KOCEPSV_SIDECAR_TASK=PASS'
}

if ($Mode -eq 'InstallTask') { Install-Task }
Ensure-Bridge
