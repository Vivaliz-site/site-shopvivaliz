param()
$ErrorActionPreference = 'Continue'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktop-commander-provider-connected.marker'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$AuthPattern = 'Please complete authentication|Starting device authorization flow|Authorization required'
$ConnectedPattern = 'Device ready|Found persisted session|Connected to Remote MCP|WebSocket connected'
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
}$npx = (Get-Command npx.cmd -ErrorAction SilentlyContinue).Source
if (-not $npx) { $npx = (Get-Command npx -ErrorAction SilentlyContinue).Source }
if (-not $npx) { Log 'ERROR npx not found'; exit 3 }

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
        if ((-not $connected) -and ($text -match $ConnectedPattern)) {
            $connected = $true
            '' | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
            Log 'Remote Desktop Commander provider connection observed'
        }
        if ((-not $connected) -and ($text -match $AuthPattern)) {
            $authRequired = $true
            '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
            Log 'AUTH_REQUIRED provider requested device authorization before connection'
            try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }            try { $proc.WaitForExit(10000) | Out-Null } catch { }
            break
        }
        if ($proc.HasExited) { break }
        Start-Sleep -Seconds 1
    }
    $proc.Refresh()
    $text = Read-CapturedProviderText @($outFile,$errFile)
    if ((-not $connected) -and (-not $authRequired) -and ($text -match $AuthPattern)) {
        $authRequired = $true
        '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
        Log 'AUTH_REQUIRED provider requested device authorization before connection'
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