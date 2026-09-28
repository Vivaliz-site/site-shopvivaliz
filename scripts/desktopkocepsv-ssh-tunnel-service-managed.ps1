$ErrorActionPreference = 'Continue'
$LogDir = 'C:\site-shopvivaliz\logs'
$LogFile = Join-Path $LogDir 'desktopkocepsv-managed-tunnel.log'
$RelayConfigFile = Join-Path $LogDir 'desktopkocepsv-relay-runtime.json'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

<#
The key path was a literal 'C:\Users\user\.ssh\...' -- only correct if the
Windows account on this host is actually named "user", which matches no
other convention in this repo (every other script here resolves
$env:USERPROFILE dynamically -- see Ensure-ProfileEnvironment in
desktopkocepsv-desktop-commander-supervisor.ps1 -- or hardcodes the one
known real username for that specific host, e.g. C:\Users\FRED\... in
ssh-tunnel-service-managed.ps1 for Fred-Win). A wrong key path here fails
Test-Path silently into 'ERROR private key missing' on every single
attempt, which is indistinguishable from the tunnel simply never starting
-- exactly the symptom observed (no listener at all on the VM's relay
port for this host). This session has no access to DESKTOP-KOCEPSV to
confirm which location is actually correct there, so check every
plausible one (current profile first, since that is the only
host-portable option) instead of guessing a single replacement.
#>
if (-not $env:USERPROFILE) {
    try {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath)
    } catch { }
}
$KeyCandidates = @(
    $(if ($env:USERPROFILE) { Join-Path $env:USERPROFILE '.ssh\ssh-key-2026-07-04.key' }),
    'C:\Users\FRED\Downloads\ssh-key-2026-07-04.key',
    'C:\Users\user\.ssh\ssh-key-2026-07-04.key'
) | Where-Object { $_ }
$KnownHostsCandidates = @(
    $(if ($env:USERPROFILE) { Join-Path $env:USERPROFILE '.ssh\known_hosts' }),
    'C:\Users\FRED\.ssh\known_hosts',
    'C:\Users\user\.ssh\known_hosts'
) | Where-Object { $_ }
$KeyPath = $null
$KnownHostsPath = $null
$VMHost = $null
$VMPortRaw = $null
$VMUser = $null

if (Test-Path -LiteralPath $RelayConfigFile) {
    try {
        $runtime = Get-Content -LiteralPath $RelayConfigFile -Raw | ConvertFrom-Json -ErrorAction Stop
        if ($runtime.key_path -and (Test-Path -LiteralPath ([string]$runtime.key_path))) { $KeyPath = [string]$runtime.key_path }
        if ($runtime.known_hosts_path -and (Test-Path -LiteralPath ([string]$runtime.known_hosts_path))) { $KnownHostsPath = [string]$runtime.known_hosts_path }
        if ($runtime.backend_host) { $VMHost = ([string]$runtime.backend_host).Trim() }
        if ($runtime.backend_port) { $VMPortRaw = ([string]$runtime.backend_port).Trim() }
        if ($runtime.backend_user) { $VMUser = ([string]$runtime.backend_user).Trim() }
        Log 'Loaded persisted connection metadata captured from working legacy tunnel'
    } catch {
        Log 'Persisted connection metadata is invalid; falling back to canonical discovery'
    }
}

if (-not $KeyPath) { $KeyPath = $KeyCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1 }
if (-not $KnownHostsPath) { $KnownHostsPath = $KnownHostsCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1 }

$DefaultBackendHost = '100.66.174.74'
$DefaultBackendPort = '22'
if ([string]::IsNullOrWhiteSpace($VMHost)) {
    $VMHost = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_HOST', 'Machine')
    $VMHost = $VMHost.Trim()
}
if ([string]::IsNullOrWhiteSpace($VMPortRaw)) {
    $VMPortRaw = [string][Environment]::GetEnvironmentVariable('SHOPVIVALIZ_BACKEND_SSH_PORT', 'Machine')
    $VMPortRaw = $VMPortRaw.Trim()
}
if ([string]::IsNullOrWhiteSpace($VMHost)) {
    $VMHost = $DefaultBackendHost
    Log 'SSH endpoint override missing; using canonical private backend Tailscale address'
}
if ([string]::IsNullOrWhiteSpace($VMPortRaw)) {
    $VMPortRaw = $DefaultBackendPort
    Log 'SSH endpoint port override missing; using canonical backend SSH port'
}
if ([string]::IsNullOrWhiteSpace($VMUser)) { $VMUser = 'ubuntu' }
$VMPort = [int]$VMPortRaw
$SshExe = 'C:\Program Files\Git\usr\bin\ssh.exe'
if (-not $KeyPath) { Log ('ERROR private key missing; checked: ' + ($KeyCandidates -join ' | ')); exit 2 }
if (-not $KnownHostsPath) { Log ('ERROR known_hosts missing; checked: ' + ($KnownHostsCandidates -join ' | ')); exit 3 }
if (!(Test-Path -LiteralPath $SshExe)) { Log 'ERROR Git SSH missing at managed path'; exit 4 }
Log ('Resolved key=' + $KeyPath + ' known_hosts=' + $KnownHostsPath)

Log 'Managed reverse tunnel service started'
$attempt = 0
while ($true) {
    $attempt++
    Log ("Connecting attempt=$attempt forwards=2223->127.0.0.1:22,5558->127.0.0.1:5557")
    try {
        & $SshExe -i $KeyPath -p $VMPort `
            -R 2223:127.0.0.1:22 `
            -R 5558:127.0.0.1:5557 `
            -o 'BatchMode=yes' `
            -o 'ServerAliveInterval=30' `
            -o 'ServerAliveCountMax=3' `
            -o 'ExitOnForwardFailure=yes' `
            -o 'StrictHostKeyChecking=yes' `
            -o ("UserKnownHostsFile=" + $KnownHostsPath) `
            ${VMUser}@${VMHost} -N -T 2>&1 | ForEach-Object { Log ('SSH lifecycle: ' + ([string]$_ -replace '(?i)(identity file\s+)[^\s]+','$1[REDACTED]')) }
    } catch { Log ('ERROR tunnel exception: ' + $_.Exception.Message) }
    Log 'Tunnel disconnected; retrying in 10 seconds'
    Start-Sleep -Seconds 10
}
