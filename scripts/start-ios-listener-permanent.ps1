#!/usr/bin/env powershell
<#
.SYNOPSIS
  Inicia iOS Command Listener e deixa rodando permanentemente

.DESCRIPTION
  Este script:
  1. Inicia o listener Node.js
  2. Monitora se está rodando
  3. Reinicia automaticamente se cair
  4. Logs tudo em arquivo

.USAGE
  .\scripts\start-ios-listener-permanent.ps1

#>

$ErrorActionPreference = "Continue"

$repoPath = "c:\site-shopvivaliz"
$scriptPath = "$repoPath\scripts\ios-command-listener.js"
$logFile = "$repoPath\logs\listener-startup.log"
$pidFile = "$repoPath\.ios-listener.pid"

# Criar pasta logs se não existir
if (-not (Test-Path "$repoPath\logs")) {
    New-Item -ItemType Directory -Path "$repoPath\logs" -Force | Out-Null
}

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$timestamp] $Message"
    Write-Host $line
    Add-Content -Path $logFile -Value $line
}

function Start-ListenerProcess {
    Write-Log "🚀 Iniciando iOS Command Listener..."

    $process = Start-Process powershell.exe `
        -ArgumentList "-NoProfile -Command `"cd '$repoPath'; node '$scriptPath'`"" `
        -WindowStyle Normal `
        -PassThru `
        -ErrorAction SilentlyContinue

    if ($process) {
        Write-Log "✅ Listener iniciado (PID: $($process.Id))"
        $process.Id | Out-File -FilePath $pidFile -Force
        return $process.Id
    } else {
        Write-Log "❌ Falha ao iniciar listener"
        return $null
    }
}

function Check-ListenerRunning {
    if (-not (Test-Path $pidFile)) {
        return $false
    }

    $pid = Get-Content -Path $pidFile -ErrorAction SilentlyContinue
    if ([string]::IsNullOrWhiteSpace($pid)) {
        return $false
    }

    try {
        $process = Get-Process -Id $pid -ErrorAction SilentlyContinue
        return $null -ne $process
    } catch {
        return $false
    }
}

Write-Log ""
Write-Log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
Write-Log "📱 iOS Command Listener - Modo Permanente"
Write-Log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
Write-Log ""

# Verificar Node.js
Write-Log "🔍 Verificando Node.js..."
$nodeVersion = node --version 2>$null
if ($nodeVersion) {
    Write-Log "✅ Node.js $nodeVersion encontrado"
} else {
    Write-Log "❌ Node.js NÃO encontrado!"
    Write-Log "Instale em: https://nodejs.org/"
    exit 1
}

Write-Log ""

# Inicial attempt
if (-not (Check-ListenerRunning)) {
    Start-ListenerProcess
} else {
    Write-Log "✅ Listener já está rodando"
}

Write-Log ""
Write-Log "📊 Monitorando... (Ctrl+C para parar)"
Write-Log ""

$checkInterval = 30  # Check a cada 30 segundos
$failCount = 0
$maxFails = 3

# Loop de monitoramento
while ($true) {
    Start-Sleep -Seconds $checkInterval

    if (Check-ListenerRunning) {
        Write-Log "✅ Listener ativo (monitorando...)"
        $failCount = 0
    } else {
        $failCount++
        Write-Log "⚠️  Listener inativo (tentativa $failCount)"

        if ($failCount -ge $maxFails) {
            Write-Log "❌ Listener parou. Reiniciando..."
            Remove-Item -Path $pidFile -Force -ErrorAction SilentlyContinue
            Start-ListenerProcess
            $failCount = 0
        }
    }
}

Write-Log ""
Write-Log "🛑 Listener parado"
