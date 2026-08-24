param()
$ErrorActionPreference = 'Continue'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktopkocepsv-desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
}

$npx = (Get-Command npx.cmd -ErrorAction SilentlyContinue).Source
if (-not $npx) { $npx = (Get-Command npx -ErrorAction SilentlyContinue).Source }
if (-not $npx) { Log 'ERROR npx not found'; exit 3 }

$authRequired = $false
$connected = $false
& $npx --yes $Package remote --persist-session 2>&1 | ForEach-Object {
    $line = [string]$_
    if ($line -match 'Please complete authentication|Starting device authorization flow|device code|Authorization required') {
        $authRequired = $true
    }
    if ($line -match 'Device ready|Found persisted session|Connected to Remote MCP|WebSocket connected') {
        if (-not $connected) { Log 'Remote Desktop Commander provider connection observed'; $connected = $true }
    }
}
$rc = $LASTEXITCODE
if ($authRequired) {
    '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
    Log 'AUTH_REQUIRED provider requested device authorization; raw provider output discarded'
    exit 20
}
Log ('Remote Desktop Commander runner exited rc=' + $rc)
exit $rc
