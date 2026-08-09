#!/bin/bash
set -Eeuo pipefail

# Safe Google Ads environment bootstrap.
# This file never contains real credentials and refuses to overwrite an existing
# .env. Populate secrets through the approved secret store or edit the private
# server .env directly over an authenticated administration channel.

cd /home/ubuntu/site-shopvivaliz

if [ -e .env ]; then
  echo "ABORT: .env already exists; refusing to overwrite production secrets."
  echo "Update credentials through the approved secret store or secure admin channel."
  exit 2
fi

cat > .env << 'ENVFILE'
# Google Ads OAuth/API - placeholders only
GOOGLE_OAUTH_CLIENT_ID=<active_client_id_ending_in_.apps.googleusercontent.com>
GOOGLE_OAUTH_CLIENT_SECRET=<secret_for_the_same_active_client>
GOOGLE_ADS_CUSTOMER_ID=<production_customer_id_10_digits>
GOOGLE_ADS_LOGIN_CUSTOMER_ID=<mcc_customer_id_if_required>
GOOGLE_ADS_DEVELOPER_TOKEN=<developer_token_from_api_center>
GOOGLE_ADS_REFRESH_TOKEN=<refresh_token_issued_for_the_same_oauth_client>

# Conversion/Analytics
GOOGLE_ADS_CONVERSION_SOURCE=GA4_IMPORT
GOOGLE_ANALYTICS_ID=<ga4_measurement_id>
GA4_SECRET=<measurement_protocol_secret_if_used>
ENVFILE

chmod 600 .env

echo ".env template created with mode 600."
echo "Fill the private values securely, then run:"
echo "  python3 scripts/google_ads_auth_preflight.py"
echo "  python3 scripts/google_ads_real_readiness.py"
echo "Do not attempt any live Ads mutation until both checks pass."
