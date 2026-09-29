<#
Installs the ChatGPT continuity bridge worker as a Windows scheduled task.

This does NOT launch or configure the browser itself: it assumes you
already have a Chrome/Edge/Opera window open, logged into your own
chatgpt.com account, started with a remote-debugging port open (e.g.
--remote-debugging-port=9223). The worker only ATTACHES to that existing
tab via CDP -- it deliberately never spawns its own browser instance,
which would be a separate, logged-out session instead of your real
conversation.

Usage (run once, elevated PowerShell, after creating bridge.token with
the same value as the CHATGPT_CONTINUITY_BRIDGE_TOKEN secret configured
on the production site):

    New-Item -ItemType Directory -Force C:\ShopVivaliz\chatgpt-continuity-bridge
    Set-Content -NoNewline C:\ShopVivaliz\chatgpt-continuity-bridge\bridge.token '<the token>'
    powershell -ExecutionPolicy Bypass -File .\scripts\install-chatgpt-continuity-windows-bridge.ps1
#>
param(
    [string]$WorkerSource = "$PSScriptRoot\chatgpt-continuity\chatgpt-continuity-bridge-worker.mjs",
    [string]$InstallDir = 'C:\ShopVivaliz\chatgpt-continuity-bridge',
    [string]$TaskName = 'ShopVivaliz ChatGPT Continuity Bridge',
    [string]$CdpUrl = 'http://127.0.0.1:9223'
)

$ErrorActionPreference = 'Stop'
$node = (Get-Command node.exe -ErrorAction Stop).Source
$token = Join-Path $InstallDir 'bridge.token'
$worker = Join-Path $InstallDir 'chatgpt-continuity-bridge-worker.mjs'
$logDir = Join-Path $InstallDir 'logs'

foreach ($required in @($WorkerSource, $token)) {
    if (-not (Test-Path $required)) { throw "Required bridge dependency missing: $required" }
}
New-Item -ItemType Directory -Force $InstallDir, $logDir | Out-Null
Copy-Item -Force $WorkerSource $worker
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$tokenAcl = Get-Acl $token
$tokenAcl.SetAccessRuleProtection($true, $false)
$tokenRule = New-Object System.Security.AccessControl.FileSystemAccessRule($currentUser, 'Read', 'Allow')
$tokenAcl.SetAccessRule($tokenRule)
Set-Acl -Path $token -AclObject $tokenAcl

$runner = Join-Path $InstallDir 'run-bridge.ps1'
$runnerBody = @"
`$ErrorActionPreference = 'Stop'
`$env:CHATGPT_CONTINUITY_BRIDGE_TOKEN_FILE = '$token'
`$env:CHATGPT_CONTINUITY_CDP_URL = '$CdpUrl'
Set-Location '$InstallDir'
& '$node' '$worker' *>> '$logDir\bridge.log'
exit `$LASTEXITCODE
"@
[IO.File]::WriteAllText($runner, $runnerBody, [Text.UTF8Encoding]::new($false))
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$runner`""
$triggers = @(
    (New-ScheduledTaskTrigger -AtStartup),
    (New-ScheduledTaskTrigger -AtLogOn -User $currentUser)
)
$settings = New-ScheduledTaskSettingsSet -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Limited
$task = New-ScheduledTask -Action $action -Trigger $triggers -Settings $settings -Principal $principal
Register-ScheduledTask -TaskName $TaskName -InputObject $task -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName
Start-Sleep -Seconds 4
$state = (Get-ScheduledTask -TaskName $TaskName).State
if ($state -notin @('Running','Ready')) { throw "Bridge task failed to start: $state" }
Write-Output "CHATGPT_CONTINUITY_WINDOWS_BRIDGE_INSTALLED=true"
Write-Output "TASK_STATE=$state"
Write-Output "BRIDGE_HOST=$env:COMPUTERNAME"
Write-Output "CDP_URL=$CdpUrl"
Write-Output "REMINDER: launch your ChatGPT browser with --remote-debugging-port=9223 (or matching -CdpUrl) for the worker to attach to it."
