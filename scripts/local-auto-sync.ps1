# ShopVivaliz Windows local auto-sync.
# Intended for the existing Task Scheduler entry at C:\site-shopvivaliz\scripts\local-auto-sync.ps1.
# Updates main with fast-forward only, enforces the Graph-only email guard, and then
# repairs the private Fred-Win remote path.

$ErrorActionPreference = "Continue"
$Repo = "C:\site-shopvivaliz"
$LogDir = Join-Path $Repo "logs"
$LogFile = Join-Path $LogDir ("local-sync-{0}.log" -f (Get-Date -Format "yyyy-MM-dd"))
$Bootstrap = Join-Path $Repo "scripts\fredwin-remote-bootstrap.ps1"
$SmtpGuard = Join-Path $Repo "scripts\fredwin-email-smtp-guard.ps1"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Log {
    param([string]$Message)
    $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$stamp - $Message" | Out-File -FilePath $LogFile -Append -Encoding utf8
}

try {
    if (!(Test-Path (Join-Path $Repo ".git"))) {
        Log "ERROR repository not found at $Repo"
        exit 2
    }

    Set-Location $Repo
    Log "Auto-sync start"
    & git fetch origin main 2>&1 | ForEach-Object { Log "git fetch: $_" }
    if ($LASTEXITCODE -ne 0) { throw "git fetch failed exit=$LASTEXITCODE" }

    # Preserve local work: only fast-forward. Do not hard reset from automation.
    & git merge --ff-only origin/main 2>&1 | ForEach-Object { Log "git merge: $_" }
    if ($LASTEXITCODE -ne 0) {
        Log "WARNING fast-forward failed; local changes/divergence preserved"
    }

    # Enforce Graph-only mail before any other Fred-Win automation.
    if (Test-Path $SmtpGuard) {
        Log "Running Graph-only SMTP guard"
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $SmtpGuard 2>&1 |
            ForEach-Object { Log "smtp-guard: $_" }
        if ($LASTEXITCODE -ne 0) {
            Log "WARNING SMTP guard exit=$LASTEXITCODE"
        }
    }
    else {
        Log "SMTP guard not present yet"
    }

    # The bootstrap may have arrived in this very sync.
    if (Test-Path $Bootstrap) {
        Log "Running Fred-Win remote bootstrap"
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Bootstrap 2>&1 |
            ForEach-Object { Log "bootstrap: $_" }
        if ($LASTEXITCODE -ne 0) {
            Log "WARNING bootstrap exit=$LASTEXITCODE"
        }
    }
    else {
        Log "Bootstrap not present yet"
    }

    Log "Auto-sync complete"
}
catch {
    Log "ERROR $($_.Exception.Message)"
    exit 1
}
