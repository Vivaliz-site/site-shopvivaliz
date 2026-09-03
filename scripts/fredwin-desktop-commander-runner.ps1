param()
$ErrorActionPreference = 'Continue'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$SupervisorLog = Join-Path $LogDir 'desktop-commander-supervisor.log'
$CooldownFile = Join-Path $LogDir 'desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktop-commander-provider-connected.marker'
$SessionPatcher = Join-Path $Repo 'scripts\patch-desktop-commander-session-persistence.mjs'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$AuthPattern = 'Persisted session invalid|Please complete authentication|Starting device authorization flow|device code|Authorization required'
$ReadyPattern = 'Device ready'
$DegradedPattern = 'InvalidJWTToken|Token has expired|Device marked as offline|Failed to (recreate|subscribe)|Subscription unhealthy'
$RecoverableChannelPattern = 'Channel (closed|errored)'
$RecoveredPattern = 'Device ready|Channel subscribed|recovered after'
$RefreshPersistAttemptPattern = 'SESSION_REFRESH_PERSIST_ATTEMPTED'
$RefreshPersistFailurePattern = 'SESSION_REFRESH_PERSIST_FAILED'
$AuthGraceSeconds = 300
$DegradedRestartSeconds = 180
$MarkerRefreshSeconds = 30
$TransportCheckIntervalSeconds = 10
$script:ProviderEntryPoint = $null
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
    $providerEntryPoint = Join-Path $packageRoot 'dist\index.js'
    if (-not (Test-Path -LiteralPath $providerEntryPoint)) { throw 'Desktop Commander provider entrypoint missing' }
    $patchOutput = @(& $node $SessionPatcher $packageRoot 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'Desktop Commander session persistence patch failed' }
    $patchState = [string]($patchOutput | Where-Object { [string]$_ -match '^SESSION_REFRESH_PATCH=' } | Select-Object -Last 1)
    if (-not $patchState) { throw 'Desktop Commander session persistence patch state missing' }
    $script:ProviderEntryPoint = $providerEntryPoint
    Log $patchState
}

function Start-HiddenCapturedProcess {
    if (-not $script:ProviderEntryPoint -or -not (Test-Path -LiteralPath $script:ProviderEntryPoint)) { throw 'Patched Desktop Commander provider entrypoint unavailable' }
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = $node
    $psi.Arguments = '"' + $script:ProviderEntryPoint + '" remote --persist-session'
    $psi.WorkingDirectory = $Repo
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $psi.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $p = New-Object System.Diagnostics.Process
    $p.StartInfo = $psi
    if (-not $p.Start()) { throw 'Desktop Commander process did not start' }
    return $p
}

try { Install-SessionRefreshPatch } catch { Log ('SESSION_REFRESH_PATCH=false reason=' + $_.Exception.Message); exit 22 }

Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
$proc = $null
$stdoutTask = $null
$stderrTask = $null
$authRequired = $false
$authStarted = $null
$readySeenAfterAuth = $false
$connected = $false
$degradedSinceUtc = $null
$transportDegradedSinceUtc = $null
$lastMarkerWriteUtc = [datetime]::MinValue
$lastTransportCheckUtc = [datetime]::MinValue
$lastDeviceStateWriteUtc = if ($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)) { (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc } else { [datetime]::MinValue }
$forcedExitCode = $null
$rc = 1

function Write-ConnectionMarker {
    if (-not $proc -or $proc.HasExited) { return }
    $utc = (Get-Date).ToUniversalTime()
    @('state=connected', ('updated_utc=' + $utc.ToString('o')), ('provider_pid=' + $proc.Id)) | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
    $script:lastMarkerWriteUtc = $utc
}

<#
Real transport-liveness check, independent of the provider's own log text.
Deliberately NOT an instant/strict gate (that pattern was already tried and
reverted in the supervisor for being fragile -- see
tests/fredwin-desktop-commander-stale-transport-contract-test.php, which
forbids Get-NetTCPConnection there): this runs throttled every
TransportCheckIntervalSeconds and only feeds the SAME tolerant
degraded/recovery grace window (DegradedRestartSeconds) the pattern-based
detector already uses, so a brief reconnect blip is not mistaken for a dead
channel. It closes the actual gap reported in production: a connection that
dies without ever printing one of DegradedPattern/RecoverableChannelPattern
left the marker refreshing forever on a bare timer, so the device looked
"healthy" locally while showing offline server-side. Fails OPEN (assumes
connected) on a real query error so a transient CIM hiccup cannot itself
trigger a restart -- but Get-NetTCPConnection reports the ordinary "zero
established connections right now" case as a (suppressed) non-terminating
error too (verified live: "No matching MSFT_NetTCPConnection objects
found..."), not just an empty result, and -ErrorAction Stop would turn
that expected case into a thrown exception caught by a blanket try/catch,
silently making this whole check inert. So catch the error explicitly and
only treat it as a real failure -- and therefore fail open -- when its
text is not that specific "no matching objects" message.
#>
function Test-BrokerTransportEstablished([int]$ProcessId) {
    $queryError = $null
    $conns = @(Get-NetTCPConnection -OwningProcess $ProcessId -State Established -ErrorAction SilentlyContinue -ErrorVariable queryError | Where-Object {
        $_.RemotePort -eq 443 -and $_.RemoteAddress -notin @('127.0.0.1', '::1')
    })
    if ($conns.Count -gt 0) { return $true }
    foreach ($e in @($queryError)) {
        if ($e.Exception.Message -notmatch 'No matching .* objects found') { return $true }
    }
    return $false
}

try {
    $proc = Start-HiddenCapturedProcess
    $stdoutTask = $proc.StandardOutput.ReadLineAsync()
    $stderrTask = $proc.StandardError.ReadLineAsync()

    while ($true) {
        $proc.Refresh()
        $lines = New-Object System.Collections.Generic.List[string]

        if ($stdoutTask -and $stdoutTask.IsCompleted) {
            $line = $stdoutTask.Result
            if ($null -ne $line) {
                [void]$lines.Add([string]$line)
                $stdoutTask = $proc.StandardOutput.ReadLineAsync()
            } else {
                $stdoutTask = $null
            }
        }
        if ($stderrTask -and $stderrTask.IsCompleted) {
            $line = $stderrTask.Result
            if ($null -ne $line) {
                [void]$lines.Add([string]$line)
                $stderrTask = $proc.StandardError.ReadLineAsync()
            } else {
                $stderrTask = $null
            }
        }

        $newText = ($lines -join "`n")
        if ($newText -match $AuthPattern) {
            if ($connected) {
                Log 'AUTH_SIGNAL_IGNORED connected provider remains healthy'
            } elseif (-not $authRequired) {
                $authRequired = $true
                $authStarted = Get-Date
                $readySeenAfterAuth = $false
                $connected = $false
                $degradedSinceUtc = $null
                $transportDegradedSinceUtc = $null
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
                $degradedSinceUtc = $null
                $transportDegradedSinceUtc = $null
                Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
                Write-ConnectionMarker
                Log 'Remote Desktop Commander broker ready observed'
            }
        }

        if ($authRequired) {
            if ($readySeenAfterAuth -and (Test-DeviceStateNewerThanCooldown)) {
                $authRequired = $false
                $authStarted = $null
                $readySeenAfterAuth = $false
                $connected = $true
                $degradedSinceUtc = $null
                $transportDegradedSinceUtc = $null
                Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
                Write-ConnectionMarker
                Log 'Remote Desktop Commander provider authorization completed during grace'
            } elseif ($authStarted -and (((Get-Date) - $authStarted).TotalSeconds -ge $AuthGraceSeconds)) {
                Log 'AUTH_REQUIRED provider authorization grace expired'
                try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
                try { $proc.WaitForExit(10000) | Out-Null } catch { }
                break
            }
        }

        if ($connected -and ($newText -match $RecoverableChannelPattern)) {
            Write-ConnectionMarker
            Log 'Provider channel transient event observed; provider kept alive'
        }
        if ($connected -and ($newText -match $DegradedPattern) -and -not $degradedSinceUtc) {
            $degradedSinceUtc = (Get-Date).ToUniversalTime()
            Log 'Provider channel degradation observed; recovery grace started'
        }
        if ($connected -and $degradedSinceUtc -and ($newText -match $RecoveredPattern)) {
            $degradedSinceUtc = $null
            Write-ConnectionMarker
            Log 'Provider channel recovery observed'
        }
        if ($connected -and ($newText -match $RefreshPersistAttemptPattern)) {
            $currentWrite = if ($DeviceFile -and (Test-Path -LiteralPath $DeviceFile)) { (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc } else { [datetime]::MinValue }
            if ($currentWrite -gt $lastDeviceStateWriteUtc) {
                $lastDeviceStateWriteUtc = $currentWrite
                Log 'SESSION_REFRESH_PERSISTED=true'
            } else {
                Log 'SESSION_REFRESH_PERSISTED=inconclusive reason=device_state_mtime_unchanged'
            }
        }
        if ($connected -and ($newText -match $RefreshPersistFailurePattern)) {
            Write-ConnectionMarker
            Log 'SESSION_REFRESH_PERSISTED=false reason=provider_persistence_failure'
        }
        if ($connected -and -not $proc.HasExited -and (((Get-Date).ToUniversalTime() - $lastTransportCheckUtc).TotalSeconds -ge $TransportCheckIntervalSeconds)) {
            $lastTransportCheckUtc = (Get-Date).ToUniversalTime()
            $transportUp = Test-BrokerTransportEstablished $proc.Id
            if (-not $transportUp -and -not $transportDegradedSinceUtc) {
                $transportDegradedSinceUtc = (Get-Date).ToUniversalTime()
                Log 'Provider channel degradation observed (no established transport); recovery grace started'
            } elseif ($transportUp -and $transportDegradedSinceUtc) {
                $transportDegradedSinceUtc = $null
                Log 'Provider channel recovery observed (transport)'
            }
        }
        if ($degradedSinceUtc) {
            $degradedAge = ((Get-Date).ToUniversalTime() - $degradedSinceUtc).TotalSeconds
            if ($degradedAge -ge $DegradedRestartSeconds) {
                Log ('Provider channel recovery timed out seconds=' + [math]::Round($degradedAge))
                $forcedExitCode = 23
                try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
                try { $proc.WaitForExit(10000) | Out-Null } catch { }
                break
            }
        }
        if ($transportDegradedSinceUtc) {
            $transportDegradedAge = ((Get-Date).ToUniversalTime() - $transportDegradedSinceUtc).TotalSeconds
            if ($transportDegradedAge -ge $DegradedRestartSeconds) {
                Log ('Provider channel recovery timed out seconds=' + [math]::Round($transportDegradedAge) + ' reason=no_established_transport')
                $forcedExitCode = 23
                try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
                try { $proc.WaitForExit(10000) | Out-Null } catch { }
                break
            }
        }
        if ($connected -and -not $degradedSinceUtc -and -not $transportDegradedSinceUtc -and (((Get-Date).ToUniversalTime() - $lastMarkerWriteUtc).TotalSeconds -ge $MarkerRefreshSeconds)) {
            Write-ConnectionMarker
        }

        if ($proc.HasExited -and -not $stdoutTask -and -not $stderrTask) { break }
        Start-Sleep -Milliseconds 200
    }

    $rc = if ($null -ne $forcedExitCode) { $forcedExitCode } elseif ($proc.HasExited) { $proc.ExitCode } else { 1 }
}
finally {
    if ($proc -and -not $proc.HasExited) {
        try { & taskkill.exe /PID $proc.Id /T /F 2>$null | Out-Null } catch { }
    }
    Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
    if ($proc) { $proc.Dispose() }
}
if ($authRequired) { exit 20 }
Log ('Remote Desktop Commander runner exited rc=' + $rc)
exit $rc
