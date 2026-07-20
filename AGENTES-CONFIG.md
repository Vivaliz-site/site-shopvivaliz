# Configuração de Agentes - ShopVivaliz

## Onde as Chaves Estão

### GitHub Secrets (Já Configuradas)
```
https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
```

Secrets que DEVEM existir:
- `GEMINI_API_KEY` (Google Gemini)
- `ANTHROPIC_API_KEY` (Claude)
- `OPENAI_API_KEY` (GPT-4o)
- `SQUAD_TOKEN` (Autenticação Squad)
- `TINY_ERP_API_KEY` (Olist/TinyERP)

## Endpoints que Usam as Chaves

### 1. Squad Chat (FUNCIONA)
```
URL: /admin/squad-chat.php
API: /api/agent/squad-chat.php
Usa: SQUAD_TOKEN, GEMINI_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY
Status: ✅ AGENTES RESPONDEM
```

### 2. Monitor v2 (NOVO)
```
URL: /admin/monitor-v2.html
API: /api/agent/squad-chat.php (mesma do Squad)
Usa: SQUAD_TOKEN
Status: ✅ Deve funcionar (usa Squad API)
```

### 3. Chat Responder (Workflows)
```
Script: scripts/chat-responder-real.py
Workflow: monitor-chat-responses.yml
Usa: GEMINI_API_KEY, ANTHROPIC_API_KEY, OPENAI_API_KEY
Status: 🟡 Testa APIs, mas responde "offline" se não tiver módulos
```

### 4. Task Executor (NOVO)
```
Script: scripts/task-queue-processor.py
Workflow: autonomous-task-execution.yml
Usa: Todas as chaves acima
Status: ✅ Pronto, delega para agentes
```

## Como Testar se Agentes Respondem

### Teste 1: Squad Chat
```bash
# Abrir no browser:
https://shopvivaliz.com.br/admin/squad-chat.php

# Se agentes respondem = chaves estão OK
# Se "offline" = problema nas chaves ou endpoint
```

### Teste 2: Monitor v2
```bash
# Abrir no browser:
https://shopvivaliz.com.br/admin/monitor-v2.html

# Enviar mensagem
# Se agentes respondem = tudo funciona
```

### Teste 3: Via API Diretamente
```bash
curl -X POST https://shopvivaliz.com.br/api/agent/squad-chat.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "SEU_SQUAD_TOKEN",
    "message": "Teste",
    "agents": ["claude"]
  }'
```

## Status Atual

| Sistema | Chaves | API | Status |
|---------|--------|-----|--------|
| Squad Chat | ✅ | ✅ | ✅ FUNCIONA |
| Monitor v2 | ✅ | ✅ | 🟡 Testa agora |
| Chat Responder | ✅ | 🟡 | 🟡 Precisa libs |
| Task Executor | ✅ | ✅ | ✅ Pronto |

## Problema Identificado

As chaves existem no GitHub Secrets, mas:
1. ❌ Chat responder precisa de módulos Python instalados nos workflows
2. ❌ Algumas libs podem não estar instaladas corretamente
3. ✅ Squad API funciona direto (não precisa libs extras)

## Solução Rápida

Usar **Monitor v2** ou **Squad Chat** que já funcionam.

Evitar chat-responder-v2.py que tenta instalar dependências dinamicamente.

## Próximos Passos

1. ✅ Testar Monitor v2
2. ✅ Verificar Squad Chat
3. ✅ Processar tarefas com Task Executor
4. ⏳ Melhorar Chat Responder (opcional)
