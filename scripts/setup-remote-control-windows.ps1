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

$svc = Get-Service -Name sshd -ErrorAction Stop
if ($svc.StartType -ne 'Automatic') { Set-Service -Name sshd -StartupType Automatic }
if ($svc.Status -ne 'Running') { Start-Service -Name sshd }

Write-Output ('REMOTE_CONTROL_WINDOWS_HOST=' + $env:COMPUTERNAME)
Write-Output ('REMOTE_CONTROL_WINDOWS_USER=' + [Environment]::UserName)
Write-Output 'REMOTE_CONTROL_WINDOWS_ADMIN=true'
Write-Output 'REMOTE_CONTROL_WINDOWS_SSHD=running'
Write-Output 'REMOTE_CONTROL_WINDOWS_KEY_INSTALL=PASS'
