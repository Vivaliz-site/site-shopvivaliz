#requires -Version 7.0
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$payload = [ordered]@{
    status = 'blocked'
    success = $false
    queue_modified = $false
    reason = 'Legacy PowerShell orchestrator retired; it ignored executor exit codes and could reuse stale evidence.'
    replacement = 'api/agent/real-work-orchestrator.php via Autonomous Real Work Watchdog or shopvivaliz-agent.service'
}

$payload | ConvertTo-Json -Depth 4 | Write-Error
exit 2
