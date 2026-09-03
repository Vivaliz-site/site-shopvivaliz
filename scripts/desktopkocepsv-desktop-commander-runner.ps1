param()
$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$Package = '@wonderwhy-er/desktop-commander@0.2.47'
$MaxLogBytes = 5MB
$AuthPattern = 'Persisted session invalid|Authenticating with Remote MCP server|Please complete authentication|Starting device authorization flow|device code|Authorization required'
$ConnectedPattern = 'Device ready'
$DegradedPattern = 'InvalidJWTToken|Token has expired|Device marked as offline|Failed to (recreate|subscribe)|Subscription unhealthy'
$RecoverableChannelPattern = 'Channel (closed|errored)'
$RecoveredPattern = 'Device ready|Channel subscribed|recovered after'
$RefreshPersistAttemptPattern = 'SESSION_REFRESH_PERSIST_ATTEMPTED'
$RefreshPersistFailurePattern = 'SESSION_REFRESH_PERSIST_FAILED'
$DegradedRestartSeconds = 180
$MarkerRefreshSeconds = 30
$TransportCheckIntervalSeconds = 10

function Ensure-ProfileEnvironment {
    if (-not $env:USERPROFILE) {
        $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        $profileKey = "HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion\ProfileList\$sid"
        $profile = (Get-ItemProperty -LiteralPath $profileKey -Name ProfileImagePath -ErrorAction Stop).ProfileImagePath
        $env:USERPROFILE = [Environment]::ExpandEnvironmentVariables($profile)
    }
    if (-not $env:LOCALAPPDATA) { $env:LOCALAPPDATA = Join-Path $env:USERPROFILE 'AppData\Local' }
    $env:HOME = $env:USERPROFILE
}

Ensure-ProfileEnvironment
$InstallRoot = Join-Path $env:LOCALAPPDATA 'ShopVivaliz\DesktopCommander'
$LogDir = Join-Path $InstallRoot 'logs'
$SupervisorLog = Join-Path $LogDir 'desktopkocepsv-desktop-commander.log'
$CooldownFile = Join-Path $LogDir 'desktopkocepsv-desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $LogDir 'desktopkocepsv-desktop-commander-provider-connected.marker'
$SessionPatcher = Join-Path $PSScriptRoot 'patch-desktop-commander-session-persistence.mjs'
$PackageRootHint = Join-Path $InstallRoot 'package-root.txt'
$DeviceFile = Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json'
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log([string]$Message) {
    $mutex = New-Object System.Threading.Mutex($false, 'Local\ShopVivalizDesktopCommanderLog')
    $locked = $false
    try {
        try { $locked = $mutex.WaitOne(5000) } catch [System.Threading.AbandonedMutexException] { $locked = $true }
        if (-not $locked) { return }
        if ((Test-Path -LiteralPath $SupervisorLog) -and (Get-Item -LiteralPath $SupervisorLog).Length -ge $MaxLogBytes) {
            $rotated = $SupervisorLog + '.1'
            Remove-Item -LiteralPath $rotated -Force -ErrorAction SilentlyContinue
            Move-Item -LiteralPath $SupervisorLog -Destination $rotated -Force
        }
        $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
        "$stamp - $Message" | Out-File -FilePath $SupervisorLog -Append -Encoding utf8
    }
    finally {
        if ($locked) { try { $mutex.ReleaseMutex() } catch { } }
        $mutex.Dispose()
    }
}

function Install-SessionRefreshPatch {
    $node = (Get-Command node.exe -ErrorAction SilentlyContinue).Source
    if (-not $node) { $node = (Get-Command node -ErrorAction SilentlyContinue).Source }
    if (-not $node) { throw 'node not found' }
    if (-not (Test-Path -LiteralPath $SessionPatcher)) { throw 'patcher missing' }
    $savedErrorPreference = $ErrorActionPreference
    $packageRoot = $null
    if (Test-Path -LiteralPath $PackageRootHint) {
        $candidate = [string](Get-Content -LiteralPath $PackageRootHint -Raw -ErrorAction SilentlyContinue)
        $candidate = $candidate.Trim()
        if ($candidate) {
            $candidateManifest = Join-Path $candidate 'package.json'
            $candidateEntry = Join-Path $candidate 'dist\index.js'
            if ((Test-Path -LiteralPath $candidateManifest) -and (Test-Path -LiteralPath $candidateEntry)) {
                try {
                    $candidateIdentity = Get-Content -LiteralPath $candidateManifest -Raw | ConvertFrom-Json
                    if ($candidateIdentity.name -eq '@wonderwhy-er/desktop-commander' -and $candidateIdentity.version -eq '0.2.47') {
                        $packageRoot = $candidate
                        Log 'PACKAGE_RESOLUTION=verified_hint'
                    }
                }
                catch { }
            }
        }
    }
    if (-not $packageRoot) {
        $npx = (Get-Command npx.cmd -ErrorAction SilentlyContinue).Source
        if (-not $npx) { $npx = (Get-Command npx -ErrorAction SilentlyContinue).Source }
        if (-not $npx) { throw 'npx not found' }
        $probeScript = "process.stdout.write(process.env.PATH.split(require('path').delimiter)[0])"
        $probeArgs = @('--yes','--package',$Package,'--','node','-e',$probeScript)
        $ErrorActionPreference = 'Continue'
        try {
            $probeOutput = @(& $npx @probeArgs 2>$null)
            $probeExitCode = $LASTEXITCODE
        }
        finally { $ErrorActionPreference = $savedErrorPreference }
        if ($probeExitCode -ne 0) { throw "Desktop Commander package probe failed rc=$probeExitCode" }
        $binDir = [string]($probeOutput | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } | Select-Object -Last 1)
        $binDir = $binDir.Trim()
        if (-not $binDir) { throw 'Desktop Commander package bin path missing' }
        $packageRoot = Join-Path (Split-Path -Parent $binDir) '@wonderwhy-er\desktop-commander'
        $packageManifest = Join-Path $packageRoot 'package.json'
        $entryPoint = Join-Path $packageRoot 'dist\index.js'
        if (-not (Test-Path -LiteralPath $packageManifest) -or -not (Test-Path -LiteralPath $entryPoint)) { throw 'Desktop Commander package root incomplete' }
        $manifest = Get-Content -LiteralPath $packageManifest -Raw | ConvertFrom-Json
        if ($manifest.name -ne '@wonderwhy-er/desktop-commander' -or $manifest.version -ne '0.2.47') { throw 'Desktop Commander package identity mismatch' }
        $packageRoot | Out-File -FilePath $PackageRootHint -Force -Encoding ascii
        Log 'PACKAGE_RESOLUTION=npx_then_hint_saved'
    }
    $entryPoint = Join-Path $packageRoot 'dist\index.js'

    $ErrorActionPreference = 'Continue'
    try {
        $patchOutput = @(& $node $SessionPatcher $packageRoot 2>&1)
        $patchExitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $savedErrorPreference }
    if ($patchExitCode -ne 0) { throw "Desktop Commander session persistence patch failed rc=$patchExitCode" }
    $patchState = [string]($patchOutput | Where-Object { [string]$_ -match '^SESSION_REFRESH_PATCH=' } | Select-Object -Last 1)
    if (-not $patchState) { throw 'Desktop Commander session persistence patch state missing' }
    Log $patchState
    return [pscustomobject]@{ Node = $node; EntryPoint = $entryPoint }
}

$script:ProviderProcess = $null
$script:Connected = $false
$script:AuthRequired = $false
$script:DegradedSinceUtc = $null
$script:TransportDegradedSinceUtc = $null
$script:LastTransportCheckUtc = [datetime]::MinValue
$script:PersistenceDegraded = $false
$script:LastMarkerWriteUtc = [datetime]::MinValue
$script:LastDeviceStateWriteUtc = if (Test-Path -LiteralPath $DeviceFile) { (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc } else { [datetime]::MinValue }

function Write-ConnectionMarker {
    if (-not $script:ProviderProcess) { return }
    $utc = (Get-Date).ToUniversalTime()
    @(
        'state=connected',
        ('updated_utc=' + $utc.ToString('o')),
        ('runner_pid=' + $PID),
        ('provider_pid=' + $script:ProviderProcess.Id),
        ('persistence_state=' + $(if ($script:PersistenceDegraded) { 'degraded' } else { 'healthy' }))
    ) | Out-File -FilePath $ConnectedMarker -Force -Encoding ascii
    $script:LastMarkerWriteUtc = $utc
}

<#
Real transport-liveness check, independent of the provider's own log text.
Deliberately throttled + tolerant (not an instant/strict gate -- that
pattern was already tried and reverted in the fredwin supervisor for being
fragile; see tests/fredwin-desktop-commander-stale-transport-contract-test.php)
so a brief reconnect blip is not mistaken for a dead channel. It feeds the
same DegradedRestartSeconds grace window used by the pattern-based
detector, closing the gap where a connection dying without ever printing
one of DegradedPattern/RecoverableChannelPattern left the marker refreshing
forever on a bare timer. Fails OPEN (assumes connected) on a real query
error -- but Get-NetTCPConnection reports the ordinary "zero established
connections right now" case as a (suppressed) non-terminating error too
(verified live: "No matching MSFT_NetTCPConnection objects found..."),
not just an empty result, and -ErrorAction Stop would turn that expected
case into a thrown exception caught by a blanket try/catch, silently
making this whole check inert. So catch the error explicitly and only
fail open when its text is not that specific "no matching objects"
message.
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

function Observe-ProviderLine([string]$Line) {
    if ([string]::IsNullOrWhiteSpace($Line)) { return }
    if ((-not $script:Connected) -and ($Line -match $ConnectedPattern)) {
        $script:Connected = $true
        $script:DegradedSinceUtc = $null
        $script:TransportDegradedSinceUtc = $null
        Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
        if (Test-Path -LiteralPath $DeviceFile) { $script:LastDeviceStateWriteUtc = (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc }
        Write-ConnectionMarker
        Log 'Remote Desktop Commander provider connection observed'
    }
    if ((-not $script:Connected) -and ($Line -match $AuthPattern)) {
        $script:AuthRequired = $true
        '' | Out-File -FilePath $CooldownFile -Force -Encoding ascii
        Log 'AUTH_REQUIRED provider requested device authorization before connection; raw provider output discarded'
    }
    if ($script:Connected -and ($Line -match $RecoverableChannelPattern)) {
        Write-ConnectionMarker
        Log 'Provider channel transient event observed; provider kept alive'
    }
    if ($script:Connected -and ($Line -match $DegradedPattern) -and -not $script:DegradedSinceUtc) {
        $script:DegradedSinceUtc = (Get-Date).ToUniversalTime()
        Log 'Provider channel degradation observed; recovery grace started'
    }
    if ($script:Connected -and $script:DegradedSinceUtc -and ($Line -match $RecoveredPattern)) {
        $script:DegradedSinceUtc = $null
        Write-ConnectionMarker
        Log 'Provider channel recovery observed'
    }
    if ($script:Connected -and ($Line -match $RefreshPersistAttemptPattern)) {
        $currentWrite = if (Test-Path -LiteralPath $DeviceFile) { (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc } else { [datetime]::MinValue }
        if ($currentWrite -gt $script:LastDeviceStateWriteUtc) {
            $wasPersistenceDegraded = $script:PersistenceDegraded
            $script:LastDeviceStateWriteUtc = $currentWrite
            $script:PersistenceDegraded = $false
            Log 'SESSION_REFRESH_PERSISTED=true'
            if ($wasPersistenceDegraded) { Log 'SESSION_REFRESH_PERSISTENCE_RECOVERED=true' }
            Write-ConnectionMarker
        }
        else {
            $script:PersistenceDegraded = $true
            Log 'SESSION_REFRESH_PERSISTED=inconclusive reason=device_state_mtime_unchanged'
            Write-ConnectionMarker
        }
    }
    if ($script:Connected -and ($Line -match $RefreshPersistFailurePattern)) {
        $script:PersistenceDegraded = $true
        Write-ConnectionMarker
        Log 'SESSION_REFRESH_PERSISTED=false reason=provider_persistence_failure'
    }
}

function Stop-ProviderTree {
    if (-not $script:ProviderProcess -or $script:ProviderProcess.HasExited) { return }
    try { & taskkill.exe /PID $script:ProviderProcess.Id /T /F 2>$null | Out-Null } catch { }
    try { [void]$script:ProviderProcess.WaitForExit(10000) } catch { }
}

try { $launch = Install-SessionRefreshPatch }
catch { Log ('SESSION_REFRESH_PATCH=false reason=' + $_.Exception.Message); exit 22 }

Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
$rc = 1
$forcedExitCode = $null
$stdoutTask = $null
$stderrTask = $null
try {
    $startInfo = New-Object System.Diagnostics.ProcessStartInfo
    $startInfo.FileName = $launch.Node
    $startInfo.Arguments = '"' + $launch.EntryPoint.Replace('"', '\"') + '" remote --persist-session'
    $startInfo.WorkingDirectory = $Repo
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.WindowStyle = [System.Diagnostics.ProcessWindowStyle]::Hidden
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $script:ProviderProcess = New-Object System.Diagnostics.Process
    $script:ProviderProcess.StartInfo = $startInfo
    if (-not $script:ProviderProcess.Start()) { throw 'Desktop Commander provider process did not start' }

    # Raw provider output is never persisted; only bounded, sanitized state transitions are logged.
    $stdoutTask = $script:ProviderProcess.StandardOutput.ReadLineAsync()
    $stderrTask = $script:ProviderProcess.StandardError.ReadLineAsync()
    while ($true) {
        if ($stdoutTask -and $stdoutTask.IsCompleted) {
            try { $line = $stdoutTask.GetAwaiter().GetResult() } catch { $line = $null }
            if ($null -eq $line) { $stdoutTask = $null }
            else { Observe-ProviderLine $line; $stdoutTask = $script:ProviderProcess.StandardOutput.ReadLineAsync() }
        }
        if ($stderrTask -and $stderrTask.IsCompleted) {
            try { $line = $stderrTask.GetAwaiter().GetResult() } catch { $line = $null }
            if ($null -eq $line) { $stderrTask = $null }
            else { Observe-ProviderLine $line; $stderrTask = $script:ProviderProcess.StandardError.ReadLineAsync() }
        }
        if ($script:AuthRequired) {
            $forcedExitCode = 20
            Stop-ProviderTree
        }
        if ($script:Connected -and $script:ProviderProcess -and -not $script:ProviderProcess.HasExited -and (((Get-Date).ToUniversalTime() - $script:LastTransportCheckUtc).TotalSeconds -ge $TransportCheckIntervalSeconds)) {
            $script:LastTransportCheckUtc = (Get-Date).ToUniversalTime()
            $transportUp = Test-BrokerTransportEstablished $script:ProviderProcess.Id
            if (-not $transportUp -and -not $script:TransportDegradedSinceUtc) {
                $script:TransportDegradedSinceUtc = (Get-Date).ToUniversalTime()
                Log 'Provider channel degradation observed (no established transport); recovery grace started'
            } elseif ($transportUp -and $script:TransportDegradedSinceUtc) {
                $script:TransportDegradedSinceUtc = $null
                Log 'Provider channel recovery observed (transport)'
            }
        }
        if ($script:DegradedSinceUtc) {
            $degradedAge = ((Get-Date).ToUniversalTime() - $script:DegradedSinceUtc).TotalSeconds
            if ($degradedAge -ge $DegradedRestartSeconds) {
                Log ('Provider channel recovery timed out seconds=' + [math]::Round($degradedAge))
                $forcedExitCode = 23
                Stop-ProviderTree
            }
        }
        if ($script:TransportDegradedSinceUtc) {
            $transportDegradedAge = ((Get-Date).ToUniversalTime() - $script:TransportDegradedSinceUtc).TotalSeconds
            if ($transportDegradedAge -ge $DegradedRestartSeconds) {
                Log ('Provider channel recovery timed out seconds=' + [math]::Round($transportDegradedAge) + ' reason=no_established_transport')
                $forcedExitCode = 23
                Stop-ProviderTree
            }
        }
        if ($script:Connected -and -not $script:DegradedSinceUtc -and -not $script:TransportDegradedSinceUtc -and (((Get-Date).ToUniversalTime() - $script:LastMarkerWriteUtc).TotalSeconds -ge $MarkerRefreshSeconds)) {
            Write-ConnectionMarker
        }
        $script:ProviderProcess.Refresh()
        if ($script:ProviderProcess.HasExited -and -not $stdoutTask -and -not $stderrTask) { break }
        Start-Sleep -Milliseconds 100
    }
    $script:ProviderProcess.WaitForExit()
    $rc = if ($null -ne $forcedExitCode) { $forcedExitCode } else { [int]$script:ProviderProcess.ExitCode }
}
catch {
    Log ('Remote Desktop Commander runner exception type=' + $_.Exception.GetType().Name)
    Stop-ProviderTree
    $rc = if ($null -ne $forcedExitCode) { $forcedExitCode } else { 24 }
}
finally {
    Remove-Item -LiteralPath $ConnectedMarker -Force -ErrorAction SilentlyContinue
    if ($script:ProviderProcess) { $script:ProviderProcess.Dispose() }
}
Log ('Remote Desktop Commander runner exited rc=' + $rc)
exit $rc
