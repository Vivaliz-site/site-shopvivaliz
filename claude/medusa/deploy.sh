#!/usr/bin/env bash
#
# Deploy do backend + storefront Medusa (ShopVivaliz) para um host Node.js
# (VPS, Railway, Render, Fly.io etc). NAO roda no HostGator - hospedagem
# compartilhada nao suporta Node.js/Postgres persistente (ver
# DEPLOY-CHECKLIST.md e DEPLOY_CHECKLIST.md). O site PHP legado em /claude/
# continua sendo publicado no HostGator pelo workflow .github/workflows/deploy.yml.
#
# Uso: ./deploy.sh [backend|storefront|all]
#
# Pre-requisitos no host de destino: ver DEPLOY_CHECKLIST.md.

set -euo pipefail

TARGET="${1:-all}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

require_env() {
  local name="$1"
  if [ -z "${!name:-}" ]; then
    echo "ERRO: variavel de ambiente obrigatoria ausente: $name" >&2
    exit 1
  fi
}

deploy_backend() {
  echo "==> Deploy do backend Medusa"
  cd "$SCRIPT_DIR/apps/backend"

  require_env DATABASE_URL
  require_env JWT_SECRET
  require_env COOKIE_SECRET

  echo "-- Instalando dependencias (npm ci)"
  npm ci

  echo "-- Build"
  npm run build

  echo "-- Migrations"
  npx medusa db:migrate

  echo "-- Iniciando/reiniciando com PM2"
  if ! command -v pm2 >/dev/null 2>&1; then
    echo "ERRO: pm2 nao encontrado. Instale com: npm install -g pm2" >&2
    exit 1
  fi

  pm2 startOrReload ecosystem.config.js --only medusa-backend || \
    pm2 start "npx medusa start" --name medusa-backend
  pm2 save

  echo "==> Backend publicado. Health check:"
  sleep 5
  curl -fsS "http://localhost:9000/health" && echo " OK" || {
    echo "AVISO: health check falhou, verifique 'pm2 logs medusa-backend'" >&2
    exit 1
  }
}

deploy_storefront() {
  echo "==> Deploy do storefront Next.js"
  cd "$SCRIPT_DIR/apps/storefront"

  require_env NEXT_PUBLIC_MEDUSA_PUBLISHABLE_KEY
  require_env NEXT_PUBLIC_MEDUSA_BACKEND_URL

  echo "-- Instalando dependencias (npm ci)"
  npm ci

  echo "-- Build"
  npm run build

  echo "-- Iniciando/reiniciando com PM2"
  if ! command -v pm2 >/dev/null 2>&1; then
    echo "ERRO: pm2 nao encontrado. Instale com: npm install -g pm2" >&2
    exit 1
  fi

  pm2 startOrReload ecosystem.config.js --only medusa-storefront || \
    pm2 start "npm run start" --name medusa-storefront
  pm2 save

  echo "==> Storefront publicado."
}

case "$TARGET" in
  backend) deploy_backend ;;
  storefront) deploy_storefront ;;
  all)
    deploy_backend
    deploy_storefront
    ;;
  *)
    echo "Uso: $0 [backend|storefront|all]" >&2
    exit 1
    ;;
esac

echo "==> Deploy concluido ($TARGET)."
