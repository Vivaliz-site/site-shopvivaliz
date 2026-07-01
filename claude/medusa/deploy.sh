#!/bin/bash
# Deploy do backend + storefront MedusaJS (ShopVivaliz)
#
# O HostGator (hospedagem compartilhada, usada pelo site PHP em /claude e
# /admin) não roda Node.js/Postgres, então este script assume um host
# separado para o Medusa: um VPS com Docker/PM2, ou serviços gerenciados
# (Railway/Render/Fly.io para o backend, Vercel/Netlify para o storefront).
#
# Uso: ./deploy.sh [backend|storefront|all]
#
# Variáveis de ambiente esperadas (já configuradas no host de produção,
# não neste script):
#   DATABASE_URL, REDIS_URL, JWT_SECRET, COOKIE_SECRET, STORE_CORS,
#   ADMIN_CORS, AUTH_CORS, STRIPE_API_KEY, STRIPE_WEBHOOK_SECRET,
#   EHA_WEBHOOK_URL, EHA_WEBHOOK_SECRET

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$SCRIPT_DIR/apps/backend"
STOREFRONT_DIR="$SCRIPT_DIR/apps/storefront"
TARGET="${1:-all}"

deploy_backend() {
  echo "==> Deploy backend Medusa"
  cd "$BACKEND_DIR"

  if [ -z "${DATABASE_URL:-}" ]; then
    echo "ERRO: DATABASE_URL não definida no ambiente. Abortando." >&2
    exit 1
  fi

  npm ci
  npm run build
  npx medusa db:migrate

  echo "==> Backend buildado. Inicie com: npx medusa start (ou via PM2/Docker)"
}

deploy_storefront() {
  echo "==> Deploy storefront Next.js"
  cd "$STOREFRONT_DIR"

  if [ -z "${NEXT_PUBLIC_MEDUSA_BACKEND_URL:-}" ]; then
    echo "ERRO: NEXT_PUBLIC_MEDUSA_BACKEND_URL não definida. Abortando." >&2
    exit 1
  fi

  npm ci
  npm run build

  echo "==> Storefront buildado. Inicie com: npm run start (ou deploy na Vercel/Netlify)"
}

case "$TARGET" in
  backend) deploy_backend ;;
  storefront) deploy_storefront ;;
  all) deploy_backend; deploy_storefront ;;
  *)
    echo "Uso: ./deploy.sh [backend|storefront|all]" >&2
    exit 1
    ;;
esac

echo "==> Deploy concluído. Ver claude/medusa/DEPLOY-CHECKLIST.md para os passos pós-deploy."
