# Keep the optional Windows CLI session auditor installed but disabled by default.
# Automatic termination was revoked by the owner on 2026-11-06.
# Desktop apps and service processes remain untouched.
param([int]$IntervalMinutes=60)
$ErrorActionPreference='Stop'
if($IntervalMinutes -lt 15 -or $IntervalMinutes -gt 1440){
  throw 'IntervalMinutes outside 15..1440'
}
$source=Join-Path $PSScriptRoot 'agent-session-ttl.ps1'
if(-not (Test-Path -LiteralPath $source)){throw 'TTL source script missing'}
$targetDir=Join-Path $env:LOCALAPPDATA 'ShopVivaliz'
New-Item -ItemType Directory -Force -Path $targetDir | Out-Null
$target=Join-Path $targetDir 'agent-session-ttl.ps1'
if([IO.Path]::GetFullPath($source) -ine [IO.Path]::GetFullPath($target)){
  if(Test-Path -LiteralPath $target){
    Copy-Item -LiteralPath $target -Destination ($target+'.bak.'+(Get-Date -Format 'yyyyMMddHHmmss'))
  }
  Copy-Item -LiteralPath $source -Destination $target -Force
}
$dryRun=@(& $target)
if(-not ($dryRun -match 'MODE=dry-run')){
  throw 'TTL dry-run failed; not installing scheduled task'
}
$currentUser=[Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal=New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Limited
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument ('-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "{0}"' -f $target)
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$task=New-ScheduledTask -Action $action -Trigger $trigger -Settings $settings -Principal $principal
$name='ShopVivaliz Agent CLI Session TTL 24h'
Register-ScheduledTask -TaskName $name -InputObject $task -Force | Out-Null
Disable-ScheduledTask -TaskName $name -ErrorAction Stop | Out-Null
$readback=Get-ScheduledTask -TaskName $name -ErrorAction Stop
if($readback.Actions[0].Arguments -notlike '*agent-session-ttl.ps1*' -or
  $readback.Actions[0].Arguments -like '*-Apply*' -or
  $readback.State -ne 'Disabled'){
  throw 'Scheduled task readback mismatch'
}
Write-Output 'AGENT_SESSION_TTL_SCHEDULED=false'
Write-Output "INTERVAL_MINUTES=$IntervalMinutes"
Write-Output ('TASK_STATE='+(Get-ScheduledTask -TaskName $name).State)
