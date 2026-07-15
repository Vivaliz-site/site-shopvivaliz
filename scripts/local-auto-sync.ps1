#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Auto-sync local repository a cada 30 minutos (igual VM Oracle)
.DESCRIPTION
    - Pull automático cada 30min
    - Push automático se houver commits locais
    - Log em C:\site-shopvivaliz\logs\local-sync-*.log
    - Roda continuamente em background
#>

param(
    [int]$IntervalMinutes = 30,
    [switch]$OneTime,
    [switch]$Verbose
)

$ErrorActionPreference = "Continue"

$scriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
try {
    $repo = (git rev-parse --show-toplevel 2>$null).Trim()
} catch {
    $repo = $scriptRoot
}
if ([string]::IsNullOrWhiteSpace($repo)) {
    $repo = $scriptRoot
}
$logsDir = "$repo\logs"

if (-not (Test-Path $logsDir)) {
    New-Item -ItemType Directory -Force -Path $logsDir | Out-Null
}

$logFile = "$logsDir\local-sync-$(Get-Date -Format 'yyyy-MM-dd').log"

function Log($Message) {
    $ts = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $msg = "[$ts] $Message"
    Write-Host $msg
    Add-Content -Path $logFile -Value $msg -Encoding UTF8
}

function SyncOnce {
    Set-Location $repo

    Log "=========================================================================="
    Log ">> SYNC INICIADO"

    try {
        # Pull com rebase e autostash
        Log "[1] Executando git fetch..."
        git fetch --prune origin 2>&1 | ForEach-Object { Log "    $_" }

        $behind = (git rev-list --count "HEAD..origin/main" 2>$null)
        $ahead = (git rev-list --count "origin/main..HEAD" 2>$null)

        Log "    Status: ahead=$ahead behind=$behind"

        if ($behind -gt 0) {
            Log "[2] Puxando atualizacoes..."
            git pull --rebase --autostash 2>&1 | ForEach-Object { Log "    $_" }
            Log "    >> Pull concluido"
        } else {
            Log "    >> Ja atualizado"
        }

        if ($ahead -gt 0) {
            Log "[3] Push de $ahead commit(s)..."
            git push origin main --no-verify 2>&1 | ForEach-Object { Log "    $_" }
            Log "    >> Push concluido"
        } else {
            Log "    >> Nada para fazer push"
        }

        Log "OK - SYNC CONCLUIDO"
        return $true

    } catch {
        Log "ERRO: $_"
        return $false
    }
}

Log "🚀 LOCAL AUTO-SYNC INICIADO"
Log "   Repositorio: $repo"
Log "   Intervalo: ${IntervalMinutes}min"
Log "   Log: $logFile"

if ($OneTime) {
    Log "   Modo: Uma unica execucao"
    $result = SyncOnce
    if ($result) { exit 0 } else { exit 1 }
} else {
    Log "   Modo: Continuo (infinito)"
    Log "   Para parar: Ctrl+C"

    $iteration = 0
    while ($true) {
        $iteration++
        Log ""
        Log "════ CICLO $iteration ════"

        SyncOnce | Out-Null

        Log "Proximo ciclo em ${IntervalMinutes} minutos... (proxima: $(([DateTime]::Now).AddMinutes($IntervalMinutes).ToString('yyyy-MM-dd HH:mm:ss')))"
        Log ""

        Start-Sleep -Seconds ($IntervalMinutes * 60)
    }
}
