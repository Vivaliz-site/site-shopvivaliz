#!/bin/bash

# Script para configurar chaves de agente no servidor
# Executar: bash scripts/setup-agent-keys.sh

AGENT_KEY="${1:-RV5yJAphQHufjlfm12qaQKsrqld5fHRKeVB1lHFym-k}"
ENV_FILE=".env"

if [ ! -f "$ENV_FILE" ]; then
    echo "❌ Arquivo .env não encontrado!"
    exit 1
fi

# Verificar se já tem as chaves
if grep -q "SHOPVIVALIZ_AGENT_KEY" "$ENV_FILE"; then
    echo "✅ Chaves de agente já configuradas no .env"
else
    echo "" >> "$ENV_FILE"
    echo "SHOPVIVALIZ_AGENT_KEY=$AGENT_KEY" >> "$ENV_FILE"
    echo "RUNTIME_AGENT_KEY=$AGENT_KEY" >> "$ENV_FILE"
    echo "WATCHDOG_AGENT_KEY=$AGENT_KEY" >> "$ENV_FILE"
    echo "AUTONOMOUS_AGENT_KEY=$AGENT_KEY" >> "$ENV_FILE"
    echo "✅ Chaves de agente adicionadas ao .env"
fi
