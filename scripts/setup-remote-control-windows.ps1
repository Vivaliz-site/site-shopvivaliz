param(
  [Parameter(Mandatory=$true)][string]$PublicKeyBase64
)
$ErrorActionPreference='Stop'

function Decode-Text([string]$Value) {
  [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($Value))
}

$pub = (Decode-Text $PublicKeyBase64).Trim()
if ($pub -notmatch '^ssh-(ed25519|rsa)\s+') { throw 'invalid_public_key' }

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]$identity
$isAdmin = $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) { throw 'administrator_required' }

$userSsh = Join-Path $HOME '.ssh'
New-Item -ItemType Directory -Force -Path $userSsh | Out-Null
$userAuth = Join-Path $userSsh 'authorized_keys'
if (-not (Test-Path -LiteralPath $userAuth)) { New-Item -ItemType File -Force -Path $userAuth | Out-Null }
$userLines = @(Get-Content -LiteralPath $userAuth -ErrorAction SilentlyContinue | Where-Object { $_ -notmatch 'shopvivaliz-remote-control\s*$' })
@($userLines + $pub) | Set-Content -LiteralPath $userAuth -Encoding ascii

$programDataSsh = Join-Path $env:ProgramData 'ssh'
New-Item -ItemType Directory -Force -Path $programDataSsh | Out-Null
$adminAuth = Join-Path $programDataSsh 'administrators_authorized_keys'
if (-not (Test-Path -LiteralPath $adminAuth)) { New-Item -ItemType File -Force -Path $adminAuth | Out-Null }
$adminLines = @(Get-Content -LiteralPath $adminAuth -ErrorAction SilentlyContinue | Where-Object { $_ -notmatch 'shopvivaliz-remote-control\s*$' })
@($adminLines + $pub) | Set-Content -LiteralPath $adminAuth -Encoding ascii

& icacls.exe $adminAuth /inheritance:r | Out-Null
& icacls.exe $adminAuth /grant:r '*S-1-5-32-544:F' '*S-1-5-18:F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'administrators_authorized_keys_acl_failed' }

$svc = Get-Service -Name sshd -ErrorAction SilentlyContinue
$cap = Get-WindowsCapability -Online | Where-Object { $_.Name -like 'OpenSSH.Server*' } | Select-Object -First 1
if (-not $svc -and (-not $cap -or $cap.State -ne 'Installed')) {
  $install = Add-WindowsCapability -Online -Name 'OpenSSH.Server~~~~0.0.1.0'
  $cap = Get-WindowsCapability -Online | Where-Object { $_.Name -like 'OpenSSH.Server*' } | Select-Object -First 1
  if (-not $cap -or $cap.State -ne 'Installed') { throw 'openssh_server_capability_not_installed' }
  $svc = Get-Service -Name sshd -ErrorAction SilentlyContinue
}
if (-not $svc) {
  $openSshDir = Join-Path $env:SystemRoot 'System32\OpenSSH'
  $sshdExe = Join-Path $openSshDir 'sshd.exe'
  $keygenExe = Join-Path $openSshDir 'ssh-keygen.exe'
  if (-not (Test-Path -LiteralPath $sshdExe)) { throw 'openssh_server_binary_missing' }
  if (Test-Path -LiteralPath $keygenExe) {
    & $keygenExe -A | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'openssh_host_key_generation_failed' }
  }
  New-Service -Name sshd -BinaryPathName ('"' + $sshdExe + '"') -DisplayName 'OpenSSH SSH Server' -StartupType Automatic | Out-Null
  $svc = Get-Service -Name sshd -ErrorAction Stop
  Write-Output 'REMOTE_CONTROL_WINDOWS_SSHD_SERVICE_REPAIRED=PASS'
}
if ($svc.StartType -ne 'Automatic') { Set-Service -Name sshd -StartupType Automatic }
if ($svc.Status -ne 'Running') { Start-Service -Name sshd }
$svc = Get-Service -Name sshd -ErrorAction Stop
if ($svc.Status -ne 'Running') { throw 'sshd_not_running_after_recovery' }

Write-Output ('REMOTE_CONTROL_WINDOWS_HOST=' + $env:COMPUTERNAME)
Write-Output ('REMOTE_CONTROL_WINDOWS_USER=' + [Environment]::UserName)
Write-Output 'REMOTE_CONTROL_WINDOWS_ADMIN=true'
Write-Output 'REMOTE_CONTROL_WINDOWS_SSHD=running'
Write-Output 'REMOTE_CONTROL_WINDOWS_KEY_INSTALL=PASS'
