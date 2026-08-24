# ShopVivaliz managed reverse SSH tunnel (Fred-Win -> Oracle VM)
# Keeps local MCP available on VM loopback with strict host verification.

$ErrorActionPreference = 'Continue'
$KeyPath = 'C:\Users\FRED\Downloads\ssh-key-2026-07-04.key'
$KnownHostsPath = 'C:\Users\FRED\.ssh\known_hosts'
$VMHost = '137.131.156.17'
$VMUser = 'ubuntu'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'fredwin-managed-tunnel.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-TunnelLog {
    param([string]$Message)
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

if (!(Test-Path -LiteralPath $KeyPath)) { Write-TunnelLog 'ERROR private key not found at expected path'; exit 2 }
if (!(Test-Path -LiteralPath $KnownHostsPath)) { Write-TunnelLog 'ERROR known_hosts not found at expected path'; exit 3 }

Write-TunnelLog 'Managed reverse tunnel service started'
$attempt = 0
while ($true) {
    $attempt++
    Write-TunnelLog ("Connecting attempt=$attempt forward=5557->127.0.0.1:5557")
    try {
        & ssh -i $KeyPath `
            -R 5557:127.0.0.1:5557 `
            -o 'BatchMode=yes' `
            -o 'ServerAliveInterval=30' `
            -o 'ServerAliveCountMax=3' `
            -o 'ExitOnForwardFailure=yes' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=" + $KnownHostsPath) `
            ${VMUser}@${VMHost} -N -T 2>&1 | ForEach-Object { Write-TunnelLog 'SSH lifecycle message received' }
    }
    catch { Write-TunnelLog ('ERROR tunnel exception: ' + $_.Exception.Message) }
    Write-TunnelLog 'Tunnel disconnected; retrying in 10 seconds'
    Start-Sleep -Seconds 10
}
