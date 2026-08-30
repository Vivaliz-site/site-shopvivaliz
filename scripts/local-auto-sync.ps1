# ShopVivaliz Windows local auto-sync.
# Intended for the existing Task Scheduler entry at C:\site-shopvivaliz\scripts\local-auto-sync.ps1.
# Updates main with fast-forward only, enforces the Graph-only email guard, and then
# ensures the host-specific private remote path and canonical Desktop Commander stay healthy.

$ErrorActionPreference = "Continue"
$Repo = "C:\site-shopvivaliz"
$LogDir = Join-Path $Repo "logs"
$LogFile = Join-Path $LogDir ("local-sync-{0}.log" -f (Get-Date -Format "yyyy-MM-dd"))
$HostName = [string]$env:COMPUTERNAME
$HostKey = $HostName.ToUpperInvariant()
$Bootstrap = $null
$DesktopCommanderSupervisor = $null
$HostLabel = $HostName

switch ($HostKey) {
    'LAPTOP-NIG4IFUU' {
        $Bootstrap = Join-Path $Repo "scripts\fredwin-remote-bootstrap.ps1"
        $DesktopCommanderSupervisor = Join-Path $Repo "scripts\fredwin-desktop-commander-supervisor.ps1"
        $HostLabel = 'Fred-Win'
    }
    'DESKTOP-KOCEPSV' {
        $Bootstrap = Join-Path $Repo "scripts\desktopkocepsv-remote-bootstrap.ps1"
        $DesktopCommanderSupervisor = Join-Path $Repo "scripts\desktopkocepsv-desktop-commander-supervisor.ps1"
        $HostLabel = 'DESKTOP-KOCEPSV'
    }
}

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
    Log "Auto-sync start host=$HostLabel"

    $CurrentBranch = & git rev-parse --abbrev-ref HEAD 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Failed to get current branch name" }
    $CurrentBranch = $CurrentBranch.ToString().Trim()
    Log "Current branch is: $CurrentBranch"

    & git fetch origin 2>&1 | ForEach-Object { Log "git fetch: $_" }
    if ($LASTEXITCODE -ne 0) { throw "git fetch failed exit=$LASTEXITCODE" }

    $Upstream = & git rev-parse --abbrev-ref --symbolic-full-name "@{u}" 2>$null
    if ($LASTEXITCODE -eq 0 -and $Upstream) {
        $Upstream = $Upstream.ToString().Trim()
        Log "Syncing current branch $CurrentBranch with upstream $Upstream"
        & git merge --ff-only $Upstream 2>&1 | ForEach-Object { Log "git merge: $_" }
        if ($LASTEXITCODE -ne 0) {
            Log "WARNING fast-forward failed for current branch $CurrentBranch; local changes preserved"
        }
    } else {
        Log "No tracking branch configured for $CurrentBranch"
    }

    if ($CurrentBranch -ne "main") {
        Log "Updating local main branch via fast-forward fetch"
        & git fetch origin main:main 2>&1 | ForEach-Object { Log "git fetch main:main: $_" }
        if ($LASTEXITCODE -ne 0) {
            Log "WARNING fast-forward update of main failed (possibly non-fast-forward)"
        }
    }

    if ($HostKey -eq 'LAPTOP-NIG4IFUU' -and (Test-Path $SmtpGuard)) {
        Log "Running Graph-only SMTP guard"
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $SmtpGuard 2>&1 |
            ForEach-Object { Log "smtp-guard: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING SMTP guard exit=$LASTEXITCODE" }
    }

    if ($DesktopCommanderSupervisor -and (Test-Path $DesktopCommanderSupervisor)) {
        Log "Ensuring canonical $HostLabel Desktop Commander remains healthy"
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $DesktopCommanderSupervisor -Mode Ensure 2>&1 |
            ForEach-Object { Log "desktop-commander: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING Desktop Commander supervisor exit=$LASTEXITCODE" }
    }
    elseif ($DesktopCommanderSupervisor) { Log "Desktop Commander supervisor not present yet for $HostLabel" }
    else { Log "No Desktop Commander host profile for $HostName; skipping host-specific recovery" }

    if ($Bootstrap -and (Test-Path $Bootstrap)) {
        Log "Repairing $HostLabel private relay task"
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $Bootstrap -Mode InstallTask 2>&1 |
            ForEach-Object { Log "bootstrap: $_" }
        if ($LASTEXITCODE -ne 0) { Log "WARNING bootstrap exit=$LASTEXITCODE" }
    }
    elseif ($Bootstrap) { Log "Bootstrap not present yet for $HostLabel" }

    Log "Auto-sync complete host=$HostLabel"
}
catch {
    Log "ERROR $($_.Exception.Message)"
    exit 1
}
