param()
$ErrorActionPreference = 'Stop'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]$identity
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'administrator_required_for_openssh_recovery'
}

$svc = Get-Service -Name sshd -ErrorAction SilentlyContinue
if ($svc) {
    if ($svc.StartType -ne 'Automatic') { Set-Service -Name sshd -StartupType Automatic }
    if ($svc.Status -ne 'Running') { Start-Service -Name sshd }
    $svc = Get-Service -Name sshd -ErrorAction Stop
    if ($svc.Status -ne 'Running') { throw 'sshd_not_running_after_recovery' }
    Write-Output 'REMOTE_CONTROL_WINDOWS_SSHD=PASS'
    exit 0
}

$cap = Get-WindowsCapability -Online |
    Where-Object { $_.Name -like 'OpenSSH.Server*' } |
    Select-Object -First 1

if (-not $cap -or $cap.State -ne 'Installed') {
    $install = Add-WindowsCapability -Online -Name 'OpenSSH.Server~~~~0.0.1.0'
    Write-Output ('REMOTE_CONTROL_WINDOWS_OPENSSH_CAPABILITY_STATE=' + [string]$install.State)
    Write-Output ('REMOTE_CONTROL_WINDOWS_OPENSSH_RESTART_NEEDED=' + [string]$install.RestartNeeded)
}

$svc = Get-Service -Name sshd -ErrorAction SilentlyContinue
if (-not $svc) {
    $sshdExe = Join-Path $env:WINDIR 'System32\OpenSSH\sshd.exe'
    $sshKeygen = Join-Path $env:WINDIR 'System32\OpenSSH\ssh-keygen.exe'
    if (-not (Test-Path -LiteralPath $sshdExe)) {
        throw 'openssh_binary_missing_after_capability'
    }

    $programDataSsh = Join-Path $env:ProgramData 'ssh'
    New-Item -ItemType Directory -Force -Path $programDataSsh | Out-Null
    if (Test-Path -LiteralPath $sshKeygen) {
        & $sshKeygen -A | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'openssh_host_key_generation_failed' }
    }

    New-Service -Name sshd -BinaryPathName ('"' + $sshdExe + '"') -DisplayName 'OpenSSH SSH Server' -StartupType Automatic | Out-Null
    Write-Output 'REMOTE_CONTROL_WINDOWS_OPENSSH_SERVICE_REGISTER=PASS'
    $svc = Get-Service -Name sshd -ErrorAction Stop
}

if ($svc.StartType -ne 'Automatic') { Set-Service -Name sshd -StartupType Automatic }
if ($svc.Status -ne 'Running') { Start-Service -Name sshd }
$svc = Get-Service -Name sshd -ErrorAction Stop
if ($svc.Status -ne 'Running') { throw 'sshd_not_running_after_recovery' }

Write-Output ('REMOTE_CONTROL_WINDOWS_SSHD=PASS host=' + $env:COMPUTERNAME)
