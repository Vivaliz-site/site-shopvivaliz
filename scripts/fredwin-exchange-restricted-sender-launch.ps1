param(
    [ValidateSet('probe','unblock')]
    [string]$Mode = 'probe'
)

$ErrorActionPreference = 'Stop'
$Work = 'C:\Temp\shopvivaliz-exchange-recovery'
$LogDir = 'C:\site-shopvivaliz\logs'
$Log = Join-Path $LogDir ("exchange-restricted-sender-{0}.log" -f $Mode)
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
Remove-Item $Log -Force -ErrorAction SilentlyContinue
Set-Location $Work
& py -3 '.\fredwin-exchange-restricted-sender.py' --mode $Mode *> $Log
exit $LASTEXITCODE
