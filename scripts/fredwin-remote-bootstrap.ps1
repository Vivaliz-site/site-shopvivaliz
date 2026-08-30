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
    $ssh = @(Get-ManagedSsh)
    if ($ssh.Count -ne 1) {
        Stop-ManagedTunnel
        Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
        Start-Sleep -Seconds 5
    }
    Log 'Fred-Win relay ensure completed'
}
function Install-Task {
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $script = Join-Path $Repo 'scripts\fredwin-remote-bootstrap.ps1'
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $script + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    $settings.Hidden = $true
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps the Fred-Win private loopback maintenance relay and diagnostic SSH forward available without interactive logon.' -Force | Out-Null
    Write-Output 'RELAY_TASK_INSTALLED=true'
}

if ($Mode -eq 'InstallTask') { Install-Task }
Ensure-Relay
