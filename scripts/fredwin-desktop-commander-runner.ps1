param()
$ErrorActionPreference = 'Continue'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktop-commander-provider-connected.marker'
$SessionPatcher = Join-Path $Repo 'scripts\patch-desktop-commander-session-persistence.mjs'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$AuthPattern = 'Please complete authentication|Starting device authorization flow|device code|Authorization required'
$ReadyPattern = 'Device ready'
$AuthGraceSeconds = 300
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
}

if (-not $env:USERPROFILE) {
    try {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables((Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath).ProfileImagePath)
    } catch { }
}
$DeviceFile = if ($env:USERPROFILE) { Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json' } else { $null }

function Test-DeviceStateNewerThanCooldown {
    if (-not $DeviceFile -or -not (Test-Path -LiteralPath $DeviceFile) -or -not (Test-Path -LiteralPath $CooldownFile)) { return $false }
    return ((Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc -gt (Get-Item -LiteralPath $CooldownFile).LastWriteTimeUtc)
}

$readOffsets = @{}
function Read-NewCapturedProviderText([string[]]$Paths) {
    $parts = @()
    foreach ($path in $Paths) {
        if (-not (Test-Path -LiteralPath $path)) { continue }
        try {
            $content = [System.IO.File]::ReadAllText($path)
            $offset = 0
            if ($script:readOffsets.ContainsKey($path)) { $offset = [int]$script:readOffsets[$path] }
            if ($content.Length -lt $offset) { $offset = 0 }
            if ($content.Length -gt $offset) {
                $parts += $content.Substring($offset)
                $script:readOffsets[$path] = $content.Length
            }
        } catch { }
    }
    return ($parts -join "`n")
}

$npx = (Get-Command npx.cmd -ErrorAction SilentlyContinue).Source
if (-not $npx) { $npx = (Get-Command npx -ErrorAction SilentlyContinue).Source }
if (-not $npx) { Log 'ERROR npx not found'; exit 3 }
$node = (Get-Command node.exe -ErrorAction SilentlyContinue).Source
if (-not $node) { $node = (Get-Command node -ErrorAction SilentlyContinue).Source }
if (-not $node) { Log 'ERROR node not found'; exit 3 }
if (-not (Test-Path -LiteralPath $SessionPatcher)) { Log 'SESSION_REFRESH_PATCH=false reason=patcher_missing'; exit 22 }

function Install-SessionRefreshPatch {
    $probeScript = "process.stdout.write(process.env.PATH.split(require('path').delimiter)[0])"
    $probeArgs = @('--yes','--package',$Package,'--','node','-e',$probeScript)
    $probeOutput = @(& $npx @probeArgs 2>$null)
    if ($LASTEXITCODE -ne 0) { throw 'Desktop Commander package probe failed' }
    $binDir = [string]($probeOutput | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } | Select-Object -Last 1)
    $binDir = $binDir.Trim()
    if (-not $binDir) { throw 'Desktop Commander package bin path missing' }
    $nodeModules = Split-Path -Parent $binDir
    $packageRoot = Join-Path $nodeModules '@wonderwhy-er\desktop-commander'
    if (-not (Test-Path -LiteralPath (Join-Path $packageRoot 'package.json'))) { throw 'Desktop Commander package root missing' }
    $patchOutput = @(& $node $SessionPatcher $packageRoot 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'Desktop Commander session persistence patch failed' }
    $patchState = [string]($patchOutput | Where-Object { [string]$_ -match '^SESSION_REFRESH_PATCH=' } | Select-Object -Last 1)
    if (-not $patchState) { throw 'Desktop Commander session persistence patch state missing' }
    Log $patchState
}

try { Install-SessionRefreshPatch } catch { Log ('SESSION_REFRESH_PATCH=false reason=' + $_.Exception.Message); exit 22 }

$tmpBase = Join-Path $env:TEMP ('shopvivaliz-dc-' + [guid]::NewGuid().ToString('N'))
$outFile = $tmpBase + '.out'
$errFile = $tmpBase + '.err'
Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
$proc = $null
$authRequired = $false
$authStarted = $null
$readySeenAfterAuth = $false
$connected = $false
$rc = 1
try {
    $proc = Start-Process -FilePath $npx -ArgumentList @('--yes',$Package,'remote','--persist-session') -WorkingDirectory $Repo -WindowStyle Hidden -RedirectStandardOutput $outFile -RedirectStandardError $errFile -PassThru
    while ($true) {
        $proc.Refresh()
        $newText = Read-NewCapturedProviderText @($outFile,$errFile)

        if ($newText -match $AuthPattern) {
            if (-not $authRequired) {
                $authRequired = $true
                $authStarted = Get-Date
                $readySeenAfterAuth = $false
                $connected = $false
                Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
                '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
                Log 'AUTH_REQUIRED provider authorization grace started'
            }
        }

        if ($newText -match $ReadyPattern) {
            if ($authRequired) {
                $readySeenAfterAuth = $true
            } else {
                $connected = $true
                Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
                '' | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
                Log 'Remote Desktop Commander broker ready observed'
            }
        }

        if ($authRequired) {
            if ($readySeenAfterAuth -and (Test-DeviceStateNewerThanCooldown)) {
                $authRequired = $false
                $authStarted = $null
                $readySeenAfterAuth = $false
                $connected = $true
                Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
                '' | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
                Log 'Remote Desktop Commander provider authorization completed during grace'
            } elseif ($authStarted -and (((Get-Date) - $authStarted).TotalSeconds -ge $AuthGraceSeconds)) {
                Log 'AUTH_REQUIRED provider authorization grace expired'
                try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
                try { $proc.WaitForExit(10000) | Out-Null } catch { }
                break
            }
        }

        if ($proc.HasExited) { break }
        Start-Sleep -Seconds 1
    }

    $proc.Refresh()
    $newText = Read-NewCapturedProviderText @($outFile,$errFile)
    if ($newText -match $AuthPattern) {
        if (-not $authRequired) {
            $authRequired = $true
            '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
            Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
            $connected = $false
            Log 'AUTH_REQUIRED provider requested device authorization before exit'
        }
    }
    if ($proc.HasExited) { $rc = $proc.ExitCode }
}
finally {
    Remove-Item -LiteralPath $outFile,$errFile -Force -ErrorAction SilentlyContinue
    if ($proc -and $proc.HasExited) { Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue }
}
if ($authRequired) { exit 20 }
Log ('Remote Desktop Commander runner exited rc=' + $rc)
exit $rc
