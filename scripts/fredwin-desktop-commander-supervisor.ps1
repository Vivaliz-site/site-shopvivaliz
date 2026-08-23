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
function Get-RemoteProcesses {
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" -ErrorAction SilentlyContinue | Where-Object { [string]$_.CommandLine -match 'desktop-commander.*remote' })
}
function Stop-RemoteProcesses {
    foreach ($p in (Get-RemoteProcesses)) {
        try { Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop; Log ('Stopped remote agent pid=' + $p.ProcessId) } catch { Log ('WARNING stop failed pid=' + $p.ProcessId) }
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
    $existing = Get-RemoteProcesses
    if ($existing.Count -gt 0) {
        Log ('Remote agent already running count=' + $existing.Count)
        Write-Output 'REMOTE_AGENT_RUNNING=true'
        return
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
    $running = Get-RemoteProcesses
    if ($running.Count -eq 0) { throw 'Remote Desktop Commander did not stay running' }
    Log ('Remote agent started pid=' + (($running.ProcessId | Sort-Object) -join ','))
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
