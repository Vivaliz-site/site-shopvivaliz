# Schedule Git Operations Tasks (Windows Task Scheduler)
# Run as Administrator
# Auto-Pull: every 5 minutes
# Auto-Push: every 10 minutes

param(
    [string]$ProjectPath = "C:\Users\user\site-shopvivaliz"
)

function Ensure-Admin {
    $currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Error "This script must be run as Administrator"
        exit 1
    }
}

function Create-GitPullTask {
    param([string]$Path)

    $taskName = "ShopVivaliz-AutoGitPull"
    Write-Host "Creating task: $taskName"

    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }

    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument @"
        -NoProfile -WindowStyle Hidden -Command `
        `"Set-Location '$Path'; git fetch origin main; git merge -X ours origin/main; echo 'Auto-pull completed' >> logs/git-auto-pull.log`"
"@

    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 999)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew

    try {
        Register-ScheduledTask -TaskName $taskName `
            -Action $action `
            -Trigger $trigger `
            -Settings $settings `
            -Description "ShopVivaliz Auto-Git-Pull (every 5 minutes)" `
            -RunLevel Highest `
            -Force

        Enable-ScheduledTask -TaskName $taskName
        Write-Host "✓ Created: $taskName (every 5 minutes)"
    } catch {
        Write-Error "Failed to create $taskName : $_"
    }
}

function Create-GitPushTask {
    param([string]$Path)

    $taskName = "ShopVivaliz-AutoGitPush"
    Write-Host "Creating task: $taskName"

    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }

    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument @"
        -NoProfile -WindowStyle Hidden -Command `
        `"Set-Location '$Path'; python scripts/autonomous-git-push.py`"
"@

    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 10) -RepetitionDuration (New-TimeSpan -Days 999)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew

    try {
        Register-ScheduledTask -TaskName $taskName `
            -Action $action `
            -Trigger $trigger `
            -Settings $settings `
            -Description "ShopVivaliz Auto-Git-Push (every 10 minutes)" `
            -RunLevel Highest `
            -Force

        Enable-ScheduledTask -TaskName $taskName
        Write-Host "✓ Created: $taskName (every 10 minutes)"
    } catch {
        Write-Error "Failed to create $taskName : $_"
    }
}

function Create-FtpDeployTask {
    param([string]$Path)

    $taskName = "ShopVivaliz-AutoFtpDeploy"
    Write-Host "Creating task: $taskName"

    $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($existingTask) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }

    $action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument @"
        -NoProfile -WindowStyle Hidden -Command `
        `"Set-Location '$Path'; python scripts/autonomous-ftp-deploy.py`"
"@

    # Trigger after git push (every 15 minutes)
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 999)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew

    try {
        Register-ScheduledTask -TaskName $taskName `
            -Action $action `
            -Trigger $trigger `
            -Settings $settings `
            -Description "ShopVivaliz Auto-FTP-Deploy (every 15 minutes)" `
            -RunLevel Highest `
            -Force

        Enable-ScheduledTask -TaskName $taskName
        Write-Host "✓ Created: $taskName (every 15 minutes)"
    } catch {
        Write-Error "Failed to create $taskName : $_"
    }
}

# Main execution
Write-Host "======================================================================" -ForegroundColor Cyan
Write-Host "ShopVivaliz - Git Operations Task Scheduler Setup" -ForegroundColor Cyan
Write-Host "======================================================================" -ForegroundColor Cyan

Ensure-Admin

if (-not (Test-Path $ProjectPath)) {
    Write-Error "Project path not found: $ProjectPath"
    exit 1
}

# Create tasks
Create-GitPullTask -Path $ProjectPath
Create-GitPushTask -Path $ProjectPath
Create-FtpDeployTask -Path $ProjectPath

Write-Host ""
Write-Host "Setup Complete!" -ForegroundColor Green
Write-Host ""
Write-Host "Schedule Overview:"
Write-Host "  • Auto-Git-Pull:  every 5 minutes"
Write-Host "  • Auto-Git-Push:  every 10 minutes (detects changes)"
Write-Host "  • Auto-FTP-Deploy: every 15 minutes (on-change)"
Write-Host ""
Write-Host "View scheduled tasks:"
Write-Host "  Get-ScheduledTask | ? { `$_.TaskName -like '*ShopVivaliz*' }"
