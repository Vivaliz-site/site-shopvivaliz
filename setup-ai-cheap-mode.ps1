# SETUP AUTOMATICO - CLAUDE + CODEX COM MODO BARATO COMO DEFAULT
# Execute como Admin: powershell -ExecutionPolicy Bypass -File setup-ai-cheap-mode.ps1

Write-Host "Configurando Claude Code + Codex com modo BARATO como default..." -ForegroundColor Green
Write-Host ""

# PARTE 1: CLAUDE CODE - HAIKU 4.5 COMO DEFAULT
Write-Host "[1/3] Claude Code - Haiku 4.5 (BARATO)" -ForegroundColor Cyan
Write-Host "=======================================" -ForegroundColor Cyan

[System.Environment]::SetEnvironmentVariable("CLAUDE_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
[System.Environment]::SetEnvironmentVariable("ANTHROPIC_DEFAULT_MODEL", "haiku-4-5-20251001", "User")
Write-Host "      Variavel: CLAUDE_DEFAULT_MODEL = haiku-4-5-20251001" -ForegroundColor Green
Write-Host ""

# PARTE 2: CODEX (GPT) - MODO BARATO COMO DEFAULT
Write-Host "[2/3] Codex (GPT) - Modo BARATO como default" -ForegroundColor Cyan
Write-Host "=============================================" -ForegroundColor Cyan

[System.Environment]::SetEnvironmentVariable("CODEX_MODEL", "gpt-4o-mini", "User")
[System.Environment]::SetEnvironmentVariable("OPENAI_DEFAULT_MODEL", "gpt-4o-mini", "User")
[System.Environment]::SetEnvironmentVariable("GPT_CHEAP_MODE", "true", "User")
Write-Host "      Variavel: CODEX_MODEL = gpt-4o-mini (BARATO)" -ForegroundColor Green
Write-Host "      Variavel: GPT_CHEAP_MODE = true" -ForegroundColor Green
Write-Host ""

# PARTE 3: ATUALIZAR POWERSHELL PROFILE
Write-Host "[3/3] Atualizando PowerShell Profile..." -ForegroundColor Cyan
Write-Host "=======================================" -ForegroundColor Cyan

$profilePath = $PROFILE
$profileDir = Split-Path -Parent $profilePath

if (-not (Test-Path $profileDir)) {
    New-Item -ItemType Directory -Path $profileDir -Force | Out-Null
}

$profileContent = @"
# SHOPVIVALIZ - CLAUDE + CODEX MODO BARATO COMO DEFAULT

`$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
`$env:ANTHROPIC_DEFAULT_MODEL = "haiku-4-5-20251001"

`$env:CODEX_MODEL = "gpt-4o-mini"
`$env:OPENAI_DEFAULT_MODEL = "gpt-4o-mini"
`$env:GPT_CHEAP_MODE = "true"

function claude-cheap {
    `$env:CLAUDE_DEFAULT_MODEL = "haiku-4-5-20251001"
    claude @args
}

function claude-fast {
    `$env:CLAUDE_DEFAULT_MODEL = "claude-opus-4-8-20250805"
    claude @args
}

function codex-cheap {
    `$env:CODEX_MODEL = "gpt-4o-mini"
    codex @args
}

function codex-fast {
    `$env:CODEX_MODEL = "gpt-4-turbo"
    codex @args
}

Set-Alias -Name cc -Value claude-cheap -Force
Set-Alias -Name cf -Value claude-fast -Force
Set-Alias -Name gx -Value codex-cheap -Force
Set-Alias -Name gf -Value codex-fast -Force
"@

if ((Test-Path $profilePath) -and (Get-Content $profilePath | Select-String "CLAUDE_DEFAULT_MODEL" -Quiet)) {
    Write-Host "      AVISO: Profile ja contem configuracao" -ForegroundColor Yellow
} else {
    Add-Content -Path $profilePath -Value $profileContent
    Write-Host "      OK: Aliases adicionados ao profile" -ForegroundColor Green
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "CONFIGURACAO COMPLETA!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""
Write-Host "NOVOS COMANDOS:" -ForegroundColor Cyan
Write-Host "  cc = Claude Haiku (BARATO - padrao)" -ForegroundColor White
Write-Host "  cf = Claude Opus (rapido)" -ForegroundColor White
Write-Host "  gx = Codex GPT-4o-mini (BARATO - padrao)" -ForegroundColor White
Write-Host "  gf = Codex GPT-4-turbo (rapido)" -ForegroundColor White
Write-Host ""
Write-Host "Custo: ~8/mes com modo BARATO" -ForegroundColor Green
Write-Host ""
