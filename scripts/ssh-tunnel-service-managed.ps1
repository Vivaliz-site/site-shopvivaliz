# ShopVivaliz managed reverse SSH tunnel (Fred-Win -> Oracle VM)
# Keeps maintenance paths private on VM loopback. No key material or command output is logged.

$ErrorActionPreference = 'Continue'
if ($env:COMPUTERNAME -ne 'LAPTOP-NIG4IFUU') {
    exit 41
}
$KeyPath = 'C:\Users\FRED\Downloads\ssh-key-2026-07-04.key'
$KnownHostsPath = 'C:\Users\FRED\.ssh\known_hosts'
$DefaultBackendHost = '100.66.174.74'
$DefaultBackendPort = '22'
$VMHost = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_HOST', 'Machine')
$VMPortRaw = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_PORT', 'Machine')
$VMHost = $VMHost.Trim()
$VMPortRaw = $VMPortRaw.Trim()
if ([string]::IsNullOrWhiteSpace($VMHost)) { $VMHost = $DefaultBackendHost }
if ([string]::IsNullOrWhiteSpace($VMPortRaw)) { $VMPortRaw = $DefaultBackendPort }
$VMUser = 'ubuntu'
$VMPort = [int]$VMPortRaw
$SshExe = 'C:\Program Files\Git\usr\bin\ssh.exe'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'fredwin-managed-tunnel.log'

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-TunnelLog {
    param([string]$Message)
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

if (!(Test-Path -LiteralPath $KeyPath)) {
    Write-TunnelLog 'ERROR private key missing at managed path'
    exit 2
}
if (!(Test-Path -LiteralPath $KnownHostsPath)) {
    Write-TunnelLog 'ERROR known_hosts missing; refusing unverified SSH'
    exit 3
}
if (!(Test-Path -LiteralPath $SshExe)) {
    Write-TunnelLog 'ERROR Git SSH missing at managed path'
    exit 4
}
Write-TunnelLog 'Managed reverse tunnel service started'
$attempt = 0
while ($true) {
    $attempt++
    Write-TunnelLog ("Connecting attempt=$attempt private-forwards=2222,5557")
    try {
        & $SshExe -i $KeyPath -p $VMPort `
            -R 2222:127.0.0.1:22 `
            -R 5557:127.0.0.1:5557 `
            -o 'BatchMode=yes' `
            -o 'ConnectTimeout=8' `
            -o 'ServerAliveInterval=15' `
            -o 'ServerAliveCountMax=2' `
            -o 'ExitOnForwardFailure=yes' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=" + $KnownHostsPath) `
            ${VMUser}@${VMHost} `
            -N -T 2>&1 | ForEach-Object {
                # Record only a reason class; never persist raw SSH output, paths or secrets.
                $line = [string]$_
                $reason = 'other'
                if ($line -match '(?i)remote port forwarding failed|cannot listen to port') {
                    $reason = 'remote_forward_bind_failed'
                } elseif ($line -match '(?i)timed out|timeout') {
                    $reason = 'transport_timeout'
                } elseif ($line -match '(?i)connection (reset|refused|closed)|broken pipe') {
                    $reason = 'transport_closed'
                } elseif ($line -match '(?i)host key verification failed|permission denied') {
                    $reason = 'ssh_authentication_failed'
                }
                Write-TunnelLog ("SSH lifecycle reason=" + $reason)
            }
        Write-TunnelLog ("SSH exit_code=" + [string]$LASTEXITCODE)
    }
    catch {
        Write-TunnelLog ('ERROR tunnel exception type=' + $_.Exception.GetType().Name)
    }
    Write-TunnelLog 'Tunnel disconnected; retrying in 5 seconds'
    Start-Sleep -Seconds 5
}
