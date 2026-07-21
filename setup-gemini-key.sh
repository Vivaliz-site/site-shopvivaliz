#!/bin/bash
# Configure Gemini Key - Script execut?vel na VM
if [ ! -f /home/ubuntu/site-shopvivaliz/.env ]; then
  echo "? Arquivo .env n?o encontrado"
  exit 1
fi

GEMINI_KEY="***REMOVED***"

# Adicionar a chave se n?o existir
if ! grep -q "^GEMINI_API_KEY=" /home/ubuntu/site-shopvivaliz/.env; then
  echo "" >> /home/ubuntu/site-shopvivaliz/.env
  echo "# === CREDENCIAIS IA ===" >> /home/ubuntu/site-shopvivaliz/.env
  echo "GEMINI_API_KEY=$GEMINI_KEY" >> /home/ubuntu/site-shopvivaliz/.env
  echo "? GEMINI_API_KEY adicionada"
else
  echo "??  GEMINI_API_KEY j? existe"
fi
