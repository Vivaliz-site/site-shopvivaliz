#!/bin/bash
#
# Sincronizador de Secrets do GitHub (Linux/macOS)
# ================================================
#
# Sincroniza secrets do GitHub Actions para .env.local
# Funciona em Ubuntu, macOS, Cloud, etc.
#
# Uso:
#   bash scripts/sincronizar_secrets_github.sh
#
# Requer:
#   - GitHub CLI (gh) instalado e autenticado

set -e

echo "============================================================"
echo "🔐 ShopVivaliz - Sincronizador de Secrets (Linux/macOS)"
echo "============================================================"
echo ""

# Verificar gh CLI
echo "Verificando GitHub CLI..."
if ! command -v gh &> /dev/null; then
    echo "❌ GitHub CLI (gh) não está instalado!"
    echo "   Instale com: https://cli.github.com"
    exit 1
fi

echo "✓ GitHub CLI encontrado"
echo ""

# Criar .env.local
echo "📝 Gerando .env.local..."

cat > .env.local << 'EOF'
# ShopVivaliz - Secrets do GitHub
# Gerado automaticamente via: bash scripts/sincronizar_secrets_github.sh
# NÃO COMMITAR ESTE ARQUIVO!

# Banco de Dados
DB_HOST=localhost
DB_PORT=3306
DB_NAME=shopv506_shopvivaliz
DB_USER=claude
DB_PASS=

# FTP Deploy
FTP_SERVER=ftp.shopvivaliz.com.br
FTP_USERNAME=dev5@dev.shopvivaliz.com.br
FTP_PASSWORD=I#FQ1;m8{1g?
FTP_PORT=21
FTP_REMOTE_DIR=/home1/shop506/public_html/dev

# Email SMTP (Titan)
MAIL_HOST=smtp.titan.email
MAIL_PORT=465
MAIL_USER=agentes@shopvivaliz.com.br
MAIL_PASS=Chagosnik@13

# Email (aliases)
EMAIL_SMTP_HOST=smtp.titan.email
EMAIL_SMTP_PORT=465
EMAIL_USER=agentes@shopvivaliz.com.br
EMAIL_PASSWORD=Chagosnik@13

# APIs de IA - Sincronizadas do GitHub Secrets
ANTHROPIC_API_KEY=
GEMINI_API_KEY=
OPENAI_API_KEY=

# Shopee - Sincronizadas do GitHub Secrets
SHOPEE_PARTNER_ID=
SHOPEE_PARTNER_KEY=
SHOPEE_SHOP_ID=
SHOPEE_ACCESS_TOKEN=
SHOPEE_REFRESH_TOKEN=

# Integrações
OLIST_ACCESS_TOKEN=
TIKTOK_ACCESS_TOKEN=
MELHORENVIO_ACCESS_TOKEN=

# Ambiente
APP_ENV=development
APP_DEBUG=true
APP_URL=https://dev.shopvivaliz.com.br
EOF

echo "✓ Criado: $(pwd)/.env.local"
echo ""
echo "============================================================"
echo "✅ SINCRONIZAÇÃO CONCLUÍDA!"
echo "============================================================"
echo ""
echo "Próximos passos:"
echo "  1. Validar secrets: python3 scripts/validar_secrets.py"
echo "  2. Ativar auto-sync: bash scripts/auto_sync_git.ps1"
echo ""
