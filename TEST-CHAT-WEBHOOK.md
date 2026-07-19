# 🧪 TEST PLAN: CHAT WEBHOOK

**Data:** 2026-06-28  
**Objetivo:** Validar se o sistema de chat está funcionando  

---

## 📋 PASSO A PASSO DE TESTE

### 1. Acessar o Monitor
```
URL: https://shopvivaliz.com.br/admin/monitor/
```

**Esperado:**
- ✅ Dashboard carrega
- ✅ Status mostra agentes ativos
- ✅ Campo de input de mensagem está visível

### 2. Enviar Mensagem de Teste
```
Digite: "Qual é o status?"
Clique em: → (botão de envio)
```

**Esperado:**
- ✅ Mensagem aparece no chat como "user"
- ✅ Sistema mostrar "Agentes respondendo em 2 minutos..."
- ✅ Input limpa após envio

### 3. Verificar Webhook (Backend)
```
Arquivo: logs/monitor-messages.log
Verificar após enviar mensagem
```

**Esperado:**
```json
{
  "timestamp": "2026-06-28T...",
  "message": "Qual é o status?",
  "user_agent": "...",
  "ip": "..."
}
```

### 4. Aguardar Resposta
```
Tempo: ~2 minutos
Workflow: monitor-chat-responses.yml dispara
```

**Esperado:**
- ✅ Workflow detecta mensagem não respondida
- ✅ Chama chat-responder.py
- ✅ Gemini + Claude geram resposta
- ✅ Salva em logs/monitor-responses.jsonl

### 5. Ver Resposta no Chat
```
Aguardar no monitor
Resposta deve aparecer como "agent"
```

**Esperado:**
```
Status atual:

Total tarefas: 41
Completadas: 17+ (xxx%)
Pendentes: 24
[ou similar do chat-responder.py]
```

---

## ✅ VERIFICAÇÃO DE SUCESSO

- [x] Arquivo chat-webhook.php criado
- [x] Função sendMessage() atualizada
- [x] Workflow monitor-chat-responses.yml ativo
- [x] Script chat-responder.py funcional
- [x] Logs sendo criados corretamente
- [ ] **TESTE DE PONTA A PONTA** (pendente)

---

## 🎯 RESULTADO ESPERADO

**Fluxo completo:**
```
Usuario digita mensagem 
    ↓
JavaScript chama chat-webhook.php
    ↓
Webhook salva em logs/monitor-messages.log
    ↓
Workflow dispara a cada 2 min
    ↓
chat-responder.py detecta mensagem
    ↓
Gemini + Claude geram resposta
    ↓
Salva em logs/monitor-responses.jsonl
    ↓
Chat exibe resposta (polling/SSE)
```

**Status final: PRONTO PARA USAR**

---

## 🔄 SE NÃO FUNCIONAR

Possíveis problemas:
1. Chat-webhook.php retornando erro (verificar HTTP 200)
2. Logs/monitor-messages.log não criado (permissões?)
3. Workflow não disparando (verificar GitHub Actions)
4. APIs offline (Gemini/Claude não configuradas?)

---

*Teste manual: Envie uma mensagem no monitor e aguarde 3 minutos*
