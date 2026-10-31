param(
    [ValidateSet('Status', 'Ensure', 'InstallTask')]
    [string]$Mode = 'Status',
    [string]$ApprovedDeviceId = ''
)
$ErrorActionPreference = 'Stop'

# Fred-Win uses a private RDC device authorization, not the corporate Dev identity.
# No authentication tokens or secret-bearing session data may be logged or copied.
$ExpectedWindowsUser = 'FRED'
$ExpectedProviderEmail = 'fred.discouto@hotmail.com'
$TaskName = 'ShopVivaliz RDC Fred Only'
$ProgramDataRoot = if ([string]::IsNullOrWhiteSpace($env:ProgramData)) { 'C:\ProgramData' } else { $env:ProgramData }
$BindingPath = Join-Path $ProgramDataRoot 'ShopVivaliz\rdc-fred-device-id.txt'
$StatePath = Join-Path $env:USERPROFILE '.desktop-commander-device\device.json'
$ScriptPath = $PSCommandPath
$LogPath = 'C:\site-shopvivaliz\logs\rdc-fred-only.log'
$LegacyTaskNames = @(
    'ShopVivaliz Desktop Commander 24h',
    'ShopVivaliz Desktop Commander Task Guardian',
    'ShopVivaliz RDC Relink 052'
)

function Write-SafeLog([string]$Message) {
    $dir = Split-Path -Parent $LogPath
    if (-not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
    Add-Content -LiteralPath $LogPath -Value ('{0} {1}' -f (Get-Date -Format s), $Message) -Encoding UTF8
}

function Get-LocalDeviceId {
    if (-not (Test-Path -LiteralPath $StatePath)) {
        throw 'AUTH_REQUIRED: local RDC device state missing'
    }
    # Read only local identity metadata; never emit/return session or credentials.
    $saved = Get-Content -LiteralPath $StatePath -Raw | ConvertFrom-Json
    if ([string]::IsNullOrWhiteSpace([string]$saved.deviceId)) {
        throw 'AUTH_REQUIRED: local RDC device ID missing'
    }
    if ($null -eq $saved.session) {
        throw 'AUTH_REQUIRED: RDC session missing'
    }
    return [string]$saved.deviceId
}

function Assert-WindowsIdentity {
    $name = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    if ($name.Split('\')[-1] -ine $ExpectedWindowsUser) {
        throw 'IDENTITY_MISMATCH: RDC Fred-only requires Windows user FRED'
    }
}

function Assert-LegacyTasksDisabled {
    foreach ($name in $LegacyTaskNames) {
        $task = Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        if ($task -and $task.State -ne 'Disabled') {
            throw ('LEGACY_RDC_TASK_ACTIVE: ' + $name)
        }
    }
}

function Get-RemoteLaunchers {
    return @(Get-CimInstance Win32_Process -Filter "name = 'node.exe'" -ErrorAction Stop |
        Where-Object {
            $cmd = [string]$_.CommandLine
            $cmd -match '(?i)desktop-commander' -and $cmd -match '(?i)\bremote\b'
        })
}

function Assert-ApprovedBinding {
    Assert-WindowsIdentity
    Assert-LegacyTasksDisabled
    if (-not (Test-Path -LiteralPath $BindingPath)) {
        throw 'OWNER_APPROVAL_REQUIRED: Fred-only RDC binding not installed'
    }
    $expected = (Get-Content -LiteralPath $BindingPath -Raw).Trim()
    $parsed = [guid]::Empty
    if (-not [guid]::TryParse($expected, [ref]$parsed)) {
        throw 'INVALID_BINDING: Fred-only device ID is not a GUID'
    }
    if ((Get-LocalDeviceId) -ne $expected) {
        throw 'IDENTITY_MISMATCH: RDC device changed; refusing automatic reauthorization'
    }
}

if ($Mode -eq 'InstallTask') {
    Assert-WindowsIdentity
    Assert-LegacyTasksDisabled
    $parsed = [guid]::Empty
    if (-not [guid]::TryParse($ApprovedDeviceId, [ref]$parsed)) {
        throw 'OWNER_APPROVAL_REQUIRED: pass a verified Fred RDC device ID'
    }
    if ((Get-LocalDeviceId) -ne $ApprovedDeviceId) {
        throw 'IDENTITY_MISMATCH: locally saved device does not match approved Fred device'
    }
    # The operator must first verify provider identity using the authenticated
    # RDC who_am_i connector and the same live device ID; do not infer from JWT.
    $directory = Split-Path -Parent $BindingPath
    if (-not (Test-Path -LiteralPath $directory)) {
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
    }
    if (Test-Path -LiteralPath $BindingPath) {
        $existing = (Get-Content -LiteralPath $BindingPath -Raw).Trim()
        if ($existing -ne $ApprovedDeviceId) {
            throw 'IDENTITY_MISMATCH: binding already exists; manual review required'
        }
    } else {
        Set-Content -LiteralPath $BindingPath -Value $ApprovedDeviceId -Encoding ASCII
    }
    $windowsUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $arguments = '-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $ScriptPath + '" -Mode Ensure'
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments
    $onLogon = New-ScheduledTaskTrigger -AtLogOn -User $windowsUser
    $periodic = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
    $principal = New-ScheduledTaskPrincipal -UserId $windowsUser -LogonType Interactive -RunLevel Limited
    $settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger @($onLogon, $periodic) -Principal $principal -Settings $settings -Description 'Fred-only RDC recovery. Never changes account, logs out or kills a running session.' -Force | Out-Null
    Write-SafeLog 'Fred-only RDC watchdog installed without interrupting the active session'
    Write-Output 'FRED_ONLY_RDC_WATCHDOG_INSTALLED=true'
    return
}

Assert-ApprovedBinding
$launchers = @(Get-RemoteLaunchers)
if ($Mode -eq 'Status') {
    Write-Output 'RDC_FRED_DEVICE_BINDING=VERIFIED'
    Write-Output ('REMOTE_LAUNCHER_COUNT=' + $launchers.Count)
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Write-Output ('FRED_WATCHDOG_TASK=' + $(if ($task) { $task.State } else { 'NOT_INSTALLED' }))
    return
}

# Safe idempotent recovery: do not kill, replace, log out or re-pair a session.
if ($launchers.Count -gt 0) {
    Write-Output ('RDC_LAUNCHER_ALREADY_RUNNING=true COUNT=' + $launchers.Count)
    return
}
$npx = Join-Path $env:ProgramFiles 'nodejs\npx.cmd'
if (-not (Test-Path -LiteralPath $npx)) {
    throw 'RUNTIME_MISSING: Node npx.cmd unavailable'
}
Start-Process -FilePath $npx -ArgumentList @('--yes', '@wonderwhy-er/desktop-commander@0.3.1', 'remote') -WorkingDirectory 'C:\site-shopvivaliz' -WindowStyle Hidden
Start-Sleep -Seconds 3
if (@(Get-RemoteLaunchers).Count -eq 0) {
    throw 'RDC_RECOVERY_FAILED: remote launcher did not start'
}
Write-SafeLog 'Restarted approved Fred device launcher after complete process exit'
Write-Output 'RDC_FRED_LAUNCHER_RESTARTED=true'
