#!/usr/bin/env pwsh
<#
.SYNOPSIS
Auto-sincronizar repositório Git a cada N minutos

.DESCRIPTION
Script para rodar em Windows Task Scheduler que:
- Faz git pull periodicamente
- Pusha mudanças locais
- Executa validações
- Logging completo

.PARAMETER Interval
Intervalo em minutos entre sincronizações (padrão: 5)

.PARAMETER RunOnce
Executar uma vez e sair (padrão: $false)

.EXAMPLE
# Sincronizar a cada 5 minutos (indefinido)
.\auto_sync_git.ps1

# Sincronizar a cada 15 minutos
.\auto_sync_git.ps1 -Interval 15

# Executar uma vez
.\auto_sync_git.ps1 -RunOnce

#>

param(
    [int]$Interval = 5,
    [switch]$RunOnce = $false
)

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

$ErrorActionPreference = "Continue"
$RepositoryPath = (Get-Item -Path $PSScriptRoot).Parent.FullName
$LogDir = Join-Path $RepositoryPath "logs"
$LogFile = Join-Path $LogDir "auto-sync-$(Get-Date -Format 'yyyy-MM-dd').log"

# Criar diretório de logs
if (-not (Test-Path $LogDir)) {
    New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
}

# ============================================================================
# FUNÇÕES
# ============================================================================

function Write-Log {
    param(
        [string]$Message,
        [string]$Level = "INFO"
    )

    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $LogMessage = "[$Timestamp] [$Level] $Message"

    # Console
    Write-Host $LogMessage

    # Arquivo
    Add-Content -Path $LogFile -Value $LogMessage -Encoding UTF8
}

function Invoke-GitSync {
    Write-Log "🔄 Iniciando sincronização..." "INFO"

    $AgentLockStream = $null

    try {
        Push-Location $RepositoryPath

        # Coordinate with coding agents. Never stage a working tree while an
        # agent is reading/editing/testing it.
        $AgentLockPath = Join-Path $RepositoryPath ".git/shopvivaliz-agent-edit.lock"
        $AgentLockStream = [System.IO.File]::Open(
            $AgentLockPath,
            [System.IO.FileMode]::OpenOrCreate,
            [System.IO.FileAccess]::ReadWrite,
            [System.IO.FileShare]::ReadWrite
        )
        try {
            $AgentLockStream.Lock(0, 1)
        } catch {
            Write-Log "⏸️ Agente editando o repositorio; sincronizacao adiada." "WARN"
            return $false
        }

        # 1. Verificar se tem mudanças não commitadas
        Write-Log "📋 Verificando status local..." "INFO"
        $Status = & git status --porcelain
        if ($Status) {
            Write-Log "⚠️  Mudanças locais detectadas:" "WARN"
            $Status | ForEach-Object { Write-Log "   $_" "WARN" }

            Write-Log "💾 Commitando mudanças..." "INFO"
            & git add -A
            & git commit -m "auto: sincronizar mudanças $(Get-Date -Format 'HH:mm:ss')"

            if ($LASTEXITCODE -eq 0) {
                Write-Log "✅ Commit realizado com sucesso" "INFO"
            } else {
                Write-Log "❌ Erro ao fazer commit (pode estar tudo committed)" "WARN"
            }
        } else {
            Write-Log "✓ Sem mudanças locais" "INFO"
        }

        # 2. Fazer pull de mudanças remotas
        Write-Log "⬇️  Fazendo git pull..." "INFO"
        & git pull origin main 2>&1 | ForEach-Object {
            if ($_ -match "^Already up to date") {
                Write-Log "✓ Repositório já atualizado" "INFO"
            } else {
                Write-Log $_  "INFO"
            }
        }

        if ($LASTEXITCODE -ne 0) {
            Write-Log "⚠️  Git pull retornou código $LASTEXITCODE" "WARN"
        }

        # 3. Fazer push se necessário
        Write-Log "⬆️  Verificando push..." "INFO"
        $LocalCommits = & git rev-list --count origin/main..HEAD
        if ([int]$LocalCommits -gt 0) {
            Write-Log "📤 Enviando $LocalCommits commit(s)..." "INFO"
            & git push origin main

            if ($LASTEXITCODE -eq 0) {
                Write-Log "✅ Push realizado com sucesso" "INFO"
            } else {
                Write-Log "❌ Erro ao fazer push" "ERROR"
            }
        } else {
            Write-Log "✓ Sem mudanças para fazer push" "INFO"
        }

        # 4. Validar secrets
        Write-Log "🔐 Validando secrets..." "INFO"
        $ValidateOutput = & python3 scripts/validar_secrets.py 2>&1
        if ($LASTEXITCODE -eq 0) {
            Write-Log "✅ Secrets validados" "INFO"
        } else {
            Write-Log "⚠️  Secrets faltando (esperado se .env.local vazio)" "WARN"
        }

        Write-Log "✅ Sincronização concluída com sucesso!" "SUCCESS"
        return $true

    } catch {
        Write-Log "❌ Erro durante sincronização: $_" "ERROR"
        return $false
    } finally {
        if ($null -ne $AgentLockStream) {
            try { $AgentLockStream.Unlock(0, 1) } catch {}
            $AgentLockStream.Dispose()
        }
        Pop-Location
    }
}

# ============================================================================
# MAIN LOOP
# ============================================================================

Write-Log "🚀 Auto-Sync Git iniciado" "INFO"
Write-Log "📍 Repositório: $RepositoryPath" "INFO"
Write-Log "⏱️  Intervalo: $Interval minuto(s)" "INFO"
Write-Log "📝 Log: $LogFile" "INFO"
Write-Log "" "INFO"

if ($RunOnce) {
    Write-Log "🎯 Modo: Executar uma vez" "INFO"
    Invoke-GitSync | Out-Null
    exit 0
} else {
    Write-Log "🎯 Modo: Loop indefinido" "INFO"
    Write-Log "Pressione Ctrl+C para parar" "INFO"
    Write-Log "" "INFO"

    while ($true) {
        $StartTime = Get-Date

        # Executar sincronização
        Invoke-GitSync | Out-Null

        # Calcular próxima execução
        $EndTime = Get-Date
        $ElapsedSeconds = ($EndTime - $StartTime).TotalSeconds
        $SleepSeconds = [Math]::Max(($Interval * 60) - $ElapsedSeconds, 1)

        Write-Log "" "INFO"
        Write-Log "⏰ Próxima sincronização em $Interval minuto(s)" "INFO"

        # Sleep com possibilidade de interrupção
        for ($i = 0; $i -lt $SleepSeconds; $i++) {
            Start-Sleep -Seconds 1

            # Verificar a cada 30 segundos
            if ($i % 30 -eq 0 -and $i -gt 0) {
                Write-Log "   ... esperando ... ($([Math]::Floor($SleepSeconds - $i)) segundos restantes)" "INFO"
            }
        }

        Write-Log "" "INFO"
    }
}
