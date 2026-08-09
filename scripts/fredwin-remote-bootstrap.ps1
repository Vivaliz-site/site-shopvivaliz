# Idempotent bootstrap for the Fred-Win remote maintenance path.
# Runs locally on Fred-Win. It does not expose the MCP server to the public internet:
# MCP binds to 127.0.0.1:5557 and reaches the Oracle VM only through reverse SSH.

$ErrorActionPreference = "Stop"
$Repo = "C:\site-shopvivaliz"
$McpScript = Join-Path $Repo "scripts\mcp-server.py"
$TunnelScript = Join-Path $Repo "scripts\ssh-tunnel-service-managed.ps1"
$LogDir = Join-Path $Repo "logs"
$LogFile = Join-Path $LogDir "fredwin-remote-bootstrap.log"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log {
    param([string]$Message)
    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

function Test-LocalPort {
    param([int]$Port)
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $async = $client.BeginConnect("127.0.0.1", $Port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne(1500, $false)) {
            $client.Close()
            return $false
        }
        $client.EndConnect($async)
        $client.Close()
        return $true
    }
    catch { return $false }
}

function Test-McpHealth {
    try {
        $response = Invoke-RestMethod -Uri "http://127.0.0.1:5557/health" -Method Get -TimeoutSec 3
        return ($response.status -eq 'ok' -and $response.environment -eq 'fred-win')
    }
    catch { return $false }
}

function Stop-FredWinMcp {
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.Name -match '^(?i)(python|python3|py)\.exe$') -and
            ([string]$_.CommandLine -like '*mcp-server.py*') -and
            ([string]$_.CommandLine -like '*5557*')
        } |
        ForEach-Object {
            Log ("Stopping stale/unhealthy MCP pid=" + $_.ProcessId)
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { }
        }
    Start-Sleep -Seconds 2
}

function Start-FredWinMcp {
    $python = $null
    if (Get-Command py -ErrorAction SilentlyContinue) {
        $python = "py"
        $args = @("-3", $McpScript, "--port", "5557", "--env", "fred-win", "--host", "127.0.0.1")
    }
    elseif (Get-Command python -ErrorAction SilentlyContinue) {
        $python = "python"
        $args = @($McpScript, "--port", "5557", "--env", "fred-win", "--host", "127.0.0.1")
    }
    else { throw "Python not found on Fred-Win" }

    Log "Starting MCP on 127.0.0.1:5557"
    Start-Process -FilePath $python -ArgumentList $args -WorkingDirectory $Repo -WindowStyle Hidden
    Start-Sleep -Seconds 3
}

if (!(Test-Path $McpScript)) { throw "MCP script missing: $McpScript" }
if (!(Test-Path $TunnelScript)) { throw "Tunnel script missing: $TunnelScript" }

# 1) Ensure the MCP responds to HTTP health, not merely that the TCP port is open.
# A long blocking command can leave port 5557 open while making the aiohttp event loop
# unresponsive; in that case the bootstrap now kills and recreates the MCP process.
$portOpen = Test-LocalPort -Port 5557
$healthy = $false
if ($portOpen) { $healthy = Test-McpHealth }

if ($portOpen -and -not $healthy) {
    Log "MCP port is open but health is unresponsive; recovering process"
    Stop-FredWinMcp
    $portOpen = $false
}

if (-not $portOpen) {
    Start-FredWinMcp
}

if (-not (Test-LocalPort -Port 5557)) { throw "MCP failed to listen on 127.0.0.1:5557" }
if (-not (Test-McpHealth)) { throw "MCP health failed on 127.0.0.1:5557" }
Log "MCP local health 5557 is OK"

# 2) Replace only the old ShopVivaliz reverse-tunnel processes, then start the managed one.
$managedRunning = Get-CimInstance Win32_Process -Filter "Name='powershell.exe' OR Name='pwsh.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like "*ssh-tunnel-service-managed.ps1*" }

if (-not $managedRunning) {
    Log "Stopping legacy ShopVivaliz tunnel processes"
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.Name -eq "ssh.exe" -and $_.CommandLine -like "*137.131.156.17*" -and $_.CommandLine -like "*-R*2222:localhost:22*") -or
            (($_.Name -eq "powershell.exe" -or $_.Name -eq "pwsh.exe") -and $_.CommandLine -like "*ssh-tunnel-service.ps1*")
        } |
        ForEach-Object {
            try { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop } catch { }
        }
    Start-Sleep -Seconds 2

    Log "Starting managed reverse tunnel"
    Start-Process -FilePath "powershell.exe" -ArgumentList @(
        "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", $TunnelScript
    ) -WorkingDirectory $Repo -WindowStyle Hidden
    Start-Sleep -Seconds 5
}
else {
    Log "Managed reverse tunnel already running"
}

Log "Fred-Win remote bootstrap completed"