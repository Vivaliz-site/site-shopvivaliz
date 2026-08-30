param([ValidateSet('Ensure','InstallTask')][string]$Mode = 'Ensure')
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$McpScript = Join-Path $Repo 'scripts\mcp-server.py'
$TunnelScript = Join-Path $Repo 'scripts\desktopkocepsv-ssh-tunnel-service-managed.ps1'
$TaskName = 'ShopVivaliz DESKTOP-KOCEPSV Relay 24h'
$LogDir = Join-Path $Repo 'logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-remote-bootstrap.log'
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
function Stop-ManagedTunnel {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        (($_.Name -eq 'ssh.exe') -and ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*')) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*desktopkocepsv-ssh-tunnel-service-managed.ps1*'))
    } | ForEach-Object { try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { } }
}
function Ensure-Relay {
    if (!(Test-Path -LiteralPath $McpScript)) { throw 'MCP script missing' }
    if (!(Test-Path -LiteralPath $TunnelScript)) { throw 'Tunnel script missing' }
    if (-not (Test-McpHealth)) { Stop-DesktopMcp; Start-DesktopMcp }
    if (-not (Test-McpHealth)) { throw 'MCP health failed on 127.0.0.1:5557' }
    $ssh = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.Name -eq 'ssh.exe' -and ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*') })
    if ($ssh.Count -eq 0) {
        Stop-ManagedTunnel
        Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
        Start-Sleep -Seconds 5
    }
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

if ($Mode -eq 'InstallTask') { Install-Task }
Ensure-Relay
