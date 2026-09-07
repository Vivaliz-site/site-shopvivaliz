param(
    [string]$InstallDir=(Join-Path $env:LOCALAPPDATA 'ShopVivaliz'),
    [int]$OrphanGuardMinutes=15
)

$ErrorActionPreference='Stop'
if($OrphanGuardMinutes -lt 5 -or $OrphanGuardMinutes -gt 60){throw 'OrphanGuardMinutes must be between 5 and 60.'}
$orphanSource=Join-Path $PSScriptRoot 'ai-session-orphan-guard.ps1'
$bootSource=Join-Path $PSScriptRoot 'ai-session-boot-cleanup.ps1'
foreach($p in @($orphanSource,$bootSource)){if(-not (Test-Path $p)){throw "Required source missing: $p"}}

New-Item -ItemType Directory -Force -Path $InstallDir | Out-Null
$orphan=Join-Path $InstallDir 'ai-session-orphan-guard.ps1'
$boot=Join-Path $InstallDir 'ai-session-boot-cleanup.ps1'
Copy-Item -Force $orphanSource $orphan
Copy-Item -Force $bootSource $boot
$currentUser=[System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal=New-ScheduledTaskPrincipal -UserId $currentUser -LogonType S4U -RunLevel Highest
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 5) -MultipleInstances IgnoreNew -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries

$orphanAction=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$orphan`""
$orphanTrigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes $OrphanGuardMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)
$orphanTask=New-ScheduledTask -Action $orphanAction -Trigger $orphanTrigger -Settings $settings -Principal $principal
Register-ScheduledTask -TaskName 'ShopVivaliz AI Session Orphan Guard' -InputObject $orphanTask -Force | Out-Null

$bootAction=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$boot`""
$bootTrigger=New-ScheduledTaskTrigger -AtLogOn -User $currentUser
$bootTask=New-ScheduledTask -Action $bootAction -Trigger $bootTrigger -Settings $settings -Principal $principal
Register-ScheduledTask -TaskName 'ShopVivaliz AI Session Boot Cleanup' -InputObject $bootTask -Force | Out-Null

Start-ScheduledTask -TaskName 'ShopVivaliz AI Session Orphan Guard'
Write-Output 'AI_SESSION_GUARDS_INSTALLED=true'
Write-Output "ORPHAN_GUARD_MINUTES=$OrphanGuardMinutes"
Write-Output 'BOOT_CLEANUP_MODE=ORPHANS_ONLY'
