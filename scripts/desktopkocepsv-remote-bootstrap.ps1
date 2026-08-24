param(
    [ValidateSet('Ensure','InstallTask','Status')]
    [string]$Mode = 'Ensure'
)
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
        $response = Invoke-RestMethod -Uri 'http://127.0.0.1:5557/health' -Method Get -TimeoutSec 3
        return ($response.status -eq 'ok' -and $response.environment -eq 'desktop-kocepsv')
    } catch { return $false }
}
function Stop-DesktopMcp {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ($_.Name -match '^(?i)(python|python3|py)\.exe$') -and
        ([string]$_.CommandLine -like '*mcp-server.py*') -and
        ([string]$_.CommandLine -like '*5557*') -and
        ([string]$_.CommandLine -like '*desktop-kocepsv*')
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
function Start-DesktopMcp {
    if (-not (Test-Path -LiteralPath $McpScript)) { throw 'mcp-server.py missing' }
    if (Get-Command py -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'py' -ArgumentList @('-3',$McpScript,'--port','5557','--env','desktop-kocepsv','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } elseif (Get-Command python -ErrorAction SilentlyContinue) {
        Start-Process -FilePath 'python' -ArgumentList @($McpScript,'--port','5557','--env','desktop-kocepsv','--host','127.0.0.1') -WorkingDirectory $Repo -WindowStyle Hidden
    } else { throw 'Python not found' }
    Start-Sleep -Seconds 3
}
function Get-ManagedTunnelProcesses {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        (($_.Name -eq 'ssh.exe') -and ([string]$_.CommandLine -like '*-R*5558:127.0.0.1:5557*')) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*desktopkocepsv-ssh-tunnel-service-managed.ps1*'))
    })
}
function Stop-ManagedTunnel {
    foreach ($p in (Get-ManagedTunnelProcesses)) { Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue }
}
function Ensure-RelayTask {
    $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $PSCommandPath + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
    $startup = New-ScheduledTaskTrigger -AtStartup
    $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps DESKTOP-KOCEPSV loopback MCP and private relay online without interactive logon.' -Force | Out-Null
}
function Ensure-RemotePath {
    if (-not (Test-McpHealth)) {
        Stop-DesktopMcp
        Start-DesktopMcp
    }
    if (-not (Test-McpHealth)) { throw 'DESKTOP-KOCEPSV MCP health failed on 127.0.0.1:5557' }
    $tunnel = Get-ManagedTunnelProcesses
    $ssh = @($tunnel | Where-Object { $_.Name -eq 'ssh.exe' })
    $wrapper = @($tunnel | Where-Object { $_.Name -match '^(powershell|pwsh)\.exe$' })
    if ($ssh.Count -eq 0 -or $wrapper.Count -eq 0) {
        Stop-ManagedTunnel
        Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
        Start-Sleep -Seconds 5
    }
    Write-Output 'DESKTOP_KOCEPSV_RELAY_LOCAL_HEALTH=true'
}

switch ($Mode) {
    'InstallTask' { Ensure-RelayTask; Ensure-RemotePath }
    'Status' {
        Write-Output ('MCP_HEALTH=' + (Test-McpHealth))
        Write-Output ('RELAY_PROCESS_COUNT=' + (Get-ManagedTunnelProcesses).Count)
        Write-Output ('TASK_EXISTS=' + [bool](Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue))
    }
    default { Ensure-RemotePath }
}
