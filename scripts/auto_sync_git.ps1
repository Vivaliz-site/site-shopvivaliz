# Auto-sync Git repository
# Triggered by Windows Task Scheduler every 5 minutes
# Auto-pushes local commits to GitHub

param(
    [int]$Interval = 5
)

$RepoPath = "c:\site-shopvivaliz"
$LogFile = Join-Path $RepoPath ".git\auto_sync_git.log"

function Log-Message {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry = "[$timestamp] $Message"
    Write-Host $logEntry
    Add-Content -Path $LogFile -Value $logEntry -Encoding UTF8 -ErrorAction SilentlyContinue
}

function Get-CurrentCommit {
    try {
        $output = & git -C $RepoPath rev-parse HEAD 2>$null
        return $output
    }
    catch {
        return $null
    }
}

try {
    Log-Message "=== Git Auto-Sync Started (interval: ${Interval}min) ==="

    Set-Location $RepoPath

    # Fetch from remote
    Log-Message "Fetching from origin..."
    $fetchResult = & git fetch origin 2>&1
    if ($LASTEXITCODE -eq 0) {
        Log-Message "Fetch successful"
    }
    else {
        Log-Message "Fetch failed: $fetchResult"
    }

    # Check status
    $status = & git status --porcelain 2>&1
    if ($status) {
        Log-Message "Local changes detected, will stash and sync"
        & git stash 2>&1 | Out-Null
    }

    # Get current branch
    $branch = & git rev-parse --abbrev-ref HEAD 2>&1

    # Check if ahead of remote
    $localCommit = & git rev-parse HEAD 2>&1
    $remoteCommit = & git rev-parse origin/$branch 2>&1

    if ($localCommit -ne $remoteCommit) {
        Log-Message "Local is ahead of remote, pushing..."
        $pushResult = & git push origin $branch 2>&1
        if ($LASTEXITCODE -eq 0) {
            Log-Message "Push successful"
        }
        else {
            Log-Message "Push failed: $pushResult"
        }
    }
    else {
        Log-Message "Local is in sync with remote"
    }

    # Pull latest
    Log-Message "Pulling latest from origin/$branch..."
    $pullResult = & git pull --ff-only origin $branch 2>&1
    if ($LASTEXITCODE -eq 0) {
        Log-Message "Pull successful - repository synchronized"
    }
    else {
        Log-Message "Pull result: $pullResult"
    }

    Log-Message "=== Sync Complete ==="
}
catch {
    Log-Message "ERROR: $_"
}
