param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('begin','finalize')]
    [string]$Phase,
    [Parameter(Mandatory=$true)]
    [ValidateSet('fred-win','kocepsv')]
    [string]$HostKey
)
$ErrorActionPreference = 'Stop'

if ($HostKey -eq 'fred-win') {
    $Package = '@wonderwhy-er/desktop-commander@0.2.48'
    $TaskNames = @('ShopVivaliz Desktop Commander 24h','ShopVivaliz Desktop Commander Task Guardian')
    $CooldownFiles = @(
        'C:\site-shopvivaliz\logs\desktop-commander-auth-required.cooldown',
        'C:\site-shopvivaliz\logs\desktop-commander-provider-connected.marker'
    )
} else {
    $Package = '@wonderwhy-er/desktop-commander@0.2.51'
    $TaskNames = @('ShopVivaliz DESKTOP-KOCEPSV Desktop Commander 24h')
    $CooldownFiles = @(
        (Join-Path $env:LOCALAPPDATA 'ShopVivaliz\DesktopCommander\logs\desktopkocepsv-desktop-commander-auth-required.cooldown'),
        (Join-Path $env:LOCALAPPDATA 'ShopVivaliz\DesktopCommander\logs\desktopkocepsv-desktop-commander-provider-connected.marker')
    )
}

$DeviceDir = Join-Path $env:USERPROFILE '.desktop-commander-device'
$DeviceFile = Join-Path $DeviceDir 'device.json'
$WorkDir = 'C:\site-shopvivaliz\logs'
$LinkFile = Join-Path $WorkDir 'dc-reauth-link.txt'
$StateFile = Join-Path $WorkDir 'dc-reauth-state.txt'
$PidFile = Join-Path $WorkDir 'dc-reauth-session.pid'
$SessionScript = Join-Path $WorkDir 'dc-reauth-session.ps1'
New-Item -ItemType Directory -Force -Path $DeviceDir,$WorkDir | Out-Null

function Stop-RemoteLaunchers {
    $candidates = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.Name) -in @('node.exe','cmd.exe') -and
        ([string]$_.CommandLine) -match '(@wonderwhy-er/desktop-commander|desktop-commander).*\bremote\b'
    })
    foreach ($p in $candidates) {
        try { & taskkill.exe /PID $p.ProcessId /T /F 2>$null | Out-Null } catch {}
    }
}

function Stop-ManualSession {
    if (Test-Path -LiteralPath $PidFile) {
        $raw = (Get-Content -LiteralPath $PidFile -Raw -ErrorAction SilentlyContinue).Trim()
        if ($raw -match '^\d+$') {
            try { Stop-Process -Id ([int]$raw) -Force -ErrorAction SilentlyContinue } catch {}
        }
    }
    Stop-RemoteLaunchers
    Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
}

if ($Phase -eq 'begin') {
    foreach ($name in $TaskNames) {
        $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($task) {
            Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
            Disable-ScheduledTask -TaskName $name | Out-Null
        }
    }
    Stop-ManualSession

    $npx = (Get-Command npx.cmd -ErrorAction Stop).Source
    & $npx --yes $Package remote --logout 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Desktop Commander logout failed: $LASTEXITCODE" }

    foreach ($path in $CooldownFiles) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $LinkFile,$StateFile,$PidFile,$SessionScript -Force -ErrorAction SilentlyContinue

    $child = @'
param([string]$Package,[string]$LinkFile,[string]$StateFile,[string]$Npx)
$ErrorActionPreference = 'Continue'
& $Npx --yes $Package remote --persist-session 2>&1 | ForEach-Object {
    $line = [string]$_
    $clean = [regex]::Replace($line, ([char]27).ToString() + '\[[0-9;]*[A-Za-z]', '')
    $match = [regex]::Match($clean, 'https://[^\s]+')
    if ($match.Success) {
        [IO.File]::WriteAllText($LinkFile, $match.Value.Trim())
    }
    if ($clean -match 'Device ready') {
        [IO.File]::WriteAllText($StateFile, 'DEVICE_READY')
    }
}
if (-not (Test-Path -LiteralPath $StateFile)) {
    [IO.File]::WriteAllText($StateFile, 'EXITED')
}
'@
    Set-Content -LiteralPath $SessionScript -Value $child -Encoding UTF8

    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes(
        "& '$SessionScript' -Package '$Package' -LinkFile '$LinkFile' -StateFile '$StateFile' -Npx '$npx'"
    ))
    $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile','-NonInteractive','-WindowStyle','Hidden','-EncodedCommand',$encoded
    ) -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath $PidFile -Value ([string]$proc.Id) -Encoding ASCII

    for ($i = 0; $i -lt 60; $i++) {
        if ((Test-Path -LiteralPath $LinkFile) -and (Get-Item -LiteralPath $LinkFile).Length -gt 0) { break }
        Start-Sleep -Seconds 1
    }
    if (-not (Test-Path -LiteralPath $LinkFile)) { throw 'Desktop Commander verification link was not produced' }
    $url = (Get-Content -LiteralPath $LinkFile -Raw).Trim()
    if ($url -notmatch '^https://') { throw 'Desktop Commander verification link is invalid' }
    Write-Output 'DC_REAUTH_BEGIN=PASS'
    exit 0
}

if (-not (Test-Path -LiteralPath $DeviceFile)) {
    throw 'Desktop Commander device state is absent; authorization has not completed'
}
Stop-ManualSession
Remove-Item -LiteralPath $LinkFile,$StateFile,$SessionScript -Force -ErrorAction SilentlyContinue
foreach ($name in $TaskNames) {
    $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if ($task) {
        Enable-ScheduledTask -TaskName $name | Out-Null
        Start-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 5
Write-Output 'DC_REAUTH_FINALIZE=PASS'
