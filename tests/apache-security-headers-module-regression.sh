#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MASTER="$ROOT/.github/workflows/master-production-pipeline.yml"
DEPLOY="$ROOT/scripts/deploy-production.sh"
HTACCESS="$ROOT/.htaccess"

# CSP is intentionally configured in .htaccess. Production deploys must
# therefore enable mod_headers before Apache is config-tested/reloaded.
grep -Fq 'Content-Security-Policy' "$HTACCESS"
grep -F 'Header always set Content-Security-Policy ' "$HTACCESS" | grep -Fq 'https://www.mercadopago.com'
grep -F 'Header always set Content-Security-Policy ' "$HTACCESS" | grep -Fq 'https://fonts.googleapis.com'
grep -F 'Header always set Content-Security-Policy ' "$HTACCESS" | grep -Fq 'https://fonts.gstatic.com'
grep -Eq 'a2enmod[[:space:]]+rewrite[[:space:]]+headers' "$MASTER"
grep -Eq 'a2enmod[[:space:]]+rewrite[[:space:]]+headers' "$DEPLOY"

echo APACHE_SECURITY_HEADERS_MODULE_OK
