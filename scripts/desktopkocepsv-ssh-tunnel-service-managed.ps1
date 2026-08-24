# DESKTOP-KOCEPSV -> Oracle VM private reverse relay.
# Local MCP stays on loopback 127.0.0.1:5557; the VM sees it only on loopback 5558.
$ErrorActionPreference = 'Continue'
$VMHost = '137.131.156.17'
$VMUser = 'ubuntu'
$KeyPath = Join-Path $env:USERPROFILE '.ssh\ssh-key-2026-07-04.key'
$KnownHostsPath = Join-Path $env:USERPROFILE '.ssh\known_hosts'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-managed-tunnel.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
if (-not (Test-Path -LiteralPath $KeyPath)) { Log 'ERROR private key missing'; exit 2 }
if (-not (Test-Path -LiteralPath $KnownHostsPath)) { Log 'ERROR known_hosts missing'; exit 3 }

$attempt = 0
while ($true) {
    $attempt++
    Log ('Connecting private relay attempt=' + $attempt)
    try {
        & ssh -i $KeyPath -R 5558:127.0.0.1:5557 `
            -o 'BatchMode=yes' `
            -o 'ServerAliveInterval=30' `
            -o 'ServerAliveCountMax=3' `
            -o 'ExitOnForwardFailure=yes' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=$KnownHostsPath") `
            ${VMUser}@${VMHost} -N -T 2>&1 | ForEach-Object { }
    } catch {
        Log ('Relay exception: ' + $_.Exception.GetType().Name)
    }
    Log 'Relay disconnected; retrying in 10 seconds'
    Start-Sleep -Seconds 10
}
