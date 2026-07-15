#!/usr/bin/env bash
#
# ShopVivaliz - gerador seguro de .env.local
#
# Este script nao le secrets do GitHub, porque o GitHub CLI nao permite
# recuperar valores secretos. Ele cria um .env.local a partir do ambiente
# atual, mantendo campos sensiveis vazios quando nao estiverem definidos.

set -euo pipefail

echo "============================================================"
echo "ShopVivaliz - Gerador seguro de .env.local"
echo "============================================================"
echo ""

env_or_default() {
  local key="$1"
  local fallback="${2:-}"
  local value="${!key:-}"
  if [ -n "$value" ]; then
    printf '%s' "$value"
  else
    printf '%s' "$fallback"
  fi
}

MAIL_HOST_VALUE="$(env_or_default MAIL_HOST smtp.titan.email)"
MAIL_PORT_VALUE="$(env_or_default MAIL_PORT 465)"
MAIL_USER_VALUE="$(env_or_default MAIL_USER agentes@shopvivaliz.com.br)"
MAIL_PASS_VALUE="$(env_or_default MAIL_PASS)"

cat > .env.local <<EOF
# ShopVivaliz - ambiente local
# Gerado por: bash scripts/sincronizar_secrets_github.sh
# Nao commitar este arquivo.

# Banco de Dados
DB_HOST=$(env_or_default DB_HOST localhost)
DB_PORT=$(env_or_default DB_PORT 3306)
DB_NAME=$(env_or_default DB_NAME)
DB_USER=$(env_or_default DB_USER)
DB_PASS=$(env_or_default DB_PASS)

# FTP Deploy
FTP_SERVER=$(env_or_default FTP_SERVER)
FTP_USERNAME=$(env_or_default FTP_USERNAME)
FTP_PASSWORD=$(env_or_default FTP_PASSWORD)
FTP_PORT=$(env_or_default FTP_PORT 21)
FTP_REMOTE_DIR=$(env_or_default FTP_REMOTE_DIR)

# Email SMTP
MAIL_HOST=$MAIL_HOST_VALUE
MAIL_PORT=$MAIL_PORT_VALUE
MAIL_USER=$MAIL_USER_VALUE
MAIL_PASS=$MAIL_PASS_VALUE

# Email SMTP - aliases aceitos pelos workflows/scripts
SMTP_HOST=$(env_or_default SMTP_HOST "$MAIL_HOST_VALUE")
SMTP_PORT=$(env_or_default SMTP_PORT "$MAIL_PORT_VALUE")
SMTP_USER=$(env_or_default SMTP_USER "$MAIL_USER_VALUE")
SMTP_PASS=$(env_or_default SMTP_PASS "$MAIL_PASS_VALUE")
EMAIL_SMTP_HOST=$(env_or_default EMAIL_SMTP_HOST "$MAIL_HOST_VALUE")
EMAIL_SMTP_PORT=$(env_or_default EMAIL_SMTP_PORT "$MAIL_PORT_VALUE")
EMAIL_USER=$(env_or_default EMAIL_USER "$MAIL_USER_VALUE")
EMAIL_PASSWORD=$(env_or_default EMAIL_PASSWORD "$MAIL_PASS_VALUE")
EMAIL_FROM=$(env_or_default EMAIL_FROM "$MAIL_USER_VALUE")
EMAIL_TO=$(env_or_default EMAIL_TO "fredmourao@gmail.com,atendimento@shopvivaliz.com.br")

# APIs de IA
ANTHROPIC_API_KEY=$(env_or_default ANTHROPIC_API_KEY)
GEMINI_API_KEY=$(env_or_default GEMINI_API_KEY)
OPENAI_API_KEY=$(env_or_default OPENAI_API_KEY)

# Marketplaces e integracoes
SHOPEE_PARTNER_ID=$(env_or_default SHOPEE_PARTNER_ID)
SHOPEE_PARTNER_KEY=$(env_or_default SHOPEE_PARTNER_KEY)
SHOPEE_SHOP_ID=$(env_or_default SHOPEE_SHOP_ID)
SHOPEE_ACCESS_TOKEN=$(env_or_default SHOPEE_ACCESS_TOKEN)
SHOPEE_REFRESH_TOKEN=$(env_or_default SHOPEE_REFRESH_TOKEN)
OLIST_ACCESS_TOKEN=$(env_or_default OLIST_ACCESS_TOKEN)
TIKTOK_ACCESS_TOKEN=$(env_or_default TIKTOK_ACCESS_TOKEN)
MELHORENVIO_ACCESS_TOKEN=$(env_or_default MELHORENVIO_ACCESS_TOKEN)

# Ambiente
APP_ENV=$(env_or_default APP_ENV development)
APP_DEBUG=$(env_or_default APP_DEBUG true)
APP_URL=$(env_or_default APP_URL https://dev.shopvivaliz.com.br)
EOF

echo "Criado: $(pwd)/.env.local"
echo "OK: nenhum segredo real foi gravado no script."
