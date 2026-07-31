#!/bin/bash
set -Eeuo pipefail
# Setup .env no servidor Oracle Cloud
# Execute via SSH: ssh -i <key> ubuntu@137.131.156.17

echo "Criando .env no servidor..."

# Navegar para pasta do projeto
cd /home/ubuntu/site-shopvivaliz

# Criar arquivo .env
cat > .env << 'ENVFILE'
# Google Ads Campaign Configuration
# SERVER ENVIRONMENT - 2026-07-19
# VM Oracle Cloud: 137.131.156.17

# OAuth 2.0 Credentials
GOOGLE_OAUTH_CLIENT_ID=m71jvyuls7c4die88db14nv3bllmth0i.app.s.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=<novo_secret_rotacionado>

# Google Ads API
GOOGLE_ADS_CUSTOMER_ID=5104079137
GOOGLE_ADS_DEVELOPER_TOKEN=<obter_de_ads_google_com_aw_apicenter>
GOOGLE_ADS_REFRESH_TOKEN=<gerar_via_oauth_google_ads>
GOOGLE_ADS_ID=<conversion_id_ou_AW-id>
GOOGLE_ADS_CONVERSION_LABEL=<conversion_label>

# Google Analytics
GOOGLE_ANALYTICS_ID=<measurement_id_ga4>
GA4_SECRET=<measurement_protocol_secret>

# Campaign Configuration
CAMPAIGN_NAME=Rodizios-Search-AGRESSIVO-10xROI-2026-07
CAMPAIGN_BUDGET_DAILY=15.00
CAMPAIGN_DURATION_DAYS=30
CAMPAIGN_ROI_TARGET=10

# Application Settings
NODE_ENV=production
DEBUG=false
ENVFILE

# Definir permissões
chmod 600 .env

# Verificar
echo ".env criado com permissao 600."
echo "Validando placeholders antes de qualquer campanha..."
if python3 scripts/google_ads_real_readiness.py; then
  echo "COMPROVADO: readiness Google Ads aprovado para criacao pausada."
else
  echo "FALHOU: readiness Google Ads nao aprovado. Preencha credenciais reais no .env privado."
  exit 1
fi

echo ""
echo "Proximo passo permitido: revisar criacao pausada da campanha."
