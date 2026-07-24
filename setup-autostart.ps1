# Script para configurar autostart do RCE Server
# Executa uma vez para registrar no Windows Task Scheduler
# Uso: .\setup-autostart.ps1

Write-Host @"
╔══════════════════════════════════════════════════════════════╗
║           CONFIGURAR AUTOSTART RCE SERVER                   ║
║                                                              ║
║  Este script registrará o RCE Server para iniciar           ║
║  automaticamente quando você fizer login no Windows.        ║
╚══════════════════════════════════════════════════════════════╝
"@

# Verificar se é admin
if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host "❌ Este script precisa de privilégios de Administrador"
    Write-Host "📝 Clique em 'Executar como administrador' e tente novamente"
    exit 1
}

$scriptPath = "$PSScriptRoot\start-rce-bg.ps1"
$taskName = "Start-RCE-Server"
$taskDescription = "Inicia RCE Server automaticamente ao fazer login"

# Verificar se tarefa já existe
$existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existingTask) {
    Write-Host "✅ Tarefa '$taskName' já existe"
    Write-Host ""
    Write-Host "Deseja substituir? (sim/nao)"
    $replace = Read-Host

    if ($replace -ne "sim") {
        Write-Host "❌ Cancelado"
        exit 0
    }

    Write-Host "🗑️  Removendo tarefa antiga..."
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

Write-Host "⚙️  Criando nova tarefa..."

# Criar ação
$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""

# Criar trigger (ao fazer login)
$trigger = New-ScheduledTaskTrigger -AtLogOn

# Criar settings
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -RunOnlyIfNetworkAvailable:$false

# Registrar tarefa
try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger $trigger `
        -Settings $settings `
        -Description $taskDescription `
        -RunLevel Highest `
        -ErrorAction Stop | Out-Null

    Write-Host "✅ Tarefa criada com sucesso!"
    Write-Host ""
    Write-Host "📋 Detalhes:"
    Write-Host "  Nome: $taskName"
    Write-Host "  Descrição: $taskDescription"
    Write-Host "  Disparo: Ao fazer login no Windows"
    Write-Host "  Privilégios: Administrador"
    Write-Host ""
    Write-Host "🚀 O RCE Server iniciará automaticamente no próximo login"
    Write-Host ""
    Write-Host "Para testar agora, execute:"
    Write-Host "  .\start-rce-bg.ps1"
    Write-Host ""

} catch {
    Write-Host "❌ Erro ao criar tarefa: $_"
    exit 1
}

# Listar a tarefa criada
Write-Host "📝 Tarefa registrada:"
Get-ScheduledTask -TaskName $taskName | Format-List TaskName, Description, State

Write-Host ""
Write-Host "✅ Autostart configurado com sucesso!"
