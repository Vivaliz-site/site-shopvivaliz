# Script para testar conexão FTP

Write-Host "Testando conexao FTP..." -ForegroundColor Cyan
Write-Host ""

# Carregar .env.local
if (Test-Path ".\.env.local") {
    $env_content = Get-Content ".\.env.local" | Where-Object { $_ -notmatch '^#' } | ConvertFrom-StringData
    $FTP_SERVER = $env_content["FTP_SERVER"]
    $FTP_USERNAME = $env_content["FTP_USERNAME"]
    $FTP_PASSWORD = $env_content["FTP_PASSWORD"]
    $FTP_PORT = $env_content["FTP_PORT"] -or "21"
} else {
    Write-Host "ERRO: Arquivo .env.local nao encontrado" -ForegroundColor Red
    exit 1
}

Write-Host "Configuracao FTP:"
Write-Host "  Host: $FTP_SERVER"
Write-Host "  Porta: $FTP_PORT"
Write-Host "  Usuario: $FTP_USERNAME"
Write-Host "  Senha: ***"
Write-Host ""

# Teste 1: Ping
Write-Host "1 Testando DNS/Ping..." -ForegroundColor Yellow
try {
    $ping = Test-NetConnection -ComputerName $FTP_SERVER -Port $FTP_PORT -WarningAction SilentlyContinue
    if ($ping.TcpTestSucceeded) {
        Write-Host "OK: Conexao TCP bem-sucedida" -ForegroundColor Green
    } else {
        Write-Host "ERRO: Conexao TCP falhou" -ForegroundColor Red
        Write-Host "   Verifique: servidor online? porta aberta? firewall?" -ForegroundColor Yellow
    }
} catch {
    Write-Host "ERRO: $_" -ForegroundColor Red
}

Write-Host ""
Write-Host "Para ver as credenciais corretas, abra:" -ForegroundColor Cyan
Write-Host "https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions"
