$ErrorActionPreference = "SilentlyContinue"
while ($true) {
    & "c:\site-shopvivaliz\scripts\local-auto-sync.ps1"
    Start-Sleep -Seconds 1800  # 30 minutos
}
