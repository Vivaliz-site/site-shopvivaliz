$ErrorActionPreference = 'Continue'
$KeyPath = 'C:\Users\user\.ssh\ssh-key-2026-07-04.key'
$KnownHostsPath = 'C:\Users\user\.ssh\known_hosts'
$VMHost = '137.131.156.17'
$VMUser = 'ubuntu'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-managed-tunnel.log'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}
if (!(Test-Path -LiteralPath $KeyPath)) { Log 'ERROR private key missing'; exit 2 }
if (!(Test-Path -LiteralPath $KnownHostsPath)) { Log 'ERROR known_hosts missing'; exit 3 }

Log 'Managed reverse tunnel service started'
$attempt = 0
while ($true) {
    $attempt++
    Log ("Connecting attempt=$attempt forward=5558->127.0.0.1:5557")
    try {
        & ssh -i $KeyPath `
            -R 5558:127.0.0.1:5557 `
            -o 'BatchMode=yes' `
            -o 'ServerAliveInterval=30' `
            -o 'ServerAliveCountMax=3' `
            -o 'ExitOnForwardFailure=yes' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=" + $KnownHostsPath) `
            ${VMUser}@${VMHost} -N -T 2>&1 | ForEach-Object { Log 'SSH lifecycle message received' }
    } catch { Log ('ERROR tunnel exception: ' + $_.Exception.Message) }
    Log 'Tunnel disconnected; retrying in 10 seconds'
    Start-Sleep -Seconds 10
}
