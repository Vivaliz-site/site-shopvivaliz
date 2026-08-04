#requires -Version 7.0
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$payload = [ordered]@{
    status = 'blocked'
    success = $false
    external_operation_performed = $false
    reason = 'Legacy hybrid-AI setup retired because it declared runtime, database and environment success without verifiable evidence.'
    replacement = 'Use the reviewed repository workflows and api/agent/real-work-orchestrator.php.'
}

$payload | ConvertTo-Json -Depth 4 | Write-Error
exit 2
