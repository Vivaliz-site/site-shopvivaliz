param(
    [Parameter(Mandatory=$true)][string]$Name,
    [string]$RequireText = ''
)
$ErrorActionPreference = 'Stop'
$task = 'ShopVivaliz UI Click ' + ($Name -replace '[^A-Za-z0-9_-]','_')
$script = 'C:\site-shopvivaliz\scripts\fredwin-ui-click-named.ps1'
$log = 'C:\site-shopvivaliz\logs\fredwin-ui-click-named.log'
if (-not (Test-Path -LiteralPath $script)) { throw "SCRIPT_NOT_FOUND: $script" }
$user = (Get-CimInstance Win32_ComputerSystem).UserName
if ([string]::IsNullOrWhiteSpace($user)) { throw 'NO_INTERACTIVE_USER' }
try { Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue } catch {}
$escapedName = $Name.Replace('"','\"')
$escapedRequire = $RequireText.Replace('"','\"')
$args = '-NoProfile -ExecutionPolicy Bypass -File "' + $script + '" -Name "' + $escapedName + '"'
if (-not [string]::IsNullOrWhiteSpace($RequireText)) { $args += ' -RequireText "' + $escapedRequire + '"' }
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $args
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $task -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $task
Start-Sleep -Seconds 12
if (Test-Path -LiteralPath $log) { Get-Content -LiteralPath $log -Raw } else { Write-Output 'NO_UI_LOG' }
$info = Get-ScheduledTaskInfo -TaskName $task -ErrorAction SilentlyContinue
if ($info) { Write-Output ('TASK_RESULT=' + $info.LastTaskResult) }
try { Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue } catch {}
