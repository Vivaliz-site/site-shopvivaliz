# ╔════════════════════════════════════════════════════════════════╗
# ║  SETUP AUTOMÁTICO - CLAUDE CODE COM HAIKU 4.5 DEFAULT         ║
# ║  Execute como Admin: powershell -ExecutionPolicy Bypass -File ║
# ╚════════════════════════════════════════════════════════════════╝

Write-Host "🚀 Configurando Claude Code com Haiku 4.5 como default..." -ForegroundColor Green
Write-Host ""

# 1. Definir variável de ambiente global
Write-Host "1️⃣ Configurando variável de ambiente CLAUDE_DEFAULT_MODEL..." -ForegroundColor Cyan
[System.Environment]::SetEnvironmentVariable("CLAUDE_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
[System.Environment]::SetEnvironmentVariable("ANTHROPIC_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
Write-Host "✅ Variáveis de ambiente configuradas" -ForegroundColor Green
Write-Host ""

# 2. Atualizar PowerShell Profile
Write-Host "2️⃣ Atualizando PowerShell Profile..." -ForegroundColor Cyan

$profilePath = $PROFILE
$profileDir = Split-Path -Parent $profilePath

if (-not (Test-Path $profileDir)) {
    New-Item -ItemType Directory -Path $profileDir -Force | Out-Null
}

$profileContent = @"
# ╔════════════════════════════════════════╗
# ║ ShopVivaliz Claude Code Configuration  ║
# ╚════════════════════════════════════════╝

# Default Model: Haiku 4.5 (Cheapest)
`$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
`$env:ANTHROPIC_DEFAULT_MODEL = "haiku-4-5-20251001"

# Aliases
function claude-cheap {
    Write-Host "🚀 Claude Code - Haiku 4.5 (Cheapest Model)" -ForegroundColor Green
    `$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
    claude @args
}

function claude-fast {
    Write-Host "⚡ Claude Code - Opus 4.8 (Faster)" -ForegroundColor Yellow
    `$env:CLAUDE_DEFAULT_MODEL = "claude-opus-4-8-20250805"
    claude @args
}

Set-Alias -Name cc -Value claude-cheap -Force
Set-Alias -Name cf -Value claude-fast -Force

# Welcome message
Write-Host "✅ Claude Code configured: cc=Haiku (cheap), cf=Opus (fast)" -ForegroundColor Cyan
"@

# Append to profile if not already there
if ((Test-Path $profilePath) -and (Get-Content $profilePath | Select-String "CLAUDE_DEFAULT_MODEL" -Quiet)) {
    Write-Host "⚠️  Profile já contém configuração CLAUDE" -ForegroundColor Yellow
} else {
    Add-Content -Path $profilePath -Value $profileContent
    Write-Host "✅ PowerShell Profile atualizado" -ForegroundColor Green
}

Write-Host ""
Write-Host "════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "✅ CONFIGURAÇÃO COMPLETA!" -ForegroundColor Green
Write-Host "════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host ""
Write-Host "📝 Novos comandos disponíveis:" -ForegroundColor Cyan
Write-Host "  • cc                  # Abre Claude Code com Haiku 4.5 (BARATO)" -ForegroundColor Yellow
Write-Host "  • cf                  # Abre Claude Code com Opus 4.8 (RÁPIDO)" -ForegroundColor Yellow
Write-Host ""
Write-Host "🔄 Próximo passo:" -ForegroundColor Cyan
Write-Host "  1. Fechar PowerShell" -ForegroundColor White
Write-Host "  2. Reabrir PowerShell (para carregar novo profile)" -ForegroundColor White
Write-Host "  3. Testar: cc" -ForegroundColor White
Write-Host ""
Write-Host "💡 Dica: Sempre use 'cc' para economizar tokens!" -ForegroundColor Green
