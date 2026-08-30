param()
$ErrorActionPreference = 'Continue'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktopkocepsv-desktop-commander.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktopkocepsv-desktop-commander-provider-connected.marker'
$SessionPatcher = Join-Path $Repo 'scripts\patch-desktop-commander-session-persistence.mjs'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$AuthPattern = 'Please complete authentication|Starting device authorization flow|device code|Authorization required|Persisted session invalid|Authenticating with Remote MCP server'
$ConnectedPattern = 'Device ready'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
}
function Read-CapturedProviderText([string[]]$Paths) {
    $parts = @()
    foreach ($path in $Paths) {
        if (Test-Path -LiteralPath $path) {
            try { $parts += (Get-Content -LiteralPath $path -Raw -ErrorAction Stop) } catch { }
        }
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
$connected = $false
$rc = 1
try {
    $proc = Start-Process -FilePath $npx -ArgumentList @('--yes',$Package,'remote','--persist-session') -WorkingDirectory $Repo -WindowStyle Hidden -RedirectStandardOutput $outFile -RedirectStandardError $errFile -PassThru
    while ($true) {
        $proc.Refresh()
        $text = Read-CapturedProviderText @($outFile,$errFile)
        if ($text -match $AuthPattern) {
            $authRequired = $true
            '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
            Log 'AUTH_REQUIRED provider requested device authorization; raw provider output discarded'
            try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
            try { $proc.WaitForExit(10000) | Out-Null } catch { }
            break
        }
        if ((-not $connected) -and ($text -match $ConnectedPattern)) {
            $connected = $true
            Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
            '' | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
            Log 'Remote Desktop Commander broker ready observed'
        }
        if ($proc.HasExited) { break }
        Start-Sleep -Seconds 1
    }
    $proc.Refresh()
    $text = Read-CapturedProviderText @($outFile,$errFile)
    if ((-not $authRequired) -and ($text -match $AuthPattern)) {
        $authRequired = $true
        '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
        Log 'AUTH_REQUIRED provider requested device authorization; raw provider output discarded'
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
