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

$repo = "c:\site-shopvivaliz"
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

    Log "════════════════════════════════════════════════════════════════"
    Log "▶ SYNC INICIADO"

    try {
        # Pull com rebase e autostash
        Log "[1] Executando git fetch..."
        & git fetch --prune origin

        $behind = & git rev-list --count "HEAD..origin/main"
        $ahead = & git rev-list --count "origin/main..HEAD"

        Log "    Status: ahead=$ahead, behind=$behind"

        if ($behind -gt 0) {
            Log "[2] Puxando atualizacoes (git pull --rebase --autostash)..."
            & git pull --rebase --autostash 2>&1 | ForEach-Object { Log "    $_" }

            if ($LASTEXITCODE -eq 0) {
                Log "    ✅ Pull bem-sucedido"
            } else {
                Log "    ❌ Pull falhou (codigo: $LASTEXITCODE)"
                return $false
            }
        } else {
            Log "    ✓ Ja atualizado"
        }

        # Push se houver commits locais
        if ($ahead -gt 0) {
            Log "[3] Push de $ahead commit(s) local(is)..."
            & git push origin main 2>&1 | ForEach-Object { Log "    $_" }

            if ($LASTEXITCODE -eq 0) {
                Log "    ✅ Push bem-sucedido"
            } else {
                Log "    ⚠️ Push falhou (pode estar bloqueado por pre-push hook - ignorando)"
            }
        } else {
            Log "    ✓ Nada para fazer push"
        }

        Log "✅ SYNC CONCLUIDO"
        return $true

    } catch {
        Log "❌ ERRO: $_"
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
    exit ($result ? 0 : 1)
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
