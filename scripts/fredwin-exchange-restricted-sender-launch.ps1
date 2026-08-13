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

function Write-RecoveryLog {
    param([string]$Message)
    $stamp = Get-Date -Format o
    Add-Content -LiteralPath $Log -Value ("{0} {1}" -f $stamp, $Message) -Encoding UTF8
}

try {
    Write-RecoveryLog "LAUNCHER_START mode=$Mode user=$([System.Security.Principal.WindowsIdentity]::GetCurrent().Name)"
    Write-RecoveryLog "WORK_EXISTS=$(Test-Path -LiteralPath $Work)"
    $script = Join-Path $Work 'fredwin-exchange-restricted-sender.py'
    Write-RecoveryLog "SCRIPT_EXISTS=$(Test-Path -LiteralPath $script)"
    if (-not (Test-Path -LiteralPath $script)) {
        throw "Recovery Python script missing: $script"
    }

    $py = Get-Command py.exe -ErrorAction SilentlyContinue
    if ($null -eq $py) { $py = Get-Command py -ErrorAction SilentlyContinue }
    if ($null -eq $py) { throw 'Python launcher py.exe not found for interactive user' }
    Write-RecoveryLog "PY_SOURCE=$($py.Source)"

    Set-Location $Work
    Write-RecoveryLog 'PYTHON_EXEC_BEGIN'
    & $py.Source -3 $script --mode $Mode 2>&1 | ForEach-Object {
        Add-Content -LiteralPath $Log -Value ([string]$_) -Encoding UTF8
    }
    $rc = $LASTEXITCODE
    Write-RecoveryLog "PYTHON_EXEC_END rc=$rc"
    exit $rc
}
catch {
    Write-RecoveryLog ("LAUNCHER_ERROR=" + $_.Exception.Message.Replace("`r", ' ').Replace("`n", ' '))
    Write-RecoveryLog ("LAUNCHER_STACK=" + $_.ScriptStackTrace.Replace("`r", ' ').Replace("`n", ' '))
    exit 99
}
