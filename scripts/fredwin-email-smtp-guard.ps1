# Fred-Win email safety guard.
# Production MEI mail is Microsoft Graph only. This script removes/blocks legacy
# SMTP paths so ad-hoc Gmail/SMTP tests cannot silently send from Fred-Win.

$ErrorActionPreference = 'Continue'
$EmailRepo = 'C:\mei-mg-email'
$Patterns = '(?i)(smtp\.gmail\.com|shopvivaliz@gmail\.com|Teste Gmail SMTP|Gmail SMTP|smtplib|enviar_teste_gmail|gmail.*smtp)'

function Write-Guard([string]$Message) {
    Write-Output ("[{0}] SMTP-GUARD {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}

# 1) Kill any live process whose command line is clearly using the forbidden SMTP path.
Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | ForEach-Object {
    $cmd = [string]$_.CommandLine
    if ([string]::IsNullOrWhiteSpace($cmd)) { return }
    if ($cmd -match $Patterns) {
        if ($_.ProcessId -ne $PID) {
            Write-Guard ("stopping pid={0} name={1}" -f $_.ProcessId,$_.Name)
            Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
        }
    }
}

# 2) Remove scheduled tasks that can relaunch Gmail/SMTP test senders.
Get-ScheduledTask -ErrorAction SilentlyContinue | ForEach-Object {
    $joined = (($_.Actions | ForEach-Object { "$($_.Execute) $($_.Arguments)" }) -join ' ')
    if ($_.TaskName -match '(?i)(gmail|smtp)' -or $joined -match $Patterns) {
        Write-Guard ("removing scheduled task={0}" -f $_.TaskName)
        Stop-ScheduledTask -TaskName $_.TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $_.TaskName -Confirm:$false -ErrorAction SilentlyContinue
    }
}

# 3) Remove Gmail/SMTP keys from the MEI project .env. Never print secret values.
$envPath = Join-Path $EmailRepo '.env'
if (Test-Path $envPath) {
    $lines = Get-Content $envPath
    $clean = $lines | Where-Object {
        $_ -notmatch '^\s*(GMAIL_|SMTP_|MICROSOFT_SMTP_)' -and
        $_ -notmatch '^\s*EMAIL_PROVIDER\s*='
    }
    $clean += 'EMAIL_PROVIDER=microsoft_graph'
    Set-Content -Path $envPath -Value $clean -Encoding UTF8
    Write-Guard 'sanitized C:\mei-mg-email\.env; provider forced to microsoft_graph'
}

# 4) Remove inherited user/machine environment variables that could re-enable SMTP.
foreach ($scope in @('User','Machine')) {
    try {
        $vars = [Environment]::GetEnvironmentVariables($scope)
        foreach ($key in @($vars.Keys)) {
            if ([string]$key -match '^(?i)(GMAIL_|SMTP_|MICROSOFT_SMTP_)') {
                [Environment]::SetEnvironmentVariable([string]$key, $null, $scope)
                Write-Guard ("removed {0} environment variable {1}" -f $scope,$key)
            }
        }
    }
    catch {
        Write-Guard ("warning: could not sanitize {0} environment scope: {1}" -f $scope,$_.Exception.Message)
    }
}

# 5) Network-level safety net. Graph uses HTTPS/443, so outbound SMTP submission is
# unnecessary for this Fred-Win campaign machine. If the scheduled task lacks admin
# rights this step logs a warning and the process/task/env guards still apply.
$ruleName = 'ShopVivaliz - Block outbound SMTP (Graph only)'
try {
    $existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    if ($existing) {
        Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
    }
    New-NetFirewallRule -DisplayName $ruleName -Direction Outbound -Action Block -Protocol TCP -RemotePort 25,465,587 -Profile Any -Description 'MEI campaign must use Microsoft Graph HTTPS only; blocks legacy SMTP/Gmail SMTP.' | Out-Null
    Write-Guard 'outbound TCP 25/465/587 blocked by Windows Firewall'
}
catch {
    Write-Guard ("warning: firewall rule could not be applied: {0}" -f $_.Exception.Message)
}

# 6) Evidence only: do not expose secrets.
$remaining = @(
    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object {
        ([string]$_.CommandLine) -match $Patterns
    }
)
Write-Guard ("remaining forbidden SMTP processes={0}" -f $remaining.Count)
if ($remaining.Count -gt 0) {
    exit 5
}
Write-Output 'SMTP_GUARD_READY'