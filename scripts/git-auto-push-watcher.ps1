# Git Auto-Push Watcher for Windows
# Monitors .git/HEAD and auto-pushes on commit
# Run: powershell -NoProfile -ExecutionPolicy Bypass -File git-auto-push-watcher.ps1

param(
    [string]$RepoPath = "c:\site-shopvivaliz",
    [int]$CheckIntervalSeconds = 5
)

$ErrorActionPreference = "SilentlyContinue"
Set-Location $RepoPath

Write-Host "Git Auto-Push Watcher Started"
Write-Host "Repo: $RepoPath"
Write-Host "Check interval: $CheckIntervalSeconds seconds"
Write-Host "Press Ctrl+C to stop`n"

$lastHeadRef = $null
$logFile = ".\.git\auto-push-watcher.log"

function Log-Message {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logEntry = "[$timestamp] $Message"
    Write-Host $logEntry
    Add-Content -Path $logFile -Value $logEntry -ErrorAction SilentlyContinue
}

function Get-CurrentCommit {
    try {
        $output = git rev-parse HEAD 2>$null
        return $output
    }
    catch {
        return $null
    }
}

function Push-IfNeeded {
    param([string]$CurrentCommit)

    try {
        $localStatus = git rev-parse HEAD 2>$null
        $remoteStatus = git rev-parse origin/main 2>$null

        if ($null -eq $remoteStatus) {
            Log-Message "Remote not available yet"
            return $false
        }

        if ($localStatus -ne $remoteStatus) {
            Log-Message "Changes detected: Local ahead of origin"
            Log-Message "Attempting push..."

            $pushResult = git push origin main 2>&1

            if ($LASTEXITCODE -eq 0) {
                Log-Message "SUCCESS: Pushed to GitHub"
                return $true
            }
            else {
                Log-Message "WARNING: Push failed - $pushResult"
                return $false
            }
        }
    }
    catch {
        Log-Message "ERROR: $_"
    }

    return $false
}

# Main loop
Log-Message "Watcher initialized"

while ($true) {
    try {
        $currentCommit = Get-CurrentCommit

        if ($null -ne $currentCommit -and $currentCommit -ne $lastHeadRef) {
            Log-Message "Commit detected: $($currentCommit.Substring(0, 7))"
            Push-IfNeeded $currentCommit
            $lastHeadRef = $currentCommit
        }

        Start-Sleep -Seconds $CheckIntervalSeconds
    }
    catch {
        Log-Message "ERROR in loop: $_"
        Start-Sleep -Seconds 10
    }
}
