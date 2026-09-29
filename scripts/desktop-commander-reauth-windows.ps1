param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('begin','finalize')]
    [string]$Phase,
    [Parameter(Mandatory=$true)]
    [ValidateSet('fred-win','kocepsv')]
    [string]$HostKey,
    [string]$AuthPackageVersion = ''
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
if (-not [string]::IsNullOrWhiteSpace($AuthPackageVersion)) {
    if ($AuthPackageVersion -notmatch '^0\.2\.(48|49|50|51)
$DeviceFile = Join-Path $DeviceDir 'device.json'
$WorkDir = 'C:\site-shopvivaliz\logs'
$LinkFile = Join-Path $WorkDir 'dc-reauth-link.txt'
$StateFile = Join-Path $WorkDir 'dc-reauth-state.txt'
$PidFile = Join-Path $WorkDir 'dc-reauth-session.pid'
$SessionLog = Join-Path $WorkDir 'dc-reauth-session.log'
$SessionScript = Join-Path $WorkDir 'dc-reauth-session.ps1'
New-Item -ItemType Directory -Force -Path $DeviceDir,$WorkDir | Out-Null

function Stop-RemoteLaunchers {
    $candidates = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.Name) -in @('node.exe','cmd.exe','powershell.exe') -and
        ([string]$_.CommandLine) -match '(@wonderwhy-er/desktop-commander|desktop-commander).*\bremote\b'
    })
    foreach ($p in $candidates) {
        if ($p.ProcessId -eq $PID) { continue }
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

    Remove-Item -LiteralPath $DeviceFile -Force -ErrorAction SilentlyContinue
    foreach ($path in $CooldownFiles) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $LinkFile,$StateFile,$PidFile,$SessionLog,$SessionScript -Force -ErrorAction SilentlyContinue

    $npx = (Get-Command npx.cmd -ErrorAction Stop).Source
    $child = @'
param([string]$Package,[string]$SessionLog,[string]$Npx)
$ErrorActionPreference = 'Continue'
& $Npx --yes $Package remote --persist-session *> $SessionLog
'@
    Set-Content -LiteralPath $SessionScript -Value $child -Encoding UTF8

    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes(
        "& '$SessionScript' -Package '$Package' -SessionLog '$SessionLog' -Npx '$npx'"
    ))
    $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile','-NonInteractive','-WindowStyle','Hidden','-EncodedCommand',$encoded
    ) -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath $PidFile -Value ([string]$proc.Id) -Encoding ASCII

    $url = $null
    for ($i = 0; $i -lt 90; $i++) {
        if (Test-Path -LiteralPath $SessionLog) {
            $raw = Get-Content -LiteralPath $SessionLog -Raw -ErrorAction SilentlyContinue
            $clean = [regex]::Replace([string]$raw, ([char]27).ToString() + '\\[[0-?]*[ -/]*[@-~]', '')
            $match = [regex]::Match($clean, 'Verify this device in your browser:\\s*(https://\\S+)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
            if (-not $match.Success) {
                $match = [regex]::Match($clean, 'Please visit:\\s*(https://\\S+)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
            }
            if ($match.Success) {
                $url = $match.Groups[1].Value.Trim()
                break
            }
        }
        if ($proc.HasExited) { break }
        & timeout.exe /t 1 /nobreak 2>$null | Out-Null
    }

    if ([string]::IsNullOrWhiteSpace($url)) { throw 'Desktop Commander verification link was not produced' }
    [IO.File]::WriteAllText($LinkFile, $url)
    [IO.File]::WriteAllText($StateFile, 'AUTH_LINK_READY')
    Write-Output 'DC_REAUTH_BEGIN=PASS'
    exit 0
}

if (-not (Test-Path -LiteralPath $DeviceFile)) {
    throw 'Desktop Commander device state is absent; authorization has not completed'
}
Stop-ManualSession
Remove-Item -LiteralPath $LinkFile,$StateFile,$SessionLog,$SessionScript -Force -ErrorAction SilentlyContinue
foreach ($name in $TaskNames) {
    $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if ($task) {
        Enable-ScheduledTask -TaskName $name | Out-Null
        Start-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 5
Write-Output 'DC_REAUTH_FINALIZE=PASS'
) {
        throw 'Unsupported Desktop Commander auth package override'
    }
    $Package = "@wonderwhy-er/desktop-commander@$AuthPackageVersion"
}

$DeviceDir = Join-Path $env:USERPROFILE '.desktop-commander-device'
$DeviceFile = Join-Path $DeviceDir 'device.json'
$WorkDir = 'C:\site-shopvivaliz\logs'
$LinkFile = Join-Path $WorkDir 'dc-reauth-link.txt'
$StateFile = Join-Path $WorkDir 'dc-reauth-state.txt'
$PidFile = Join-Path $WorkDir 'dc-reauth-session.pid'
$SessionLog = Join-Path $WorkDir 'dc-reauth-session.log'
$SessionScript = Join-Path $WorkDir 'dc-reauth-session.ps1'
New-Item -ItemType Directory -Force -Path $DeviceDir,$WorkDir | Out-Null

function Stop-RemoteLaunchers {
    $candidates = @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.Name) -in @('node.exe','cmd.exe','powershell.exe') -and
        ([string]$_.CommandLine) -match '(@wonderwhy-er/desktop-commander|desktop-commander).*\bremote\b'
    })
    foreach ($p in $candidates) {
        if ($p.ProcessId -eq $PID) { continue }
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

    Remove-Item -LiteralPath $DeviceFile -Force -ErrorAction SilentlyContinue
    foreach ($path in $CooldownFiles) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $LinkFile,$StateFile,$PidFile,$SessionLog,$SessionScript -Force -ErrorAction SilentlyContinue

    $npx = (Get-Command npx.cmd -ErrorAction Stop).Source
    $child = @'
param([string]$Package,[string]$SessionLog,[string]$Npx)
$ErrorActionPreference = 'Continue'
& $Npx --yes $Package remote --persist-session *> $SessionLog
'@
    Set-Content -LiteralPath $SessionScript -Value $child -Encoding UTF8

    $encoded = [Convert]::ToBase64String([Text.Encoding]::Unicode.GetBytes(
        "& '$SessionScript' -Package '$Package' -SessionLog '$SessionLog' -Npx '$npx'"
    ))
    $proc = Start-Process -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile','-NonInteractive','-WindowStyle','Hidden','-EncodedCommand',$encoded
    ) -WindowStyle Hidden -PassThru
    Set-Content -LiteralPath $PidFile -Value ([string]$proc.Id) -Encoding ASCII

    $url = $null
    for ($i = 0; $i -lt 90; $i++) {
        if (Test-Path -LiteralPath $SessionLog) {
            $raw = Get-Content -LiteralPath $SessionLog -Raw -ErrorAction SilentlyContinue
            $clean = [regex]::Replace([string]$raw, ([char]27).ToString() + '\\[[0-?]*[ -/]*[@-~]', '')
            $match = [regex]::Match($clean, 'Verify this device in your browser:\\s*(https://\\S+)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
            if (-not $match.Success) {
                $match = [regex]::Match($clean, 'Please visit:\\s*(https://\\S+)', [Text.RegularExpressions.RegexOptions]::IgnoreCase)
            }
            if ($match.Success) {
                $url = $match.Groups[1].Value.Trim()
                break
            }
        }
        if ($proc.HasExited) { break }
        & timeout.exe /t 1 /nobreak 2>$null | Out-Null
    }

    if ([string]::IsNullOrWhiteSpace($url)) { throw 'Desktop Commander verification link was not produced' }
    [IO.File]::WriteAllText($LinkFile, $url)
    [IO.File]::WriteAllText($StateFile, 'AUTH_LINK_READY')
    Write-Output 'DC_REAUTH_BEGIN=PASS'
    exit 0
}

if (-not (Test-Path -LiteralPath $DeviceFile)) {
    throw 'Desktop Commander device state is absent; authorization has not completed'
}
Stop-ManualSession
Remove-Item -LiteralPath $LinkFile,$StateFile,$SessionLog,$SessionScript -Force -ErrorAction SilentlyContinue
foreach ($name in $TaskNames) {
    $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    if ($task) {
        Enable-ScheduledTask -TaskName $name | Out-Null
        Start-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    }
}
Start-Sleep -Seconds 5
Write-Output 'DC_REAUTH_FINALIZE=PASS'
