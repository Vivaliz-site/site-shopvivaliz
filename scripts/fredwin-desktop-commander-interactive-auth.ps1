param(
    [ValidateSet('Bootstrap','InteractiveWorker')]
    [string]$Mode = 'Bootstrap'
)

$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$MainTask = 'ShopVivaliz Desktop Commander 24h'
$RelayTask = 'ShopVivaliz Fred-Win Relay 24h'
$InteractiveTask = 'ShopVivaliz DC Interactive Authorization'
$Runner = Join-Path $Repo 'scripts\fredwin-desktop-commander-runner.ps1'
$DeviceFile = Join-Path (Join-Path $env:USERPROFILE '.desktop-commander-device') 'device.json'
$CooldownFile = Join-Path $Repo 'logs\desktop-commander-auth-required.cooldown'
$ConnectedMarker = Join-Path $Repo 'logs\desktop-commander-provider-connected.marker'
$StateDir = 'C:\ProgramData\ShopVivaliz'
$ResultFile = Join-Path $StateDir 'dc-interactive-auth-result.txt'
$ExactPackagePattern = '@wonderwhy-er/desktop-commander@0\.2\.47.*\bremote\b.*--persist-session'
$AnyRemotePattern = '@wonderwhy-er/desktop-commander@[^ ]+.*\bremote\b'

function Write-ResultLine([string]$Line) {
    New-Item -ItemType Directory -Force -Path $StateDir | Out-Null
    Add-Content -LiteralPath $ResultFile -Value $Line -Encoding ascii
}

function Initialize-Result {
    New-Item -ItemType Directory -Force -Path $StateDir | Out-Null
    @(
        'STARTED=true',
        'BUTTON_CLICKED=false',
        'AUTH_COMPLETED=false',
        'CANONICAL_STARTED=false'
    ) | Set-Content -LiteralPath $ResultFile -Encoding ascii
}

function Get-RemoteProcesses {
    return @(Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.CommandLine) -match $AnyRemotePattern
    })
}

function Get-LauncherRoots([object[]]$Processes) {
    $items = @($Processes)
    if ($items.Count -le 1) { return $items }
    $ids = @($items | ForEach-Object { [int]$_.ProcessId })
    return @($items | Where-Object { $ids -notcontains [int]$_.ParentProcessId })
}

function Get-CanonicalRoots {
    return @(Get-LauncherRoots @(Get-RemoteProcesses | Where-Object {
        ([string]$_.CommandLine) -match $ExactPackagePattern
    }))
}

function Get-NonCanonicalRoots {
    $all = @(Get-RemoteProcesses)
    $canonicalIds = @($all | Where-Object { ([string]$_.CommandLine) -match $ExactPackagePattern } | ForEach-Object { [int]$_.ProcessId })
    return @(Get-LauncherRoots @($all | Where-Object { $canonicalIds -notcontains [int]$_.ProcessId }))
}

function Set-TaskHidden([string]$TaskName) {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) { return }
    if (-not $task.Settings.Hidden) {
        $task.Settings.Hidden = $true
        Set-ScheduledTask -InputObject $task | Out-Null
    }
}

if ($Mode -eq 'Bootstrap') {
    if (-not (Test-Path -LiteralPath $Runner)) { Write-Output 'BLOCKED=runner_missing'; exit 31 }
    if (-not (Test-Path -LiteralPath $DeviceFile)) { Write-Output 'BLOCKED=device_state_missing'; exit 32 }
    $main = Get-ScheduledTask -TaskName $MainTask -ErrorAction SilentlyContinue
    if (-not $main) { Write-Output 'BLOCKED=canonical_task_missing'; exit 33 }

    $interactiveSession = @(Get-Process explorer -IncludeUserName -ErrorAction SilentlyContinue | Where-Object {
        $_.SessionId -gt 0 -and ([string]$_.UserName) -match '\\FRED$'
    } | Select-Object -First 1)
    if ($interactiveSession.Count -ne 1) { Write-Output 'BLOCKED=interactive_user_session_missing'; exit 34 }

    Disable-ScheduledTask -TaskName $MainTask | Out-Null
    Stop-ScheduledTask -TaskName $MainTask -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2
    if (@(Get-RemoteProcesses).Count -gt 0) {
        Write-Output 'BLOCKED=existing_dc_launcher'
        exit 35
    }

    Remove-Item -LiteralPath $ResultFile -Force -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $InteractiveTask -Confirm:$false -ErrorAction SilentlyContinue

    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -ExecutionPolicy Bypass -File "' + $PSCommandPath + '" -Mode InteractiveWorker')
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(10)
    $principal = New-ScheduledTaskPrincipal -UserId ($env:COMPUTERNAME + '\FRED') -LogonType Interactive -RunLevel Highest
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -Hidden -ExecutionTimeLimit (New-TimeSpan -Minutes 8)
    Register-ScheduledTask -TaskName $InteractiveTask -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
    Start-ScheduledTask -TaskName $InteractiveTask
    Write-Output 'INTERACTIVE_AUTH_TASK_STARTED=true'

    $deadline = (Get-Date).AddMinutes(7)
    while ((Get-Date) -lt $deadline) {
        if (Test-Path -LiteralPath $ResultFile) {
            $safe = @(Get-Content -LiteralPath $ResultFile -ErrorAction SilentlyContinue | Where-Object {
                $_ -match '^(STARTED|BUTTON_CLICKED|AUTH_COMPLETED|CANONICAL_STARTED|BLOCKED)='
            })
            if ($safe -contains 'AUTH_COMPLETED=true' -and $safe -contains 'CANONICAL_STARTED=true') {
                $safe | Write-Output
                Unregister-ScheduledTask -TaskName $InteractiveTask -Confirm:$false -ErrorAction SilentlyContinue
                exit 0
            }
            if (@($safe | Where-Object { $_ -like 'BLOCKED=*' }).Count -gt 0) {
                $safe | Write-Output
                Unregister-ScheduledTask -TaskName $InteractiveTask -Confirm:$false -ErrorAction SilentlyContinue
                exit 36
            }
        }
        Start-Sleep -Seconds 2
    }
    Write-Output 'BLOCKED=interactive_authorization_timeout'
    Unregister-ScheduledTask -TaskName $InteractiveTask -Confirm:$false -ErrorAction SilentlyContinue
    exit 37
}

Initialize-Result
if (-not (Test-Path -LiteralPath $DeviceFile)) { Write-ResultLine 'BLOCKED=device_state_missing'; exit 40 }
if (@(Get-RemoteProcesses).Count -gt 0) { Write-ResultLine 'BLOCKED=existing_dc_launcher'; exit 41 }

$beforeMtime = (Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc
Add-Type -AssemblyName UIAutomationClient
Add-Type -AssemblyName UIAutomationTypes
$runnerProc = Start-Process powershell.exe -ArgumentList @('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$Runner) -WindowStyle Hidden -PassThru
$clicked = $false
$authDone = $false
try {
    $deadline = (Get-Date).AddMinutes(3)
    while ((Get-Date) -lt $deadline -and -not $runnerProc.HasExited) {
        $newer = (Test-Path -LiteralPath $DeviceFile) -and ((Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc -gt $beforeMtime)
        if ($newer -and (Test-Path -LiteralPath $ConnectedMarker) -and -not (Test-Path -LiteralPath $CooldownFile)) {
            $authDone = $true
            break
        }

        $root = [System.Windows.Automation.AutomationElement]::RootElement
        $condition = New-Object System.Windows.Automation.PropertyCondition(
            [System.Windows.Automation.AutomationElement]::ControlTypeProperty,
            [System.Windows.Automation.ControlType]::Button
        )
        $buttons = $root.FindAll([System.Windows.Automation.TreeScope]::Descendants, $condition)
        foreach ($button in $buttons) {
            if (([string]$button.Current.Name).Equals('Verify Device',[System.StringComparison]::OrdinalIgnoreCase)) {
                try {
                    $invoke = $button.GetCurrentPattern([System.Windows.Automation.InvokePattern]::Pattern)
                    if ($invoke) {
                        $invoke.Invoke()
                        $clicked = $true
                        (Get-Content -LiteralPath $ResultFile) -replace 'BUTTON_CLICKED=false','BUTTON_CLICKED=true' | Set-Content -LiteralPath $ResultFile -Encoding ascii
                        break
                    }
                } catch { }
            }
        }
        if ($clicked) { break }
        Start-Sleep -Milliseconds 750
    }

    if ($clicked -and -not $authDone) {
        $deadline2 = (Get-Date).AddMinutes(3)
        while ((Get-Date) -lt $deadline2 -and -not $runnerProc.HasExited) {
            $newer = (Test-Path -LiteralPath $DeviceFile) -and ((Get-Item -LiteralPath $DeviceFile).LastWriteTimeUtc -gt $beforeMtime)
            if ($newer -and (Test-Path -LiteralPath $ConnectedMarker) -and -not (Test-Path -LiteralPath $CooldownFile)) {
                $authDone = $true
                break
            }
            Start-Sleep -Seconds 1
        }
    }
}
finally {
    if (-not $runnerProc.HasExited) {
        & taskkill.exe /PID $runnerProc.Id /T /F 2>$null | Out-Null
    }
}

if (-not $authDone) {
    if (-not $clicked) { Write-ResultLine 'BLOCKED=verify_device_button_not_found' }
    else { Write-ResultLine 'BLOCKED=provider_authorization_not_confirmed' }
    exit 42
}
(Get-Content -LiteralPath $ResultFile) -replace 'AUTH_COMPLETED=false','AUTH_COMPLETED=true' | Set-Content -LiteralPath $ResultFile -Encoding ascii

Set-TaskHidden $MainTask
Set-TaskHidden $RelayTask
Remove-Item -LiteralPath $CooldownFile -Force -ErrorAction SilentlyContinue
Enable-ScheduledTask -TaskName $MainTask | Out-Null
Start-ScheduledTask -TaskName $MainTask

$deadline3 = (Get-Date).AddSeconds(75)
while ((Get-Date) -lt $deadline3) {
    $canonical = @(Get-CanonicalRoots)
    $noncanonical = @(Get-NonCanonicalRoots)
    $task = Get-ScheduledTask -TaskName $MainTask -ErrorAction SilentlyContinue
    $info = Get-ScheduledTaskInfo -TaskName $MainTask -ErrorAction SilentlyContinue
    if ($canonical.Count -eq 1 -and $noncanonical.Count -eq 0 -and $task -and $task.State -in @('Ready','Running') -and -not (Test-Path -LiteralPath $CooldownFile)) {
        (Get-Content -LiteralPath $ResultFile) -replace 'CANONICAL_STARTED=false','CANONICAL_STARTED=true' | Set-Content -LiteralPath $ResultFile -Encoding ascii
        exit 0
    }
    if ($info -and $info.LastTaskResult -eq 20) { break }
    Start-Sleep -Seconds 2
}
Write-ResultLine 'BLOCKED=canonical_reconnect_failed'
exit 43
