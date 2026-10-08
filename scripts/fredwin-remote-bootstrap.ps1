param([ValidateSet('Ensure','InstallTask')][string]$Mode = 'Ensure')
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$McpScript = Join-Path $Repo 'scripts\mcp-server.py'
$TunnelScript = Join-Path $Repo 'scripts\ssh-tunnel-service-managed.ps1'
$TaskName = 'ShopVivaliz Fred-Win Relay 24h'
$LogDir = Join-Path $Repo 'logs'
$LogFile = Join-Path $LogDir 'fredwin-remote-bootstrap.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
function Test-McpHealth {
    try {
        $r = Invoke-RestMethod -Uri 'http://127.0.0.1:5557/health' -Method Get -TimeoutSec 3
        return ($r.status -eq 'ok' -and $r.environment -eq 'fred-win')
    } catch { return $false }
}
function Stop-FredWinMcp {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ($_.Name -match '^(?i)(python|python3|py)\.exe$') -and
        ([string]$_.CommandLine -like '*mcp-server.py*') -and
        ([string]$_.CommandLine -like '*5557*')
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { } }
    Start-Sleep -Seconds 2
}
function Start-FredWinMcp {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'py' -ArgumentList @('-3',$McpScript,'--port','5557','--env','fred-win','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } elseif (Get-Command python -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'python' -ArgumentList @($McpScript,'--port','5557','--env','fred-win','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } else { throw 'Python not found on Fred-Win' }
    Start-Sleep -Seconds 3
}
function Get-ManagedSsh {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and
        ([string]$_.CommandLine -like '*-R*2222:127.0.0.1:22*') -and
        ([string]$_.CommandLine -like '*-R*5557:127.0.0.1:5557*') -and
        ([string]$_.CommandLine -like '*StrictHostKeyChecking=yes*') -and
        ([string]$_.CommandLine -like '*UserKnownHostsFile=*')
    })
}
function Test-RemoteTunnelProtocol {
    # A running ssh.exe (or a LISTEN socket) does not establish that -R 2222
    # actually reaches Windows sshd. Use an independent authenticated probe.
    $sshExe = 'C:\Program Files\Git\usr\bin\ssh.exe'
    $keyPath = 'C:\Users\FRED\Downloads\ssh-key-2026-07-04.key'
    $knownHostsPath = 'C:\Users\FRED\.ssh\known_hosts'
    if (!(Test-Path -LiteralPath $sshExe) -or !(Test-Path -LiteralPath $keyPath) -or
        !(Test-Path -LiteralPath $knownHostsPath)) { return $false }
    $backendHost = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_HOST', 'Machine')
    $backendPortRaw = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_PORT', 'Machine')
    if ([string]::IsNullOrWhiteSpace($backendHost)) { $backendHost = '100.66.174.74' }
    if ([string]::IsNullOrWhiteSpace($backendPortRaw)) { $backendPortRaw = '22' }
    try {
        $backendPort = [int]$backendPortRaw
        $output = & $sshExe -i $keyPath -p $backendPort `
            -o 'BatchMode=yes' `
            -o 'ConnectTimeout=8' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=" + $knownHostsPath) `
            "ubuntu@$backendHost" 'timeout 5 ssh-keyscan -T 3 -p 2222 127.0.0.1' 2>$null
        if ($LASTEXITCODE -ne 0) { return $false }
        foreach ($line in @($output)) {
            if ([string]$line -match '^\[127\.0\.0\.1\]:2222 ssh-') { return $true }
        }
    } catch { return $false }
    return $false
}
function Stop-ManagedTunnel {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        (($_.Name -eq 'ssh.exe') -and (([string]$_.CommandLine -like '*-R*5557:127.0.0.1:5557*') -or ([string]$_.CommandLine -like '*-R*2222:127.0.0.1:22*'))) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*ssh-tunnel-service-managed.ps1*'))
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { } }
    Start-Sleep -Seconds 2
}
function Ensure-Relay {
    if (!(Test-Path -LiteralPath $McpScript)) { throw 'MCP script missing' }
    if (!(Test-Path -LiteralPath $TunnelScript)) { throw 'Tunnel script missing' }
    if (-not (Test-McpHealth)) { Stop-FredWinMcp; Start-FredWinMcp }
    if (-not (Test-McpHealth)) { throw 'MCP health failed on loopback' }
    $sshd = Get-Service -Name sshd -ErrorAction SilentlyContinue
    if (-not $sshd) { throw 'Windows OpenSSH sshd service missing' }
    if ($sshd.StartType -ne 'Automatic') { Set-Service -Name sshd -StartupType Automatic }
    if ($sshd.Status -ne 'Running') {
        Start-Service -Name sshd
        Start-Sleep -Seconds 2
        Log 'Windows sshd restarted'
    }
    $ssh = @(Get-ManagedSsh)
    $protocolOk = $false
    if ($ssh.Count -eq 1) {
        $protocolOk = Test-RemoteTunnelProtocol
        if (-not $protocolOk) {
            Start-Sleep -Seconds 2
            $protocolOk = Test-RemoteTunnelProtocol
        }
    }
    if ($ssh.Count -eq 1 -and -not $protocolOk) {
        # An inconclusive remote probe must never destroy a working transport.
        # The backend controller is authoritative for actual reverse SSH health.
        Log 'SSH protocol probe inconclusive; leaving existing tunnel intact'
    }
    if ($ssh.Count -ne 1) {
        Log 'Reverse SSH process count invalid; repairing'
        Stop-ManagedTunnel
        Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
        Start-Sleep -Seconds 5
        $ssh = @(Get-ManagedSsh)
        $protocolOk = $false
        if ($ssh.Count -eq 1) {
            for ($attempt = 1; $attempt -le 3; $attempt++) {
                if (Test-RemoteTunnelProtocol) { $protocolOk = $true; break }
                Start-Sleep -Seconds 2
            }
        }
    }
    if ($ssh.Count -ne 1) { throw 'Managed Fred-Win reverse tunnel failed to stay running' }
    if (-not $protocolOk) { Log 'SSH protocol probe remains inconclusive after repair' }
    Log 'Fred-Win relay ensure completed (process verified; protocol probe recorded)'
}
function Install-Task {
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\fredwin-remote-bootstrap.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps the Fred-Win private loopback maintenance relay and diagnostic SSH forward available without interactive logon.' -Force | Out-Null
    Write-Output 'RELAY_TASK_INSTALLED=true'
}
function Ensure-Task {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) {
        Install-Task
        return
    }
    $twoMinuteWatchdog = $false
    foreach ($trigger in @($task.Triggers)) {
        try {
            if ($trigger.Repetition.Interval -and
                [System.Xml.XmlConvert]::ToTimeSpan([string]$trigger.Repetition.Interval).TotalSeconds -eq 120) {
                $twoMinuteWatchdog = $true
            }
        } catch { }
    }
    if (-not $twoMinuteWatchdog) {
        Install-Task
        Log 'Watchdog task updated to two-minute interval'
        return
    }
    if ($task.State -eq 'Disabled') {
        Enable-ScheduledTask -TaskName $TaskName | Out-Null
        Write-Output 'RELAY_TASK_REENABLED=true'
    }
}

if ($Mode -eq 'InstallTask') { Install-Task }
Ensure-Task
Ensure-Relay
