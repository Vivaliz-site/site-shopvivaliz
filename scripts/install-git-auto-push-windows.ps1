# Install Git Auto-Push Daemon as Windows Background Task
# Run as Administrator

param(
    [string]$RepoPath = "c:\site-shopvivaliz",
    [string]$PythonPath = "python3",
    [string]$TaskName = "GitAutoPushDaemon"
)

Write-Host "Git Auto-Push Daemon Installation for Windows" -ForegroundColor Cyan
Write-Host "=" * 60

# Check if running as admin
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")

if (-not $isAdmin) {
    Write-Host "ERROR: This script must run as Administrator" -ForegroundColor Red
    Write-Host "Please right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    exit 1
}

Write-Host "Status: Running as Administrator ✓" -ForegroundColor Green

# Check Python
Write-Host "`nChecking Python..."
try {
    $pythonVersion = & $PythonPath --version 2>&1
    Write-Host "Found: $pythonVersion ✓" -ForegroundColor Green
}
catch {
    Write-Host "ERROR: Python not found. Install Python and add to PATH." -ForegroundColor Red
    exit 1
}

# Check repo exists
Write-Host "`nChecking repository..."
if (-not (Test-Path $RepoPath)) {
    Write-Host "ERROR: Repository not found at $RepoPath" -ForegroundColor Red
    exit 1
}
Write-Host "Found: $RepoPath ✓" -ForegroundColor Green

# Check daemon script
$daemonScript = Join-Path $RepoPath "scripts\git-auto-push-daemon.py"
if (-not (Test-Path $daemonScript)) {
    Write-Host "ERROR: Daemon script not found at $daemonScript" -ForegroundColor Red
    exit 1
}
Write-Host "Found: $daemonScript ✓" -ForegroundColor Green

# Remove existing task if present
Write-Host "`nRemoving existing task (if any)..."
try {
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 1
    Write-Host "Removed existing task ✓" -ForegroundColor Green
}
catch {
    Write-Host "No existing task found (OK)" -ForegroundColor Gray
}

# Create task action
Write-Host "`nCreating scheduled task..."
$action = New-ScheduledTaskAction `
    -Execute $PythonPath `
    -Argument "`"$daemonScript`" `"$RepoPath`" 2"

# Create task trigger (run at startup and every 5 minutes)
$trigger = @(
    (New-ScheduledTaskTrigger -AtStartup),
    (New-ScheduledTaskTrigger -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 365))
)

# Create task settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable `
    -Compatibility Win8

# Create task
try {
    $task = Register-ScheduledTask `
        -TaskName $TaskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Description "Automatically push git commits to GitHub" `
        -RunLevel Highest

    Write-Host "Task created: $TaskName ✓" -ForegroundColor Green
}
catch {
    Write-Host "ERROR: Failed to create task: $_" -ForegroundColor Red
    exit 1
}

# Test daemon
Write-Host "`nTesting daemon..."
$testOutput = & $PythonPath $daemonScript $RepoPath 2>&1 | Select-Object -First 3
if ($testOutput) {
    Write-Host "Daemon test output:" -ForegroundColor Gray
    $testOutput | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
}

# Start task
Write-Host "`nStarting task..."
try {
    Start-ScheduledTask -TaskName $TaskName
    Start-Sleep -Seconds 2
    $taskState = (Get-ScheduledTask -TaskName $TaskName).State
    Write-Host "Task state: $taskState ✓" -ForegroundColor Green
}
catch {
    Write-Host "Warning: Could not start task manually: $_" -ForegroundColor Yellow
    Write-Host "It will start automatically at next boot or in 5 minutes" -ForegroundColor Yellow
}

Write-Host "`n" + ("=" * 60)
Write-Host "Installation Complete!" -ForegroundColor Green
Write-Host "=" * 60

Write-Host "`nNext steps:"
Write-Host "1. Task name: $TaskName"
Write-Host "2. Logs: $RepoPath\.git\auto-push-daemon.log"
Write-Host "3. View task: Task Scheduler > Task Scheduler Library > $TaskName"
Write-Host "4. Stop task: Stop-ScheduledTask -TaskName '$TaskName'"
Write-Host "5. Remove task: Unregister-ScheduledTask -TaskName '$TaskName' -Confirm:`$false"

Write-Host "`nNow git commits will auto-push within 2 seconds! ✓" -ForegroundColor Green
