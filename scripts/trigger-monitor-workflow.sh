#!/bin/bash
# Disparar workflow do monitor manualmente no GitHub

echo "[TRIGGER] Disparando workflow monitor-chat-responses.yml"
echo ""

# Tentar usar GitHub CLI se disponível
if command -v gh &> /dev/null; then
    echo "[GITHUB CLI] Encontrado, disparando workflow..."
    gh workflow run monitor-chat-responses.yml -r main
    echo "[OK] Workflow disparado!"
    echo ""
    echo "Acesse: https://github.com/fredmourao-ai/site-shopvivaliz/actions"
    echo "Procure por: Monitor Chat - Respostas Automticas"
else
    echo "[INFO] GitHub CLI nao encontrado"
    echo ""
    echo "Para disparar manualmente:"
    echo "1. Abra: https://github.com/fredmourao-ai/site-shopvivaliz/actions"
    echo "2. Procure por: 'Monitor Chat - Respostas Automticas'"
    echo "3. Clique em 'Run workflow'"
    echo "4. Selecione branch: main"
    echo "5. Clique em 'Run workflow'"
fi

echo ""
echo "[NEXT] Aguarde 1-2 minutos para o workflow executar"
echo "[THEN] Abra: https://dev.shopvivaliz.com.br/admin/monitor/"
echo "[TEST] Envie uma mensagem e veja a resposta real dos agentes!"
