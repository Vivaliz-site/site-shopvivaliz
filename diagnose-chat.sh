#!/bin/bash

echo "=================================================================="
echo "DIAGNOSTICO DO SISTEMA DE CHAT"
echo "=================================================================="
echo ""

echo "1. VERIFICANDO WORKFLOW..."
if [ -f ".github/workflows/monitor-chat-responses.yml" ]; then
    echo "   [OK] monitor-chat-responses.yml existe"
    grep "schedule:" .github/workflows/monitor-chat-responses.yml | head -1
else
    echo "   [ERRO] monitor-chat-responses.yml NAO EXISTE"
fi

echo ""
echo "2. VERIFICANDO SCRIPT DE RESPOSTA..."
if [ -f "scripts/chat-responder.py" ]; then
    echo "   [OK] chat-responder.py existe"
    python3 -m py_compile scripts/chat-responder.py 2>&1 && echo "   [OK] Sintaxe Python OK" || echo "   [ERRO] Erro de sintaxe"
else
    echo "   [ERRO] chat-responder.py NAO EXISTE"
fi

echo ""
echo "2. VERIFICANDO SCRIPT DE RESPOSTA REAL..."
if [ -f "scripts/chat-responder-real.py" ]; then
    echo "   [OK] chat-responder-real.py existe"
    python3 -m py_compile scripts/chat-responder-real.py 2>&1 && echo "   [OK] Sintaxe Python OK" || echo "   [ERRO] Erro de sintaxe"
else
    echo "   [ERRO] chat-responder-real.py NAO EXISTE"
fi

echo ""
echo "3. VERIFICANDO LOGS DO CHAT..."
echo "   Mensagens do monitor:"
if [ -f "logs/monitor-messages.log" ]; then
    count=$(wc -l < "logs/monitor-messages.log")
    echo "   [OK] Arquivo existe com $count linhas"
    echo "   Ultimas mensagens:"
    tail -3 logs/monitor-messages.log 2>/dev/null | sed 's/^/     /'
else
    echo "   [VAZIO] Nenhuma mensagem ainda"
fi

echo ""
echo "   Respostas dos agentes:"
if [ -f "logs/monitor-responses.jsonl" ]; then
    count=$(wc -l < "logs/monitor-responses.jsonl")
    echo "   [OK] Arquivo existe com $count linhas"
    echo "   Ultimas respostas:"
    tail -2 logs/monitor-responses.jsonl 2>/dev/null | sed 's/^/     /'
else
    echo "   [VAZIO] Nenhuma resposta ainda"
fi

echo ""
echo "4. POSSÍVEIS PROBLEMAS..."
echo "   - APIs (Gemini/Claude) nao estao configuradas"
echo "4. POSSÍVEIS PROBLEMAS (APÓS CONFIGURAÇÃO DE SECRETS)..."
echo "   - Variáveis de ambiente ANTHROPIC_API_KEY, GEMINI_API_KEY, OPENAI_API_KEY não estão acessíveis ao script."
echo "   - Workflow nao esta disparando a cada 2 minutos"
echo "   - Chat nao esta salvando mensagens em logs/monitor-messages.log"
echo "   - Script chat-responder.py tem erro"
echo "   - Script chat-responder-real.py tem erro"

echo ""
echo "5. SOLUCOES..."
echo "   a) Verificar se API keys estao em GitHub Secrets"
echo "   a) Verificar se ANTHROPIC_API_KEY, GEMINI_API_KEY, OPENAI_API_KEY estão configuradas em GitHub Secrets e acessíveis ao workflow."
echo "   b) Verificar se workflow rodou nos ultimos 2 minutos"
echo "   c) Testar script localmente:"
echo "      python3 scripts/chat-responder.py"
echo "   c) Testar script localmente (com variáveis de ambiente configuradas):"
echo "      ANTHROPIC_API_KEY=sk-... GEMINI_API_KEY=... OPENAI_API_KEY=... python3 scripts/chat-responder-real.py"
echo "   d) Enviar mensagem de teste no monitor e aguardar 2-3 minutos"
