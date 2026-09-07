param(
    [switch]$DryRun,
    [switch]$NoDelay
)

$ErrorActionPreference='Stop'
$logDir=Join-Path $env:LOCALAPPDATA 'ShopVivaliz'
$stateFile=Join-Path $logDir 'ai-session-last-boot.txt'
$guard=Join-Path $PSScriptRoot 'ai-session-orphan-guard.ps1'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
if(-not (Test-Path $guard)){throw "Orphan guard missing: $guard"}

$bootId=(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToUniversalTime().ToString('o')
if(-not $DryRun -and (Test-Path $stateFile)){
    $lastBoot=(Get-Content $stateFile -Raw).Trim()
    if($lastBoot -eq $bootId){
        Write-Output 'ALREADY_CLEANED_THIS_BOOT'
        exit 0
    }
}

if(-not $NoDelay -and -not $DryRun){Start-Sleep -Seconds 120}
if($DryRun){
    & $guard -DryRun
    exit $LASTEXITCODE
}

& $guard
if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}
$bootId | Set-Content -Path $stateFile -Encoding ascii
Write-Output 'BOOT_ORPHAN_CLEANUP_COMPLETE'
exit 0
