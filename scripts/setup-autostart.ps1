#!/usr/bin/env powershell
<#
.SYNOPSIS
  Configura iOS Listener para iniciar automaticamente no boot

.DESCRIPTION
  Cria uma tarefa no Windows Task Scheduler que executa o listener
  sempre que o Windows inicia.

.USAGE
  .\scripts\setup-autostart.ps1
  (Execute como Administrador)

#>

Write-Host ""
Write-Host "🔧 Configurando Auto-Start..." -ForegroundColor Cyan
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Cyan
Write-Host ""

# Verificar se é administrador
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (-not $isAdmin) {
    Write-Host "❌ Este script requer privilégios de Administrador!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Solução:" -ForegroundColor Yellow
    Write-Host "1. Abra PowerShell como Administrador"
    Write-Host "2. Execute novamente"
    Write-Host ""
    exit 1
}

$repoPath = "c:\site-shopvivaliz"
$scriptPath = "$repoPath\scripts\start-ios-listener-permanent.ps1"
$taskName = "ShopVivaliz iOS Listener"
$taskDescr = "Inicia iOS Command Listener automaticamente no boot"

Write-Host "✅ Rodando como Administrador" -ForegroundColor Green
Write-Host ""

# Verificar se script existe
if (-not (Test-Path $scriptPath)) {
    Write-Host "❌ Script não encontrado: $scriptPath" -ForegroundColor Red
    exit 1
}

Write-Host "📝 Criando tarefa agendada..." -ForegroundColor Yellow
Write-Host ""

# Remover tarefa antiga se existir
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existingTask) {
    Write-Host "🗑️  Removendo tarefa anterior..."
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
}

# Criar ação
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`"" `
    -WorkingDirectory $repoPath

# Criar trigger (ao ligar o PC)
$trigger = New-ScheduledTaskTrigger -AtStartup

# Criar configurações
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable `
    -RunOnlyIfIdle:$false

# Executar como usuário atual (SYSTEM)
$principal = New-ScheduledTaskPrincipal `
    -UserID "SYSTEM" `
    -RunLevel Highest

# Registrar tarefa
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Description $taskDescr `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Principal $principal `
        -Force | Out-Null

    Write-Host "✅ Tarefa criada com sucesso!" -ForegroundColor Green
    Write-Host ""
    Write-Host "📋 Detalhes:" -ForegroundColor Cyan
    Write-Host "   Nome: $taskName"
    Write-Host "   Descrição: $taskDescr"
    Write-Host "   Trigger: Ao iniciar Windows"
    Write-Host "   Usuário: SYSTEM (admin)"
    Write-Host ""

} catch {
    Write-Host "❌ Erro ao criar tarefa:" -ForegroundColor Red
    Write-Host $_.Exception.Message
    exit 1
}

# Testar se tarefa foi criada
Write-Host "🔍 Verificando tarefa..." -ForegroundColor Yellow
$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($task) {
    Write-Host "✅ Tarefa registrada corretamente" -ForegroundColor Green
    Write-Host ""
    Write-Host "📊 Status:"
    Write-Host "   Estado: $($task.State)"
    Write-Host "   Próxima execução: Ao reiniciar PC"
    Write-Host ""
} else {
    Write-Host "❌ Falha ao verificar tarefa" -ForegroundColor Red
    exit 1
}

Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor Green
Write-Host ""
Write-Host "✨ Auto-Start Configurado!" -ForegroundColor Green
Write-Host ""
Write-Host "O que vai acontecer:" -ForegroundColor Yellow
Write-Host "1. Próxima vez que ligar o PC"
Write-Host "2. iOS Listener inicia automaticamente"
Write-Host "3. Fica monitorando 24/7"
Write-Host "4. Se cair, reinicia sozinho"
Write-Host ""
Write-Host "🎯 Para testar AGORA (sem reiniciar):" -ForegroundColor Yellow
Write-Host "   1. Execute: node scripts/ios-command-listener.js"
Write-Host "   2. Ou execute: .\scripts\start-ios-listener-permanent.ps1"
Write-Host ""
