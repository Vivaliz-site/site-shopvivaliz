param(
    [ValidateSet('probe','upload')]
    [string]$Mode = 'probe'
)

$ErrorActionPreference = 'Stop'
$Repo = 'C:\site-shopvivaliz'
$LogDir = Join-Path $Repo 'logs'
$Log = Join-Path $LogDir ("entra-uia-{0}.log" -f $Mode)
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
Remove-Item $Log -Force -ErrorAction SilentlyContinue
Set-Location $Repo
& py -3 '.\scripts\fredwin-entra-uia.py' --mode $Mode *> $Log
exit $LASTEXITCODE
