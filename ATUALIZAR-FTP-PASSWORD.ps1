# Script para atualizar FTP_PASSWORD e disparar deploy

Write-Host "🔐 ATUALIZAR FTP_PASSWORD" -ForegroundColor Cyan
Write-Host "════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""

# Pedir o FTP_PASSWORD
$password = Read-Host "Digite o FTP_PASSWORD" -AsSecureString

# Converter para plain text (apenas para usar com gh cli)
$plainPassword = [System.Net.NetworkCredential]::new('', $password).Password

# Atualizar no GitHub
Write-Host ""
Write-Host "Atualizando FTP_PASSWORD no GitHub..." -ForegroundColor Yellow
$plainPassword | gh secret set FTP_PASSWORD

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ FTP_PASSWORD atualizado com sucesso!" -ForegroundColor Green
    Write-Host ""
    Write-Host "📋 Secrets agora configurados:" -ForegroundColor Cyan
    Write-Host "  ✅ FTP_SERVER: ftp.shopvivaliz.com.br"
    Write-Host "  ✅ FTP_USERNAME: dev5@dev.shopvivaliz.com.br"
    Write-Host "  ✅ FTP_PORT: 21"
    Write-Host "  ✅ FTP_REMOTE_DIR: /public_html/dev/"
    Write-Host "  ✅ FTP_PASSWORD: [Atualizado]"
    Write-Host ""
    Write-Host "🚀 Disparando deploy automaticamente..." -ForegroundColor Green
    Write-Host ""

    # Fazer um push vazio para disparar o deploy
    git commit --allow-empty -m "chore: trigger deploy with corrected FTP secrets"
    git push origin main

    Write-Host ""
    Write-Host "✅ Deploy disparado!" -ForegroundColor Green
    Write-Host ""
    Write-Host "📊 Monitorar progresso em:" -ForegroundColor Cyan
    Write-Host "https://github.com/Vivaliz-site/site-shopvivaliz/actions" -ForegroundColor Cyan

} else {
    Write-Host "❌ Erro ao atualizar FTP_PASSWORD" -ForegroundColor Red
    exit 1
}
