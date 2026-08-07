# ShopVivaliz managed reverse SSH tunnel (Fred-Win -> Oracle VM)
# Keeps both Windows SSH (diagnostic) and local MCP available only on VM loopback.
# No private key material is stored in the repository.

$ErrorActionPreference = "Continue"
$KeyPath = "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key"
$VMHost = "137.131.156.17"
$VMUser = "ubuntu"
$LogDir = "C:\site-shopvivaliz\logs"
$LogFile = Join-Path $LogDir "fredwin-managed-tunnel.log"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-TunnelLog {
    param([string]$Message)
    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

if (!(Test-Path $KeyPath)) {
    Write-TunnelLog "ERROR private key not found at expected path"
    exit 2
}

Write-TunnelLog "Managed reverse tunnel service started"
$attempt = 0
while ($true) {
    $attempt++
    Write-TunnelLog "Connecting attempt=$attempt VM=$VMUser@$VMHost forwards=2222->22,5557->5557"
    try {
        & ssh -i $KeyPath `
            -R 2222:localhost:22 `
            -R 5557:localhost:5557 `
            -o "BatchMode=yes" `
            -o "ServerAliveInterval=30" `
            -o "ServerAliveCountMax=3" `
            -o "ExitOnForwardFailure=yes" `
            -o "StrictHostKeyChecking=accept-new" `
            ${VMUser}@${VMHost} `
            -N -T 2>&1 | ForEach-Object { Write-TunnelLog "SSH: $_" }
    }
    catch {
        Write-TunnelLog "ERROR tunnel exception: $($_.Exception.Message)"
    }
    Write-TunnelLog "Tunnel disconnected; retrying in 10 seconds"
    Start-Sleep -Seconds 10
}
