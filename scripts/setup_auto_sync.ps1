#!/usr/bin/env pwsh
<#
.SYNOPSIS
Configurar sincronizacao automatica no Windows Task Scheduler

.DESCRIPTION
Cria uma tarefa no Task Scheduler que executa local-auto-sync.ps1 de forma segura

.PARAMETER Interval
Intervalo em minutos (padrao: 30)

.PARAMETER TaskName
Nome da tarefa no Task Scheduler (padrao: "ShopVivaliz Auto Sync")

.EXAMPLE
.\setup_auto_sync.ps1

.EXAMPLE
.\setup_auto_sync.ps1 -Interval 15

.EXAMPLE
.\setup_auto_sync.ps1 -Remove
#>

param(
    [int]$Interval = 30,
    [string]$TaskName = "ShopVivaliz Auto Sync",
    [switch]$Remove = $false,
    [switch]$Status = $false
)

$ErrorActionPreference = "Stop"

$isAdministrator = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole] "Administrator"
)
$runLevel = if ($isAdministrator) { "Highest" } else { "Limited" }

$repositoryPath = (Get-Item -Path $PSScriptRoot).Parent.FullName
$scriptPath = Join-Path (Join-Path $repositoryPath "scripts") "local-auto-sync.ps1"

Write-Host "Nivel da tarefa: $runLevel" -ForegroundColor Gray
Write-Host ""
Write-Host "ShopVivaliz - Setup de Auto Sync" -ForegroundColor Cyan
Write-Host ("=" * 60)
Write-Host "Repositorio: $repositoryPath" -ForegroundColor Gray
Write-Host "Script: $scriptPath" -ForegroundColor Gray
Write-Host "Tarefa: $TaskName" -ForegroundColor Gray
Write-Host "Intervalo: $Interval minuto(s)" -ForegroundColor Gray
Write-Host ""

function Test-TaskExists {
    param([string]$Name)

    try {
        $task = Get-ScheduledTask -TaskName $Name -ErrorAction SilentlyContinue
        return $null -ne $task
    } catch {
        return $false
    }
}

function Remove-AutoSyncTask {
    param([string]$Name)

    Write-Host "Removendo tarefa: $Name..." -ForegroundColor Yellow

    if (-not (Test-TaskExists -Name $Name)) {
        Write-Host "Tarefa nao existe (nada a remover)." -ForegroundColor Gray
        return $true
    }

    try {
        Unregister-ScheduledTask -TaskName $Name -Confirm:$false
        Write-Host "Tarefa removida com sucesso." -ForegroundColor Green
        return $true
    } catch {
        Write-Host "Erro ao remover tarefa: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Get-TaskStatus {
    Write-Host ""
    Write-Host "Status das tarefas ShopVivaliz:" -ForegroundColor Cyan
    Write-Host ("=" * 60)

    try {
        $tasks = Get-ScheduledTask | Where-Object {
            $_.TaskName -like "*ShopVivaliz*" -or $_.TaskName -like "*Auto Sync*"
        }

        if (-not $tasks) {
            Write-Host "Nenhuma tarefa encontrada." -ForegroundColor Gray
            return
        }

        foreach ($task in $tasks) {
            $taskInfo = Get-ScheduledTaskInfo -TaskName $task.TaskName
            $enabled = $task.State -ne "Disabled"

            Write-Host ""
            Write-Host $task.TaskName -ForegroundColor Cyan
            Write-Host "  Habilitada: $(if ($enabled) { 'Sim' } else { 'Nao' })" -ForegroundColor Gray
            Write-Host "  Ultima execucao: $($taskInfo.LastRunTime)" -ForegroundColor Gray
            Write-Host "  Proxima execucao: $($taskInfo.NextRunTime)" -ForegroundColor Gray
            Write-Host "  Resultado: $($taskInfo.LastTaskResult)" -ForegroundColor Gray
        }
    } catch {
        Write-Host "Erro ao obter status: $($_.Exception.Message)" -ForegroundColor Red
    }

    Write-Host ""
}

function Create-AutoSyncTask {
    param(
        [string]$Name,
        [int]$Minutes
    )

    Write-Host "Criando tarefa de sincronizacao..." -ForegroundColor Yellow
    Write-Host ""

    if (-not (Test-Path -LiteralPath $scriptPath)) {
        Write-Host "Script nao encontrado: $scriptPath" -ForegroundColor Red
        return $false
    }

    if (Test-TaskExists -Name $Name) {
        if (-not (Remove-AutoSyncTask -Name $Name)) {
            return $false
        }
    }

    try {
        $action = New-ScheduledTaskAction `
            -Execute "powershell.exe" `
            -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`" -IntervalMinutes $Minutes -OneTime"

        $trigger = New-ScheduledTaskTrigger `
            -Once `
            -At (Get-Date).AddMinutes(1) `
            -RepetitionInterval (New-TimeSpan -Minutes $Minutes) `
            -RepetitionDuration (New-TimeSpan -Days 3650)

        $settings = New-ScheduledTaskSettingsSet `
            -AllowStartIfOnBatteries `
            -DontStopIfGoingOnBatteries `
            -Compatibility Win8 `
            -MultipleInstances IgnoreNew `
            -RunOnlyIfNetworkAvailable

        Register-ScheduledTask `
            -TaskName $Name `
            -Action $action `
            -Trigger $trigger `
            -Settings $settings `
            -RunLevel $runLevel `
            -Force | Out-Null

        Write-Host "Tarefa criada com sucesso." -ForegroundColor Green
        Write-Host ""
        Write-Host "Detalhes:" -ForegroundColor Cyan
        Write-Host "  Nome: $Name" -ForegroundColor Gray
        Write-Host "  Script: $scriptPath" -ForegroundColor Gray
        Write-Host "  Intervalo: $Minutes minuto(s)" -ForegroundColor Gray
        Write-Host "  Nivel: $runLevel" -ForegroundColor Gray
        return $true
    } catch {
        Write-Host "Erro ao criar tarefa: $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

if ($Remove) {
    Write-Host ""
    $success = Remove-AutoSyncTask -Name $TaskName
    Write-Host ""
    if ($success) { exit 0 } else { exit 1 }
}

if ($Status) {
    Get-TaskStatus
    exit 0
}

$success = Create-AutoSyncTask -Name $TaskName -Minutes $Interval

if ($success) {
    Write-Host ""
    Write-Host "Setup completo." -ForegroundColor Green
    Write-Host ""
    Write-Host "Proximos passos:" -ForegroundColor Cyan
    Write-Host "  1. Verificar logs em: logs/local-sync-*.log" -ForegroundColor Gray
    Write-Host "  2. Ver status: .\scripts\setup_auto_sync.ps1 -Status" -ForegroundColor Gray
    Write-Host "  3. Parar: .\scripts\setup_auto_sync.ps1 -Remove" -ForegroundColor Gray
    Write-Host ""
    Write-Host "A tarefa executara o primeiro ciclo em ate um minuto." -ForegroundColor Gray
    exit 0
}

Write-Host ""
Write-Host "Setup falhou." -ForegroundColor Red
exit 1
