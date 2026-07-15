# Schedule Auto-Sync Task (Windows Task Scheduler)
# Run as Administrator
# Every 2 minutes

param(
    [string]$ProjectPath = "C:\Users\user\site-shopvivaliz",
    [string]$TaskName = "ShopVivaliz-AutoSync"
)

function Ensure-Admin {
    $currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Error "This script must be run as Administrator"
        exit 1
    }
}

function Create-AutoSyncTask {
    param(
        [string]$Path,
        [string]$Name
    )

    Write-Host "Creating scheduled task: $Name"
    Write-Host "Project path: $Path"

    # Check if task exists
    $existingTask = Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
    if ($existingTask) {
        Write-Host "Task already exists. Removing..."
        Unregister-ScheduledTask -TaskName $Name -Confirm:$false
    }

    # Create task action
    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument @"
        -NoProfile -WindowStyle Hidden -Command `
        `"Set-Location '$Path'; if (Get-Command node -ErrorAction SilentlyContinue) { node scripts/tri-environment-sync.js } else { python scripts/autonomous-sync.py }`"
"@

    # Create trigger (every 2 minutes)
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 2) -RepetitionDuration (New-TimeSpan -Days 999)

    # Create task settings
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -RunWithoutNetwork:$false -MultipleInstances IgnoreNew

    # Register task
    try {
        Register-ScheduledTask -TaskName $Name `
            -Action $action `
            -Trigger $trigger `
            -Settings $settings `
            -Description "ShopVivaliz Autonomous Sync - Synchronizes PC, cloud and Oracle" `
            -RunLevel Highest `
            -Force

        Write-Host "✓ Task created successfully"
        Write-Host "  Task: $Name"
        Write-Host "  Interval: 2 minutes"
        Write-Host "  Status: Enabled"

        return $true
    } catch {
        Write-Error "Failed to create task: $_"
        return $false
    }
}

function Enable-Task {
    param([string]$Name)
    try {
        Enable-ScheduledTask -TaskName $Name
        Write-Host "✓ Task enabled: $Name"
        return $true
    } catch {
        Write-Error "Failed to enable task: $_"
        return $false
    }
}

# Main execution
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "ShopVivaliz - Auto-Sync Task Scheduler Setup" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

Ensure-Admin

if (-not (Test-Path $ProjectPath)) {
    Write-Error "Project path not found: $ProjectPath"
    exit 1
}

# Create task
if (Create-AutoSyncTask -Path $ProjectPath -Name $TaskName) {
    Enable-Task -Name $TaskName

    Write-Host ""
    Write-Host "Setup Complete!" -ForegroundColor Green
    Write-Host ""
    Write-Host "The auto-sync task will start in the next 2 minutes and run every 2 minutes thereafter."
    Write-Host ""
    Write-Host "View logs:"
    Write-Host "  Get-ScheduledTaskInfo -TaskName '$TaskName'"
    Write-Host "  Get-EventLog -LogName System | ? { `$_.Source -eq 'TaskScheduler' }"
} else {
    Write-Host "Failed to create task" -ForegroundColor Red
    exit 1
}
