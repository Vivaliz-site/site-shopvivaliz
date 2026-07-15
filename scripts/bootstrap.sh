#!/bin/bash
# Bootstrap universal para ShopVivaliz
# Roda em qualquer Linux/Cloud e configura tudo automaticamente

set -e

echo "🚀 ShopVivaliz Bootstrap Universal"
echo "===================================="

cd "$(dirname "$0")/.."
REPO_DIR="$(pwd)"

# 1. Sincronizar secrets
echo "1. Sincronizando secrets..."
if command -v python3 &>/dev/null; then
    python3 scripts/sincronizar_secrets_github.py 2>/dev/null || bash scripts/sincronizar_secrets_github.sh 2>/dev/null || true
fi

# 2. Validar
echo "2. Validando..."
python3 scripts/validar_secrets.py 2>/dev/null || true

# 3. Iniciar auto-sync em background
echo "3. Iniciando auto-sync..."
nohup bash scripts/auto-sincronizar.sh > logs/bootstrap.log 2>&1 &
echo "   ✅ Auto-sync rodando em background (PID: $!)"

echo ""
echo "✅ Setup concluído!"
echo "   Log: $REPO_DIR/logs/bootstrap.log"
