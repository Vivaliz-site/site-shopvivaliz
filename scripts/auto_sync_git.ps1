#!/usr/bin/env pwsh
# Wrapper de compatibilidade. A implementacao canonica nao cria commits e nao ignora hooks.
param([int]$Interval = 30, [switch]$RunOnce)
$script = Join-Path $PSScriptRoot "local-auto-sync.ps1"
if (-not (Test-Path -LiteralPath $script)) { Write-Error "Script canonico nao encontrado: $script"; exit 1 }
if ($RunOnce) { & $script -IntervalMinutes $Interval -OneTime } else { & $script -IntervalMinutes $Interval }
exit $LASTEXITCODE
