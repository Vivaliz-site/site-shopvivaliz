#requires -Version 5.1
<#
.SYNOPSIS
  Cost-free checkpoint guard for an external-wait continuation job.
.DESCRIPTION
  Examine local checkpoint state; no model, network, or child process.
  Actionable states require a separate explicitly approved free executor.
#>
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$JobRoot)

$ErrorActionPreference = 'Stop'
try {
    $folder = (Resolve-Path -LiteralPath $JobRoot -ErrorAction Stop).ProviderPath
    $state = Join-Path -Path $folder -ChildPath 'state.json'
    $file = Get-Item -LiteralPath $state -ErrorAction Stop
    if ($file.Length -gt 4194304 -or $file.Length -eq 0) {
        Write-Output 'FREE_CHECKPOINT_WATCH reason=invalid_state_size inference=false'
        exit 78
    }
    $snapshot = Get-Content -LiteralPath $state -Raw -ErrorAction Stop | ConvertFrom-Json -ErrorAction Stop
    $status = [string]$snapshot.status
    if ([string]::IsNullOrWhiteSpace($status)) {
        Write-Output 'FREE_CHECKPOINT_WATCH reason=missing_status inference=false'
        exit 78
    }
    switch ($status) {
        'completed' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=completed action=none inference=false'
            exit 0
        }
        'waiting_external' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=waiting_external action=await_evidence inference=false'
            exit 0
        }
        'human_action_required' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=human_action_required action=await_evidence inference=false'
            exit 0
        }
        'blocked_external' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=blocked_external action=await_evidence inference=false'
            exit 0
        }
        'cancelled' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=cancelled action=none inference=false'
            exit 0
        }
        'ready' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=ready action=free_executor_required inference=false'
            exit 75
        }
        'running' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=running action=free_executor_required inference=false'
            exit 75
        }
        'pending' {
            Write-Output 'FREE_CHECKPOINT_WATCH status=pending action=free_executor_required inference=false'
            exit 75
        }
        default {
            Write-Output 'FREE_CHECKPOINT_WATCH reason=unrecognized_status inference=false'
            exit 78
        }
    }
} catch {
    Write-Output 'FREE_CHECKPOINT_WATCH reason=state_unavailable inference=false'
    exit 78
}
