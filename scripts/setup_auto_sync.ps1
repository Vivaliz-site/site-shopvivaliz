#!/usr/bin/env pwsh
<#
.SYNOPSIS
Configurar sincronização automática no Windows Task Scheduler

.DESCRIPTION
Cria uma tarefa no Task Scheduler que executa auto_sync_git.ps1 periodicamente

.PARAMETER Interval
Intervalo em minutos (padrão: 30)

.PARAMETER TaskName
Nome da tarefa no Task Scheduler (padrão: "ShopVivaliz Auto Sync")

.EXAMPLE
# Setup com intervalo de 30 minutos
.\setup_auto_sync.ps1

# Setup com intervalo de 15 minutos
.\setup_auto_sync.ps1 -Interval 15

# Remover tarefa
.\setup_auto_sync.ps1 -Remove

#>

param(
    [int]$Interval = 30,
    [string]$TaskName = "ShopVivaliz Auto Sync",
    [switch]$Remove = $false,
    [switch]$Status = $false
)

$ErrorActionPreference = "Stop"

# ============================================================================
# VERIFICAR NIVEL DE EXECUCAO
# ============================================================================

$IsAdministrator = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")
$RunLevel = if ($IsAdministrator) { "Highest" } else { "Limited" }
Write-Host "Nivel da tarefa: $RunLevel" -ForegroundColor Gray

# ============================================================================
# CONFIGURAÇÃO
# ============================================================================

$RepositoryPath = (Get-Item -Path $PSScriptRoot).Parent.FullName
$ScriptPath = Join-Path $RepositoryPath "scripts" "local-auto-sync.ps1"
$TaskFolderName = "ShopVivaliz"

Write-Host ""
Write-Host "🚀 ShopVivaliz - Setup de Auto Sync" -ForegroundColor Cyan
Write-Host ("=" * 60)
Write-Host "Repositório: $RepositoryPath" -ForegroundColor Gray
Write-Host "Script: $ScriptPath" -ForegroundColor Gray
Write-Host "Tarefa: $TaskName" -ForegroundColor Gray
Write-Host "Intervalo: $Interval minuto(s)" -ForegroundColor Gray
Write-Host ""

# ============================================================================
# FUNÇÕES
# ============================================================================

function Test-TaskExists {
    param([string]$TaskName)
    try {
        $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
        return $null -ne $task
    } catch {
        return $false
    }
}

function Remove-AutoSyncTask {
    Write-Host "🗑️  Removendo tarefa: $TaskName..." -ForegroundColor Yellow

    if (Test-TaskExists -TaskName $TaskName) {
        try {
            Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
            Write-Host "✅ Tarefa removida com sucesso!" -ForegroundColor Green
            return $true
        } catch {
            Write-Host "❌ Erro ao remover tarefa: $_" -ForegroundColor Red
            return $false
        }
    } else {
        Write-Host "ℹ️  Tarefa não existe (nada a remover)" -ForegroundColor Gray
        return $true
    }
}

function Get-TaskStatus {
    Write-Host ""
    Write-Host "📋 Status das Tarefas ShopVivaliz:" -ForegroundColor Cyan
    Write-Host ("=" * 60)

    try {
        $tasks = Get-ScheduledTask | Where-Object { $_.TaskName -like "*ShopVivaliz*" -or $_.TaskName -like "*Auto Sync*" }

        if ($tasks.Count -eq 0) {
            Write-Host "⭕ Nenhuma tarefa encontrada" -ForegroundColor Gray
        } else {
            foreach ($task in $tasks) {
                $taskInfo = Get-ScheduledTaskInfo -TaskName $task.TaskName
                $lastRun = $taskInfo.LastRunTime
                $lastResult = $taskInfo.LastTaskResult
                $enabled = $task.State -ne "Disabled"

                Write-Host ""
                Write-Host "📌 $($task.TaskName)" -ForegroundColor Cyan
                Write-Host "   Habilitada: $(if ($enabled) { '✅ Sim' } else { '❌ Não' })" -ForegroundColor Gray
                Write-Host "   Última execução: $(if ($lastRun) { $lastRun } else { 'Nunca executada' })" -ForegroundColor Gray
                Write-Host "   Resultado: $lastResult" -ForegroundColor Gray
            }
        }
    } catch {
        Write-Host "❌ Erro ao obter status: $_" -ForegroundColor Red
    }

    Write-Host ""
}

function Create-AutoSyncTask {
    Write-Host "📝 Criando tarefa de sincronização..." -ForegroundColor Yellow
    Write-Host ""

    # Verificar se script existe
    if (-not (Test-Path $ScriptPath)) {
        Write-Host "❌ Script não encontrado: $ScriptPath" -ForegroundColor Red
        return $false
    }

    # Se tarefa existe, remover primeiro
    if (Test-TaskExists -TaskName $TaskName) {
        Write-Host "⚠️  Tarefa já existe, removendo..." -ForegroundColor Yellow
        Remove-AutoSyncTask
    }

    try {
        # Criar ação de tarefa
        $Action = New-ScheduledTaskAction `
            -Execute "powershell.exe" `
            -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$ScriptPath`" -IntervalMinutes $Interval -OneTime"

        # Criar trigger (a cada N minutos, indefinidamente)
        $Trigger = New-ScheduledTaskTrigger `
            -Once `
            -At (Get-Date).AddMinutes(1) `
            -RepetitionInterval (New-TimeSpan -Minutes $Interval) `
            -RepetitionDuration (New-TimeSpan -Days 3650)

        # Criar configuração de tarefa
        $Settings = New-ScheduledTaskSettingsSet `
            -AllowStartIfOnBatteries `
            -DontStopIfGoingOnBatteries `
            -Compatibility Win8 `
            -MultipleInstances IgnoreNew `
            -RunOnlyIfNetworkAvailable

        # Registrar tarefa
        Register-ScheduledTask `
            -TaskName $TaskName `
            -Action $Action `
            -Trigger $Trigger `
            -Settings $Settings `
            -RunLevel $RunLevel `
            -Force | Out-Null

        Write-Host "✅ Tarefa criada com sucesso!" -ForegroundColor Green
        Write-Host ""
        Write-Host "📋 Detalhes:" -ForegroundColor Cyan
        Write-Host "   Nome: $TaskName" -ForegroundColor Gray
        Write-Host "   Script: $ScriptPath" -ForegroundColor Gray
        Write-Host "   Intervalo: $Interval minuto(s)" -ForegroundColor Gray
        Write-Host "   Nível: $RunLevel" -ForegroundColor Gray
        Write-Host "   Status: Ativa" -ForegroundColor Green

        return $true

    } catch {
        Write-Host "❌ Erro ao criar tarefa: $_" -ForegroundColor Red
        return $false
    }
}

# ============================================================================
# MAIN
# ============================================================================

if ($Remove) {
    Write-Host ""
    $success = Remove-AutoSyncTask
    Write-Host ""
    exit (if ($success) { 0 } else { 1 })
}

if ($Status) {
    Get-TaskStatus
    exit 0
}

# Setup normal
$success = Create-AutoSyncTask

if ($success) {
    Write-Host ""
    Write-Host "🎉 Setup completo!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Próximos passos:" -ForegroundColor Cyan
    Write-Host "  1. Verificar logs em: logs/local-sync-*.log" -ForegroundColor Gray
    Write-Host "  2. Ver status: .\scripts\setup_auto_sync.ps1 -Status" -ForegroundColor Gray
    Write-Host "  3. Parar: .\scripts\setup_auto_sync.ps1 -Remove" -ForegroundColor Gray
    Write-Host ""

    Write-Host "A tarefa executara o primeiro teste em ate um minuto." -ForegroundColor Gray
} else {
    Write-Host ""
    Write-Host "❌ Setup falhou!" -ForegroundColor Red
    exit 1
}
