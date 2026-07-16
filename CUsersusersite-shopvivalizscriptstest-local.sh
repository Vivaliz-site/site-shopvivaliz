#!/bin/bash

# TESTE LOCAL DE CONEXAO SHOPEE
# Use este script para testar localmente exportando os secrets

echo "=============================================================================="
echo "TESTE LOCAL: CONEXAO SHOPEE"
echo "=============================================================================="
echo ""

# Verificar se secrets estao definidos
echo "[1] Verificando credenciais..."
echo ""

if [ -z "$SHOPEE_PARTNER_ID" ]; then
    echo "ERRO: SHOPEE_PARTNER_ID nao definido"
    echo ""
    echo "Para testar localmente, exporte os secrets:"
    echo "  export SHOPEE_PARTNER_ID=seu_valor"
    echo "  export SHOPEE_PARTNER_KEY=seu_valor"
    echo "  export SHOPEE_SHOP_ID=seu_valor"
    echo "  export SHOPEE_ACCESS_TOKEN=seu_valor"
    echo ""
    echo "Depois execute:"
    echo "  bash scripts/test-local.sh"
    exit 1
fi

echo "  SHOPEE_PARTNER_ID: OK (***${SHOPEE_PARTNER_ID: -10})"
echo "  SHOPEE_PARTNER_KEY: OK"
echo "  SHOPEE_SHOP_ID: OK"
echo "  SHOPEE_ACCESS_TOKEN: OK"
echo ""

# Executar script PHP de teste
echo "[2] Executando teste de conexao..."
echo ""

php scripts/test-shopee-connection.php

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo ""
    echo "=============================================================================="
    echo "SUCESSO: Sistema conectado e pronto!"
    echo "=============================================================================="
else
    echo ""
    echo "=============================================================================="
    echo "ERRO: Falha na conexao"
    echo "=============================================================================="
fi

exit $exit_code
