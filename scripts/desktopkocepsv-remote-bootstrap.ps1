param([ValidateSet('Ensure','InstallTask')][string]$Mode = 'Ensure')
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$McpScript = Join-Path $Repo 'scripts\mcp-server.py'
$TunnelScript = Join-Path $Repo 'scripts\desktopkocepsv-ssh-tunnel-service-managed.ps1'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Relay 24h'
$LogDir = Join-Path $Repo 'logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-remote-bootstrap.log'
$RelayConfigFile = Join-Path $LogDir 'desktopkocepsv-relay-runtime.json'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
function Test-McpHealth {
    try {
        $r = Invoke-RestMethod -Uri 'http://127.0.0.1:5557/health' -Method Get -TimeoutSec 3
        return ($r.status -eq 'ok' -and $r.environment -eq 'desktop-kocepsv')
    } catch { return $false }
}
function Stop-DesktopMcp {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ($_.Name -match '^(?i)(python|python3|py)\.exe$') -and
        ([string]$_.CommandLine -like '*mcp-server.py*') -and
        ([string]$_.CommandLine -like '*5557*')
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { } }
    Start-Sleep -Seconds 2
}
function Start-DesktopMcp {
    if (Get-Command py -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'py' -ArgumentList @('-3',$McpScript,'--port','5557','--env','desktop-kocepsv','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } elseif (Get-Command python -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'python' -ArgumentList @($McpScript,'--port','5557','--env','desktop-kocepsv','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } else { throw 'Python not found' }
    Start-Sleep -Seconds 3
}
function Capture-WorkingTunnelConfig {
    $legacy = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*')
    } | Select-Object -First 1)
    if ($legacy.Count -ne 1) { return $false }
    $line = [string]$legacy[0].CommandLine
    $key = $null
    $known = $null
    $port = '22'
    $user = $null
    $host = $null
    if ($line -match '(?i)(?:^|\s)-i\s+"([^"]+)"') { $key = $Matches[1] }
    elseif ($line -match "(?i)(?:^|\s)-i\s+'([^']+)'") { $key = $Matches[1] }
    elseif ($line -match '(?i)(?:^|\s)-i\s+([^\s]+)') { $key = $Matches[1] }
    if ($line -match '(?i)UserKnownHostsFile="([^"]+)"') { $known = $Matches[1] }
    elseif ($line -match "(?i)UserKnownHostsFile='([^']+)'") { $known = $Matches[1] }
    elseif ($line -match '(?i)UserKnownHostsFile=([^\s]+)') { $known = $Matches[1] }
    if ($line -match '(?i)(?:^|\s)-p\s+(\d+)') { $port = $Matches[1] }
    $targets = [regex]::Matches($line, '(?i)([A-Za-z0-9_.-]+)@([A-Za-z0-9_.:-]+)')
    if ($targets.Count -gt 0) {
        $user = $targets[$targets.Count - 1].Groups[1].Value
        $host = $targets[$targets.Count - 1].Groups[2].Value
    }
    if (-not $key -or -not $known -or -not $user -or -not $host) {
        Log 'Working legacy tunnel found but connection parameters could not be captured'
        return $false
    }
    if (-not (Test-Path -LiteralPath $key) -or -not (Test-Path -LiteralPath $known)) {
        Log 'Captured working tunnel paths no longer exist'
        return $false
    }
    @{
        key_path = $key
        known_hosts_path = $known
        backend_host = $host
        backend_port = [int]$port
        backend_user = $user
        captured_at = (Get-Date).ToUniversalTime().ToString('o')
    } | ConvertTo-Json -Compress | Set-Content -LiteralPath $RelayConfigFile -Encoding UTF8
    Log 'Captured working legacy tunnel connection metadata for reverse-SSH upgrade'
    return $true
}
function Stop-ManagedTunnel {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        (($_.Name -eq 'ssh.exe') -and (
            ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*') -or
            ([string]$_.CommandLine -like '*-R*2223:127.0.0.1:22*')
        )) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*desktopkocepsv-ssh-tunnel-service-managed.ps1*'))
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { } }
}
function Ensure-Relay {
    if (!(Test-Path -LiteralPath $McpScript)) { throw 'MCP script missing' }
    if (!(Test-Path -LiteralPath $TunnelScript)) { throw 'Tunnel script missing' }
    if (-not (Test-McpHealth)) { Stop-DesktopMcp; Start-DesktopMcp }
    if (-not (Test-McpHealth)) { throw 'MCP health failed on 127.0.0.1:5557' }
    $ssh = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and
        ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*') -and
        ([string]$_.CommandLine -like '*-R*2223:127.0.0.1:22*')
    })
    if ($ssh.Count -ne 1) {
        [void](Capture-WorkingTunnelConfig)
        Stop-ManagedTunnel
        Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
        Start-Sleep -Seconds 5
        $ssh = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        $_.Name -eq 'ssh.exe' -and
        ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*') -and
        ([string]$_.CommandLine -like '*-R*2223:127.0.0.1:22*')
    })
    }
    if ($ssh.Count -ne 1) { throw 'Managed DESKTOP-KOCEPSV reverse tunnel failed to stay running' }
    Log 'DESKTOP-KOCEPSV relay ensure completed'
}
function Install-Task {
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\desktopkocepsv-remote-bootstrap.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps DESKTOP-KOCEPSV private loopback maintenance relay available without interactive logon.' -Force | Out-Null
    Write-Output 'TASK_INSTALLED=true'
}
function Ensure-Task {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) {
        Install-Task
        return
    }
    if ($task.State -eq 'Disabled') {
        Enable-ScheduledTask -TaskName $TaskName | Out-Null
        Write-Output 'TASK_REENABLED=true'
    }
}

if ($Mode -eq 'InstallTask') { Install-Task }
Ensure-Task
Ensure-Relay
