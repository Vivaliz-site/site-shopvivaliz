# Idempotent bootstrap for the Fred-Win private maintenance path.
# MCP stays on 127.0.0.1:5557 and reaches the Oracle VM only through reverse SSH.
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$McpScript = Join-Path $Repo 'scripts\mcp-server.py'
$TunnelScript = Join-Path $Repo 'scripts\ssh-tunnel-service-managed.ps1'
$AutoSyncScript = Join-Path $Repo 'scripts\local-auto-sync.ps1'
$AutoSyncTaskName = 'ShopVivaliz Auto Sync'
$RelayTaskName = 'ShopVivaliz FredWin Relay 24h'
$LogDir = Join-Path $Repo 'logs'
$LogFile = Join-Path $LogDir 'fredwin-remote-bootstrap.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
function Test-LocalPort([int]$Port) {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $async = $client.BeginConnect('127.0.0.1', $Port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne(1500, $false)) { $client.Close(); return $false }
        $client.EndConnect($async); $client.Close(); return $true
    } catch { return $false }
}
function Test-McpHealth {
    try {
        $response = Invoke-RestMethod -Uri 'http://127.0.0.1:5557/health' -Method Get -TimeoutSec 3
        return ($response.status -eq 'ok' -and $response.environment -eq 'fred-win')
    } catch { return $false }
}
function Stop-FredWinMcp {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ($_.Name -match '^(?i)(python|python3|py)\.exe$') -and
        ([string]$_.CommandLine -like '*mcp-server.py*') -and
        ([string]$_.CommandLine -like '*5557*')
    } | ForEach-Object {
        Log ('Stopping stale/unhealthy MCP pid=' + $_.ProcessId)
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }
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
function Ensure-RelayWatchdogTask {
    try {
        $existing = Get-ScheduledTask -TaskName $RelayTaskName -ErrorAction SilentlyContinue
        if ($existing) {
            $actionText = (($existing.Actions | ForEach-Object { ([string]$_.Execute) + ' ' + ([string]$_.Arguments) }) -join ' ')
            if ($actionText -like '*fredwin-remote-bootstrap.ps1*' -and
                [string]$existing.Principal.LogonType -eq 'S4U' -and
                [string]$existing.Principal.RunLevel -eq 'Highest') {
                return
            }
        }
        $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $PSCommandPath + '"'
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
        $startup = New-ScheduledTaskTrigger -AtStartup
        $watchdog = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
        $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType S4U -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)
        Register-ScheduledTask -TaskName $RelayTaskName -Action $action -Trigger @($startup,$watchdog) -Principal $principal -Settings $settings -Description 'Keeps Fred-Win loopback MCP and verified private relay online without interactive logon.' -Force | Out-Null
        Log 'Fred-Win relay S4U watchdog installed'
    } catch {
        Log ('WARNING unable to ensure Fred-Win relay watchdog: ' + $_.Exception.Message)
    }
}
function Ensure-AutoSyncTask {
    if (-not (Test-Path -LiteralPath $AutoSyncScript)) { return }
    try {
        $existing = Get-ScheduledTask -TaskName $AutoSyncTaskName -ErrorAction SilentlyContinue
        if ($existing) {
            $actionText = (($existing.Actions | ForEach-Object { ([string]$_.Execute) + ' ' + ([string]$_.Arguments) }) -join ' ')
            if ($actionText -like '*local-auto-sync.ps1*') { return }
        }
        $user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        $arguments = '-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $AutoSyncScript + '"'
        $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory $Repo
        $logonTrigger = New-ScheduledTaskTrigger -AtLogOn -User $user
        $periodicTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
        $principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Highest
        $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
        Register-ScheduledTask -TaskName $AutoSyncTaskName -Action $action -Trigger @($logonTrigger,$periodicTrigger) -Principal $principal -Settings $settings -Description 'ShopVivaliz local Git fast-forward sync and mail guard' -Force | Out-Null
    } catch { Log ('WARNING unable to ensure auto-sync task: ' + $_.Exception.Message) }
}
function Get-ManagedTunnelProcesses {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        (($_.Name -eq 'ssh.exe') -and ([string]$_.CommandLine -like '*137.131.156.17*') -and ([string]$_.CommandLine -like '*-R*5557:127.0.0.1:5557*')) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*ssh-tunnel-service-managed.ps1*'))
    })
}
function Stop-ManagedTunnel {
    foreach ($p in (Get-ManagedTunnelProcesses)) {
        Log ('Stopping managed tunnel pid=' + $p.ProcessId)
        Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Seconds 2
}

if (-not (Test-Path -LiteralPath $McpScript)) { throw 'MCP script missing' }
if (-not (Test-Path -LiteralPath $TunnelScript)) { throw 'Tunnel script missing' }
Ensure-RelayWatchdogTask
Ensure-AutoSyncTask

$portOpen = Test-LocalPort -Port 5557
$healthy = if ($portOpen) { Test-McpHealth } else { $false }
if ($portOpen -and -not $healthy) { Stop-FredWinMcp; $portOpen = $false }
if (-not $portOpen) { Start-FredWinMcp }
if (-not (Test-LocalPort -Port 5557)) { throw 'MCP failed to listen on 127.0.0.1:5557' }
if (-not (Test-McpHealth)) { throw 'MCP health failed on 127.0.0.1:5557' }
Log 'MCP local health 5557 is OK'

$managed = Get-ManagedTunnelProcesses
$managedWrapper = @($managed | Where-Object { $_.Name -match '^(powershell|pwsh)\.exe$' })
$managedSsh = @($managed | Where-Object { $_.Name -eq 'ssh.exe' })
if ($managedWrapper.Count -gt 0 -and $managedSsh.Count -eq 0) {
    Log 'Managed tunnel wrapper exists without ssh forward; recovering tunnel'
    Stop-ManagedTunnel
    $managedWrapper = @(); $managedSsh = @()
}
if ($managedWrapper.Count -eq 0 -or $managedSsh.Count -eq 0) {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ($_.Name -eq 'ssh.exe' -and ([string]$_.CommandLine -like '*137.131.156.17*') -and ([string]$_.CommandLine -like '*-R*2222:localhost:22*')) -or
        ((($_.Name -eq 'powershell.exe') -or ($_.Name -eq 'pwsh.exe')) -and ([string]$_.CommandLine -like '*ssh-tunnel-service.ps1*'))
    } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-WindowStyle','Hidden','-File',$TunnelScript) -WorkingDirectory $Repo -WindowStyle Hidden
    Start-Sleep -Seconds 5
}
Log 'Fred-Win remote bootstrap completed'
