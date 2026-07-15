$Action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-NoProfile -ExecutionPolicy Bypass -File C:\Users\user\site-shopvivaliz\scripts\auto-sync-pc.ps1"
$Trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(5) -RepetitionInterval (New-TimeSpan -Minutes 5)
Register-ScheduledTask -TaskName "ShopVivaliz Auto Sync PC" -Action $Action -Trigger $Trigger -Description "Sincroniza PC com GitHub via PR seguro" -Force
