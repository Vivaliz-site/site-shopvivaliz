# SETUP AUTOMATICO - CLAUDE CODE COM HAIKU 4.5 COMO DEFAULT
# Execute como Admin: powershell -ExecutionPolicy Bypass -File setup-claude-haiku.ps1

Write-Host "Configurando Claude Code com Haiku 4.5 como default..." -ForegroundColor Green
Write-Host ""

# 1. Definir variaveis de ambiente
Write-Host "1. Definindo variaveis de ambiente..." -ForegroundColor Cyan
[System.Environment]::SetEnvironmentVariable("CLAUDE_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
[System.Environment]::SetEnvironmentVariable("ANTHROPIC_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
Write-Host "   OK: CLAUDE_DEFAULT_MODEL = haiku-4-5-20251001" -ForegroundColor Green
Write-Host ""

# 2. Atualizar PowerShell Profile
Write-Host "2. Atualizando PowerShell Profile..." -ForegroundColor Cyan

$profilePath = $PROFILE
$profileDir = Split-Path -Parent $profilePath

if (-not (Test-Path $profileDir)) {
    New-Item -ItemType Directory -Path $profileDir -Force | Out-Null
}

$profileContent = @"
# SHOPVIVALIZ CLAUDE CODE CONFIGURATION

`$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
`$env:ANTHROPIC_DEFAULT_MODEL = "haiku-4-5-20251001"

# Aliases para rapidez
function claude-cheap {
    `$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
    claude @args
}
function claude-fast {
    `$env:CLAUDE_DEFAULT_MODEL = "claude-opus-4-8-20250805"
    claude @args
}
Set-Alias -Name cc -Value claude-cheap -Force
Set-Alias -Name cf -Value claude-fast -Force
"@

if ((Test-Path $profilePath) -and (Get-Content $profilePath | Select-String "CLAUDE_DEFAULT_MODEL" -Quiet)) {
    Write-Host "   AVISO: Profile ja contem CLAUDE_DEFAULT_MODEL" -ForegroundColor Yellow
} else {
    Add-Content -Path $profilePath -Value $profileContent
    Write-Host "   OK: Aliases adicionados ao profile" -ForegroundColor Green
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "CONFIGURACAO COMPLETA!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "Novos comandos:" -ForegroundColor Cyan
Write-Host "  cc    - Claude Code com Haiku 4.5 (BARATO)" -ForegroundColor Yellow
Write-Host "  cf    - Claude Code com Opus 4.8 (RAPIDO)" -ForegroundColor Yellow
Write-Host ""
Write-Host "Proximo passo: Fechar e reabrir PowerShell" -ForegroundColor White
Write-Host ""
