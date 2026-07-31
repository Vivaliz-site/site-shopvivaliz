#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Instala agendador de backup automático diário
.DESCRIPTION
    Cria tarefa agendada no Windows Task Scheduler para executar
    backup-daily.ps1 todos os dias às 02:00 AM
.PARAMETER Time
    Hora para executar o backup (padrão: 02:00 - 2 AM)
.EXAMPLE
    .\install-backup-scheduler.ps1
    .\install-backup-scheduler.ps1 -Time "03:30"
#>

param(
    [string]$Time = "02:00"
)

$ErrorActionPreference = "Stop"

Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "⏰ INSTALAR AGENDADOR DE BACKUP AUTOMÁTICO" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# ================================================================================
# VALIDAÇÕES
# ================================================================================

# Verificar se é administrador
$isAdmin = [Security.Principal.WindowsIdentity]::GetCurrent().Groups -contains [Security.Principal.SecurityIdentifier]'S-1-5-32-544'
if (-not $isAdmin) {
    Write-Host "❌ ERRO: Execute com privilégios de administrador" -ForegroundColor Red
    Write-Host "   Abra PowerShell como Administrador e execute novamente" -ForegroundColor Yellow
    exit 1
}

Write-Host "✅ Executando como administrador" -ForegroundColor Green
Write-Host ""

# Validar hora
if ($Time -notmatch '^\d{2}:\d{2}$') {
    Write-Host "❌ ERRO: Hora inválida. Use formato HH:mm (ex: 02:00)" -ForegroundColor Red
    exit 1
}

Write-Host "⏰ Backup agendado para: $Time todos os dias" -ForegroundColor Green
Write-Host ""

# ================================================================================
# CAMINHOS
# ================================================================================

$repoPath = "C:\Users\FRED\site-shopvivaliz"
$scriptPath = "$repoPath\scripts\backup-daily.ps1"
$taskName = "ShopVivaliz-DailyBackup"
$taskDescription = "Backup automático diário do repositório ShopVivaliz"

Write-Host "📍 Configuração:" -ForegroundColor Cyan
Write-Host "  Script: $scriptPath" -ForegroundColor Gray
Write-Host "  Tarefa: $taskName" -ForegroundColor Gray
Write-Host ""

# Validar se script existe
if (-not (Test-Path $scriptPath)) {
    Write-Host "❌ ERRO: Script não encontrado: $scriptPath" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Script encontrado" -ForegroundColor Green
Write-Host ""

# ================================================================================
# REMOVER TAREFA EXISTENTE (SE HOUVER)
# ================================================================================

Write-Host "🔄 Verificando tarefa existente..." -ForegroundColor Yellow

$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "  ⚠️  Tarefa existente encontrada: $taskName" -ForegroundColor Yellow
    Write-Host "  Deletando..." -ForegroundColor Gray

    try {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction Stop
        Write-Host "  ✅ Tarefa removida com sucesso" -ForegroundColor Green
    } catch {
        Write-Host "  ⚠️  Erro ao remover tarefa anterior: $_" -ForegroundColor Yellow
    }
}

Write-Host ""

# ================================================================================
# CRIAR TAREFA AGENDADA
# ================================================================================

Write-Host "📝 CRIANDO TAREFA AGENDADA..." -ForegroundColor Yellow

try {
    # Converter tempo para objeto DateTime
    [TimeSpan]$timeSpan = [TimeSpan]::Parse($Time)

    # Criar trigger (diário)
    $trigger = New-ScheduledTaskTrigger `
        -Daily `
        -At $Time `
        -ErrorAction Stop

    Write-Host "  ✅ Trigger criado: todos os dias às $Time" -ForegroundColor Green

    # Criar ação (executar PowerShell script)
    $action = New-ScheduledTaskAction `
        -Execute "powershell.exe" `
        -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`"" `
        -WorkingDirectory "$repoPath" `
        -ErrorAction Stop

    Write-Host "  ✅ Ação criada: executar script" -ForegroundColor Green

    # Criar settings
    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -Compatibility Win8 `
        -ErrorAction Stop

    Write-Host "  ✅ Configurações criadas" -ForegroundColor Green

    # Criar principal (executar como usuário atual)
    $principal = New-ScheduledTaskPrincipal `
        -UserID "$env:USERDOMAIN\$env:USERNAME" `
        -LogonType Interactive `
        -RunLevel Highest `
        -ErrorAction Stop

    Write-Host "  ✅ Principal criado: $env:USERDOMAIN\$env:USERNAME" -ForegroundColor Green

    # Registrar tarefa
    $task = Register-ScheduledTask `
        -TaskName $taskName `
        -Trigger $trigger `
        -Action $action `
        -Settings $settings `
        -Principal $principal `
        -Description $taskDescription `
        -Force `
        -ErrorAction Stop

    Write-Host "  ✅ Tarefa registrada com sucesso" -ForegroundColor Green

} catch {
    Write-Host "❌ ERRO ao criar tarefa: $_" -ForegroundColor Red
    exit 1
}

Write-Host ""

# ================================================================================
# VALIDAR TAREFA
# ================================================================================

Write-Host "✔️ VALIDANDO TAREFA..." -ForegroundColor Yellow

$createdTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($createdTask) {
    Write-Host "✅ Tarefa encontrada" -ForegroundColor Green
    Write-Host "  Nome: $($createdTask.TaskName)" -ForegroundColor Gray
    Write-Host "  Caminho: $($createdTask.TaskPath)" -ForegroundColor Gray
    Write-Host "  Estado: $($createdTask.State)" -ForegroundColor Gray
} else {
    Write-Host "❌ ERRO: Tarefa não foi criada" -ForegroundColor Red
    exit 1
}

Write-Host ""

# ================================================================================
# RESUMO
# ================================================================================

$summary = @"
════════════════════════════════════════════════════════
✅ AGENDADOR INSTALADO COM SUCESSO
════════════════════════════════════════════════════════
Tarefa: $taskName
Horário: $Time (diariamente)
Status: ATIVO
Script: $scriptPath

Próximo backup: Amanhã às $Time
Retenção: 10 dias mínimo

Diretório de backups: C:\backups\site-shopvivaliz
Diretório de logs: C:\backups\site-shopvivaliz\logs

PRÓXIMOS PASSOS:
1. Instale 7-Zip (recomendado para melhor compressão)
   https://www.7-zip.org/download.html
2. Monitore os backups em: C:\backups\site-shopvivaliz\
3. Verifique os logs diários em: C:\backups\site-shopvivaliz\logs\

PARA EXECUTAR BACKUP MANUALMENTE:
  .\scripts\backup-daily.ps1

PARA ALTERAR HORÁRIO:
  .\scripts\install-backup-scheduler.ps1 -Time "03:30"

PARA DESINSTALAR:
  Unregister-ScheduledTask -TaskName "$taskName" -Confirm:`$false
════════════════════════════════════════════════════════
"@

Write-Host $summary -ForegroundColor Green

exit 0
