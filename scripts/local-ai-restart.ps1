$ErrorActionPreference = "Stop"
$Repo = "C:\site-shopvivaliz"
$StopScript = Join-Path $Repo "scripts\local-ai-stop.ps1"
$StartScript = Join-Path $Repo "scripts\local-ai-start.ps1"

& $StopScript
Start-Sleep -Seconds 2
& $StartScript
