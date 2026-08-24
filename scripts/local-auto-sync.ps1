# ShopVivaliz Windows local auto-sync.
# Intended for the existing Task Scheduler entry at C:\site-shopvivaliz\scripts\local-auto-sync.ps1.
# Updates main with fast-forward only, enforces the Graph-only email guard, and then
# ensures the private Fred-Win remote path and canonical Desktop Commander stay healthy.

$ErrorActionPreference = "Continue"
$Repo = "C:\site-shopvivaliz"
$LogDir = Join-Path $Repo "logs"
$LogFile = Join-Path $LogDir ("local-sync-{0}.log" -f (Get-Date -Format "yyyy-MM-dd"))
$Bootstrap = Join-Path $Repo "scripts\fredwin-remote-bootstrap.ps1"
$DesktopCommanderSupervisor = Join-Path $Repo "scripts\fredwin-desktop-commander-supervisor.ps1"
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

    $CurrentBranch = & git rev-parse --abbrev-ref HEAD 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Failed to get current branch name" }
    $CurrentBranch = $CurrentBranch.ToString().Trim()
    Log "Current branch is: $CurrentBranch"

    & git fetch origin 2>&1 | ForEach-Object { Log "git fetch: $_" }
    if ($LASTEXITCODE -ne 0) { throw "git fetch failed exit=$LASTEXITCODE" }

    $DirtyOutput = & git status --porcelain 2>$null
    $WorkingTreeDirty = ($LASTEXITCODE -eq 0 -and [bool]$DirtyOutput)

    $Upstream = & git rev-parse --abbrev-ref --symbolic-full-name "@{u}" 2>$null
    if ($LASTEXITCODE -eq 0 -and $Upstream) {
        $Upstream = $Upstream.ToString().Trim()
        if ($WorkingTreeDirty) {
            Log "Working tree dirty; skipping fast-forward sync for $CurrentBranch; local changes preserved"
        } else {
            Log "Syncing current branch $CurrentBranch with upstream $Upstream"
            & git merge --ff-only $Upstream 2>&1 | ForEach-Object { Log "git merge: $_" }
            if ($LASTEXITCODE -ne 0) {
                Log "WARNING fast-forward failed for current branch $CurrentBranch; local changes preserved"
            }
        }
    } else {
        Log "No tracking branch configured for $CurrentBranch"
    }

    if ($CurrentBranch -ne "main") {
        if ($WorkingTreeDirty) {
            Log "Working tree dirty; skipping local main fast-forward update"
        } else {
            Log "Updating local main branch via fast-forward fetch"
            & git fetch origin main:main 2>&1 | ForEach-Object { Log "git fetch main:main: $_" }
            if ($LASTEXITCODE -ne 0) {
                Log "WARNING fast-forward update of main failed (possibly non-fast-forward)"
            }
        }
    }

    if (Test-Path $SmtpGuard) {
        Log "Running Graph-only SMTP guard"
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $SmtpGuard 2>&1 |
            ForEach-Object { Log "smtp-guard: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING SMTP guard exit=$LASTEXITCODE" }
    }
    else { Log "SMTP guard not present yet" }

    if (Test-Path $DesktopCommanderSupervisor) {
        Log "Ensuring canonical Fred-Win Desktop Commander remains healthy"
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $DesktopCommanderSupervisor -Mode Ensure 2>&1 |
            ForEach-Object { Log "desktop-commander: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING Desktop Commander supervisor exit=$LASTEXITCODE" }
    }
    else { Log "Desktop Commander supervisor not present yet" }

    if (Test-Path $Bootstrap) {
        Log "Repairing Fred-Win private relay task"
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $Bootstrap -Mode InstallTask 2>&1 |
            ForEach-Object { Log "bootstrap: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING bootstrap exit=$LASTEXITCODE" }
    }
    else { Log "Bootstrap not present yet" }

    Log "Auto-sync complete"
}
catch {
    Log "ERROR $($_.Exception.Message)"
    exit 1
}
