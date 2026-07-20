#!/bin/bash

echo "TESTANDO WEBHOOK DE CHAT"
echo ""

# Testar se endpoint responde
echo "1. Testando acesso ao webhook..."
curl -s -X OPTIONS https://shopvivaliz.com.br/api/monitor/chat-webhook.php -H "Access-Control-Allow-Origin: *"
echo ""

# Testar POST
echo "2. Enviando mensagem de teste..."
curl -s -X POST https://shopvivaliz.com.br/api/monitor/chat-webhook.php \
  -H "Content-Type: application/json" \
  -d '{"message":"Teste webhook"}' | jq .

echo ""
echo "3. Verificando se arquivo foi criado..."
if [ -f "logs/monitor-messages.log" ]; then
    echo "[OK] logs/monitor-messages.log existe"
    tail -1 logs/monitor-messages.log
else
    echo "[ERRO] logs/monitor-messages.log NAO foi criado"
    echo "Criando diretorio..."
    mkdir -p logs
fi

echo ""
echo "4. Testando criacao de chat..."
curl -s -X POST https://shopvivaliz.com.br/api/monitor/chat-history.php?action=create \
  -H "Content-Type: application/json" \
  -d '{"title":"Teste"}' | jq .

echo ""
echo "5. Listando chats..."
curl -s https://shopvivaliz.com.br/api/monitor/chat-history.php?action=list | jq .

