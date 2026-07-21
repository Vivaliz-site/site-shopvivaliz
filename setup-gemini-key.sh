#!/bin/bash
# Configure Gemini Key - Script executável na VM
# NÃO inclui a chave hardcoded - usa arquivo de configuração seguro
# Execução: GEMINI_API_KEY="xxx" bash setup-gemini-key.sh

if [ ! -f /home/ubuntu/site-shopvivaliz/.env ]; then
  echo "❌ Arquivo .env não encontrado"
  exit 1
fi

# Verificar se GEMINI_API_KEY está na variável de ambiente
if [ -z "$GEMINI_API_KEY" ]; then
  echo "❌ GEMINI_API_KEY não está configurada em variável de ambiente"
  exit 1
fi

# Adicionar a chave se não existir
if ! grep -q "^GEMINI_API_KEY=" /home/ubuntu/site-shopvivaliz/.env; then
  echo "" >> /home/ubuntu/site-shopvivaliz/.env
  echo "# === CREDENCIAIS IA ===" >> /home/ubuntu/site-shopvivaliz/.env
  echo "GEMINI_API_KEY=$GEMINI_API_KEY" >> /home/ubuntu/site-shopvivaliz/.env
  echo "✅ GEMINI_API_KEY adicionada"
else
  echo "ℹ️  GEMINI_API_KEY já existe"
fi
