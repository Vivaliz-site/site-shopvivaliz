# Local Auto-Sync Script
# Monitors commits and auto-pushes to GitHub
# Triggered by Windows Task Scheduler

$RepoPath = "c:\site-shopvivaliz"
$LogFile = Join-Path $RepoPath ".git\local-auto-sync.log"

function Log-Message {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry = "[$timestamp] $Message"
    Write-Host $logEntry
    Add-Content -Path $LogFile -Value $logEntry -Encoding UTF8 -ErrorAction SilentlyContinue
}

function Check-And-Push {
    try {
        Set-Location $RepoPath

        # Get current branch
        $branch = & git rev-parse --abbrev-ref HEAD 2>&1
        if ($LASTEXITCODE -ne 0) {
            Log-Message "ERROR: Could not get branch"
            return
        }

        # Check status
        $status = & git status --porcelain 2>&1
        if ($status) {
            Log-Message "Uncommitted changes detected"
            return
        }

        # Get commit hashes
        $localCommit = & git rev-parse HEAD 2>&1
        $remoteCommit = & git rev-parse origin/$branch 2>&1

        if ($LASTEXITCODE -ne 0) {
            Log-Message "WARNING: Could not get remote commit"
            return
        }

        # Check if local is ahead
        if ($localCommit -ne $remoteCommit) {
            Log-Message "Local ahead of remote ($($localCommit.Substring(0,7)))"
            Log-Message "Pushing to origin/$branch..."

            $pushResult = & git push origin $branch 2>&1
            if ($LASTEXITCODE -eq 0) {
                Log-Message "SUCCESS: Pushed to GitHub"
            }
            else {
                Log-Message "ERROR: Push failed - $pushResult"
            }
        }
        else {
            Log-Message "Local is in sync with remote"
        }
    }
    catch {
        Log-Message "ERROR: $_"
    }
}

try {
    Log-Message "=== Local Auto-Sync Check ==="
    Check-And-Push
    Log-Message "=== Check Complete ==="
}
catch {
    Log-Message "FATAL: $_"
}
