#!/bin/bash
# Setup GitHub Secrets para ShopVivaliz Pipeline
# Uso: ./setup-github-secrets.sh

set -e

echo "🔐 ADICIONANDO SECRETS AO GITHUB..."
echo ""

# Credenciais de API
echo "📝 Adicionando credenciais de API..."
gh secret set ANTHROPIC_API_KEY --body "${ANTHROPIC_API_KEY:-sk-ant-}" 2>/dev/null || echo "⚠️  ANTHROPIC_API_KEY - não definida"
gh secret set OPENAI_API_KEY --body "${OPENAI_API_KEY:-}" 2>/dev/null || echo "⚠️  OPENAI_API_KEY - não definida"
gh secret set GEMINI_API_KEY --body "${GEMINI_API_KEY:-}" 2>/dev/null || echo "⚠️  GEMINI_API_KEY - não definida"
gh secret set GOOGLE_API_KEY --body "${GOOGLE_API_KEY:-}" 2>/dev/null || echo "⚠️  GOOGLE_API_KEY - não definida"

# Credenciais Olist
echo "📝 Adicionando credenciais Olist..."
gh secret set OLIST_CLIENT_ID --body "${OLIST_CLIENT_ID:-}" 2>/dev/null || echo "⚠️  OLIST_CLIENT_ID - não definida"
gh secret set OLIST_CLIENT_SECRET --body "${OLIST_CLIENT_SECRET:-}" 2>/dev/null || echo "⚠️  OLIST_CLIENT_SECRET - não definida"
gh secret set TOKEN_API_OLIST --body "${TOKEN_API_OLIST:-}" 2>/dev/null || echo "⚠️  TOKEN_API_OLIST - não definida"
gh secret set CLIENT_ID_API_OLIST --body "${CLIENT_ID_API_OLIST:-}" 2>/dev/null || echo "⚠️  CLIENT_ID_API_OLIST - não definida"
gh secret set CLIENT_SECRET_OLIST --body "${CLIENT_SECRET_OLIST:-}" 2>/dev/null || echo "⚠️  CLIENT_SECRET_OLIST - não definida"

# Credenciais FTP
echo "📝 Adicionando credenciais FTP..."
gh secret set FTP_SERVER --body "${FTP_SERVER:-}" 2>/dev/null || echo "⚠️  FTP_SERVER - não definida"
gh secret set FTP_USERNAME --body "${FTP_USERNAME:-}" 2>/dev/null || echo "⚠️  FTP_USERNAME - não definida"
gh secret set FTP_PASSWORD --body "${FTP_PASSWORD:-}" 2>/dev/null || echo "⚠️  FTP_PASSWORD - não definida"
gh secret set FTP_PORT --body "${FTP_PORT:-21}" 2>/dev/null || echo "⚠️  FTP_PORT - usando default 21"
gh secret set FTP_REMOTE_DIR --body "${FTP_REMOTE_DIR:-/public_html}" 2>/dev/null || echo "⚠️  FTP_REMOTE_DIR - usando default"

# Credenciais Email
echo "📝 Adicionando credenciais Email..."
gh secret set EMAIL_FROM --body "${EMAIL_FROM:-}" 2>/dev/null || echo "⚠️  EMAIL_FROM - não definida"
gh secret set EMAIL_TO --body "${EMAIL_TO:-}" 2>/dev/null || echo "⚠️  EMAIL_TO - não definida"
gh secret set EMAIL_SMTP_HOST --body "${EMAIL_SMTP_HOST:-}" 2>/dev/null || echo "⚠️  EMAIL_SMTP_HOST - não definida"
gh secret set EMAIL_SMTP_PORT --body "${EMAIL_SMTP_PORT:-587}" 2>/dev/null || echo "⚠️  EMAIL_SMTP_PORT - usando default 587"
gh secret set EMAIL_USER --body "${EMAIL_USER:-}" 2>/dev/null || echo "⚠️  EMAIL_USER - não definida"
gh secret set EMAIL_PASSWORD --body "${EMAIL_PASSWORD:-}" 2>/dev/null || echo "⚠️  EMAIL_PASSWORD - não definida"

# Credenciais Database
echo "📝 Adicionando credenciais Database..."
gh secret set DB_HOST --body "${DB_HOST:-localhost}" 2>/dev/null || echo "⚠️  DB_HOST - usando default"
gh secret set DB_NAME --body "${DB_NAME:-shopvivaliz}" 2>/dev/null || echo "⚠️  DB_NAME - usando default"
gh secret set DB_DATABASE --body "${DB_DATABASE:-}" 2>/dev/null || echo "⚠️  DB_DATABASE - não definida"

# Credenciais Marketplace
echo "📝 Adicionando credenciais Marketplace..."
gh secret set SHOPEE_PARTNER_ID --body "${SHOPEE_PARTNER_ID:-}" 2>/dev/null || echo "⚠️  SHOPEE_PARTNER_ID - não definida"
gh secret set SHOPEE_PARTNER_KEY --body "${SHOPEE_PARTNER_KEY:-}" 2>/dev/null || echo "⚠️  SHOPEE_PARTNER_KEY - não definida"
gh secret set TIKTOK_CLIENT_ID --body "${TIKTOK_CLIENT_ID:-}" 2>/dev/null || echo "⚠️  TIKTOK_CLIENT_ID - não definida"
gh secret set TIKTOK_CLIENT_SECRET --body "${TIKTOK_CLIENT_SECRET:-}" 2>/dev/null || echo "⚠️  TIKTOK_CLIENT_SECRET - não definida"

# Credenciais Tiny
echo "📝 Adicionando credenciais Tiny..."
gh secret set TINY_CLIENT_ID --body "${TINY_CLIENT_ID:-}" 2>/dev/null || echo "⚠️  TINY_CLIENT_ID - não definida"
gh secret set TINY_CLIENT_SECRET --body "${TINY_CLIENT_SECRET:-}" 2>/dev/null || echo "⚠️  TINY_CLIENT_SECRET - não definida"
gh secret set URL_TINY_OLIST --body "${URL_TINY_OLIST:-}" 2>/dev/null || echo "⚠️  URL_TINY_OLIST - não definida"

# Tokens e IDs Adicionais
echo "📝 Adicionando tokens adicionais..."
gh secret set GH_REPO_TOKEN --body "${GH_REPO_TOKEN:-}" 2>/dev/null || echo "⚠️  GH_REPO_TOKEN - não definida"
gh secret set SQUAD_TOKEN --body "${SQUAD_TOKEN:-}" 2>/dev/null || echo "⚠️  SQUAD_TOKEN - não definida"
gh secret set EMAIL_AGENTES_SECRET --body "${EMAIL_AGENTES_SECRET:-}" 2>/dev/null || echo "⚠️  EMAIL_AGENTES_SECRET - não definida"

echo ""
echo "✅ SECRETS ADICIONADOS AO GITHUB!"
echo ""
echo "📋 Para verificar os secrets configurados:"
echo "   gh secret list"
echo ""
echo "🔍 Para editar um secret:"
echo "   gh secret set NOME_SECRET"
