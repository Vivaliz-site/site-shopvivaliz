#!/usr/bin/env pwsh
# Script para configurar automaticamente todos os GitHub Secrets

Write-Host @"
╔═══════════════════════════════════════════════════════════════════════╗
║                                                                       ║
║         🔐 CONFIGURADOR AUTOMÁTICO DE GITHUB SECRETS                 ║
║                                                                       ║
║         Configure credenciais no repositório automaticamente         ║
║                                                                       ║
╚═══════════════════════════════════════════════════════════════════════╝
"@ -ForegroundColor Cyan

# Verificar se gh CLI está instalado
Write-Host "`n🔍 Verificando GitHub CLI..." -ForegroundColor Yellow
try {
    gh --version | Out-Null
    Write-Host "✅ GitHub CLI encontrado" -ForegroundColor Green
} catch {
    Write-Host "❌ GitHub CLI não encontrado. Instale com: winget install gh" -ForegroundColor Red
    exit 1
}

# Definir credenciais (ADICIONE SEUS VALORES AQUI)
$secrets = @{
    # IA/APIs
    "OPENAI_API_KEY" = ""  # sk-proj-...
    "ANTHROPIC_API_KEY" = ""  # sk-ant-...

    # Shopee
    "SHOPEE_PARTNER_ID" = ""  # 1237032
    "SHOPEE_PARTNER_KEY" = ""  # shpk_...

    # TikTok Shop
    "TIKTOK_CLIENT_ID" = ""  # 7...
    "TIKTOK_CLIENT_SECRET" = ""  # secret_...

    # FTP
    "FTP_SERVER" = ""  # ftp.shopvivaliz.com.br
    "FTP_USERNAME" = ""  # usuario
    "FTP_PASSWORD" = ""  # senha
    "FTP_PORT" = "21"

    # Email
    "EMAIL_FROM" = "noreply@shopvivaliz.com.br"
    "EMAIL_TO" = "fredmourao@gmail.com"
    "EMAIL_SMTP_HOST" = "smtp.gmail.com"
    "EMAIL_SMTP_PORT" = "587"
    "EMAIL_USER" = ""  # seu-email@gmail.com
    "EMAIL_PASSWORD" = ""  # app-password

    # GitHub
    "GH_REPO_TOKEN" = ""  # ghp_...

    # Pagarme
    "PAGARME_SECRET_KEY" = "" # sk_test_... ou sk_live_...
    "PAGARME_PUBLIC_KEY" = "" # pk_test_... ou pk_live_...
}

Write-Host "`n📝 CREDENCIAIS NECESSÁRIAS:" -ForegroundColor Yellow
Write-Host "`n1️⃣  OPENAI_API_KEY"
Write-Host "   Obter em: https://platform.openai.com/api-keys"
Write-Host "   Formato: sk-proj-xxxxxxx"
$openai = Read-Host "   Valor"
if ($openai) { $secrets["OPENAI_API_KEY"] = $openai }

Write-Host "`n2️⃣  SHOPEE_PARTNER_ID"
Write-Host "   Obter em: https://partner.shopee.com.br/ → Settings"
$shopee_id = Read-Host "   Valor"
if ($shopee_id) { $secrets["SHOPEE_PARTNER_ID"] = $shopee_id }

Write-Host "`n3️⃣  SHOPEE_PARTNER_KEY"
Write-Host "   Obter em: https://partner.shopee.com.br/ → Settings"
$shopee_key = Read-Host "   Valor"
if ($shopee_key) { $secrets["SHOPEE_PARTNER_KEY"] = $shopee_key }

Write-Host "`n4️⃣  TIKTOK_CLIENT_ID"
Write-Host "   Obter em: https://seller.tiktok.com/ → Developer"
$tiktok_id = Read-Host "   Valor"
if ($tiktok_id) { $secrets["TIKTOK_CLIENT_ID"] = $tiktok_id }

Write-Host "`n5️⃣  TIKTOK_CLIENT_SECRET"
Write-Host "   Obter em: https://seller.tiktok.com/ → Developer"
$tiktok_secret = Read-Host "   Valor"
if ($tiktok_secret) { $secrets["TIKTOK_CLIENT_SECRET"] = $tiktok_secret }

Write-Host "`n6️⃣  FTP_SERVER"
Write-Host "   Exemplo: ftp.shopvivaliz.com.br"
$ftp_server = Read-Host "   Valor"
if ($ftp_server) { $secrets["FTP_SERVER"] = $ftp_server }

Write-Host "`n7️⃣  FTP_USERNAME"
Write-Host "   Seu usuário FTP"
$ftp_user = Read-Host "   Valor"
if ($ftp_user) { $secrets["FTP_USERNAME"] = $ftp_user }

Write-Host "`n8️⃣  FTP_PASSWORD"
Write-Host "   Sua senha FTP"
$ftp_pass = Read-Host "   Valor"
if ($ftp_pass) { $secrets["FTP_PASSWORD"] = $ftp_pass }

Write-Host "`n9️⃣  EMAIL_USER (Gmail)"
Write-Host "   Exemplo: seu-email@gmail.com"
$email_user = Read-Host "   Valor"
if ($email_user) { $secrets["EMAIL_USER"] = $email_user }

Write-Host "`n🔟 EMAIL_PASSWORD (Gmail App Password)"
Write-Host "   Obter em: https://myaccount.google.com/app-passwords"
$email_pass = Read-Host "   Valor"
if ($email_pass) { $secrets["EMAIL_PASSWORD"] = $email_pass }

Write-Host "`n1️⃣1️⃣ PAGARME_SECRET_KEY"
Write-Host "   Obter em: Dashboard Pagarme → Configurações → Chaves de API"
$pagarme_secret = Read-Host "   Valor"
if ($pagarme_secret) { $secrets["PAGARME_SECRET_KEY"] = $pagarme_secret }

Write-Host "`n1️⃣2️⃣ PAGARME_PUBLIC_KEY"
Write-Host "   Obter em: Dashboard Pagarme → Configurações → Chaves de API"
$pagarme_public = Read-Host "   Valor"
if ($pagarme_public) { $secrets["PAGARME_PUBLIC_KEY"] = $pagarme_public }

# Configurar secrets no GitHub
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "📤 CONFIGURANDO SECRETS NO GITHUB..." -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Cyan

$configured = 0
$skipped = 0

foreach ($secret_name in $secrets.Keys) {
    $secret_value = $secrets[$secret_name]

    if ([string]::IsNullOrWhiteSpace($secret_value)) {
        Write-Host "⏭️  $secret_name - PULADO (sem valor)" -ForegroundColor Gray
        $skipped++
        continue
    }

    Write-Host "⏳ Configurando $secret_name..." -ForegroundColor Yellow

    try {
        # Usar gh secret set com valor do stdin
        $secret_value | gh secret set $secret_name --repo fredmourao-ai/site-shopvivaliz

        Write-Host "✅ $secret_name configurado" -ForegroundColor Green
        $configured++
    } catch {
        Write-Host "❌ Erro ao configurar $secret_name`: $_" -ForegroundColor Red
    }
}

Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "✅ RESULTADO:" -ForegroundColor Green
Write-Host "   Configurados: $configured" -ForegroundColor Green
Write-Host "   Pulados: $skipped" -ForegroundColor Yellow
Write-Host "═══════════════════════════════════════════════════════════════" -ForegroundColor Green

# Listar secrets configurados
Write-Host "`n📋 SECRETS CONFIGURADOS NO GITHUB:" -ForegroundColor Cyan
Write-Host "`nListando..." -ForegroundColor Yellow

try {
    gh secret list --repo fredmourao-ai/site-shopvivaliz
} catch {
    Write-Host "❌ Erro ao listar secrets. Verifique: https://github.com/.../settings/secrets/actions" -ForegroundColor Red
}

Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "🎉 PRÓXIMO PASSO:" -ForegroundColor Cyan
Write-Host "`n1. Fazer push para disparar o workflow:" -ForegroundColor White
Write-Host "   git push origin main" -ForegroundColor Yellow
Write-Host "`n2. Monitorar execução:" -ForegroundColor White
Write-Host "   https://github.com/fredmourao-ai/site-shopvivaliz/actions" -ForegroundColor Yellow
Write-Host "`n3. Dashboard:" -ForegroundColor White
Write-Host "   https://dev.shopvivaliz.com.br/admin/monitor/" -ForegroundColor Yellow
Write-Host "`n═══════════════════════════════════════════════════════════════" -ForegroundColor Green
Write-Host "✨ Sistema começará a fazer upload automaticamente! 🚀" -ForegroundColor Green
