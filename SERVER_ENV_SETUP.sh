#!/bin/bash
# Setup .env no servidor Oracle Cloud
# Execute via SSH: ssh -i <key> ubuntu@137.131.156.17

echo "📝 Criando .env no servidor..."

# Navegar para pasta do projeto
cd /home/ubuntu/site-shopvivaliz

# Criar arquivo .env
cat > .env << 'ENVFILE'
# Google Ads Campaign Configuration
# SERVER ENVIRONMENT - 2026-07-19
# VM Oracle Cloud: 137.131.156.17

# OAuth 2.0 Credentials
GOOGLE_OAUTH_CLIENT_ID=m71jvyuls7c4die88db14nv3bllmth0i.app.s.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=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P

# Google Ads API
GOOGLE_ADS_CUSTOMER_ID=5104079137
GOOGLE_ADS_DEVELOPER_TOKEN=[OBTER_DE_https://ads.google.com/aw/apicenter]

# Google Analytics
GOOGLE_ANALYTICS_ID=G-XXXXXXXXXX

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
echo "✅ .env criado com sucesso!"
echo "Conteúdo:"
cat .env

echo ""
echo "🚀 Próximo passo: python3 scripts/autonomous_campaign_system.py"
