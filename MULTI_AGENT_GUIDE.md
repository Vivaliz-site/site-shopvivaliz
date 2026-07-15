# 🤖 Multi-Agent Guide - Integração com Qualquer IA

**ShopVivaliz agora suporta múltiplos agentes IA de qualquer provedor!**

---

## 🎯 Suportados

- ✅ **Claude** (Anthropic)
- ✅ **Gemini** (Google)
- ✅ **GPT** (OpenAI)
- ✅ **LLaMA** (Meta)
- ✅ **Qualquer Custom Agent**

---

## 🏗️ Arquitetura Multi-Agente

```
┌─────────────────────────────────────────────────┐
│         Agent API (REST + Webhooks)             │
│         :5000 (central hub)                     │
├─────────────────────────────────────────────────┤
│                                                  │
│  ┌──────────────┐  ┌──────────────┐             │
│  │ Claude       │  │ Gemini       │             │
│  │ (Anthropic)  │  │ (Google)     │             │
│  └──────────────┘  └──────────────┘             │
│         │                 │                      │
│  ┌──────────────┐  ┌──────────────┐             │
│  │ GPT          │  │ Custom Agent │             │
│  │ (OpenAI)     │  │ (Your API)   │             │
│  └──────────────┘  └──────────────┘             │
│                                                  │
│  Roteador de Mensagens (Message Queue)          │
│  ├─ Registry de Agentes                         │
│  ├─ Fila de Mensagens Async                     │
│  └─ Orquestração de Tarefas                     │
│                                                  │
│  Recursos Compartilhados                        │
│  ├─ GitHub Issues                               │
│  ├─ MCP Protocol                                │
│  ├─ Database Centralizado                       │
│  └─ Logs Aggregados                             │
│                                                  │
└─────────────────────────────────────────────────┘
```

---

## 🚀 Integração Rápida

### 1. Claude (Anthropic)

```python
# Registrar Claude como agente
import requests

response = requests.post("http://localhost:5000/agents/register", json={
    "name": "Claude-Worker-1",
    "type": "claude",
    "webhook_url": "http://seu-webhook-claude:8000/events",
    "capabilities": ["sync", "execute", "monitor", "analyze"]
})

agent_id = response.json()["agent"]["agent_id"]
```

### 2. Gemini (Google)

```python
response = requests.post("http://localhost:5000/agents/register", json={
    "name": "Gemini-Worker-1",
    "type": "gemini",
    "webhook_url": "http://seu-webhook-gemini:8000/events",
    "capabilities": ["analyze", "generate", "summarize"]
})
```

### 3. GPT (OpenAI)

```python
response = requests.post("http://localhost:5000/agents/register", json={
    "name": "GPT-Worker-1",
    "type": "gpt",
    "webhook_url": "http://seu-webhook-gpt:8000/events",
    "capabilities": ["reason", "optimize", "debug"]
})
```

### 4. Custom Agent

```python
response = requests.post("http://localhost:5000/agents/register", json={
    "name": "MyCustomAgent",
    "type": "custom",
    "webhook_url": "http://meu-server:3000/handle-event",
    "capabilities": ["my-action-1", "my-action-2"]
})
```

---

## 📨 Enviar Mensagens Entre Agentes

### Claude Enviando para Gemini

```python
requests.post("http://localhost:5000/messages/send", json={
    "from_agent": "claude-1",
    "to_agent": "gemini-1",
    "type": "task",
    "data": {
        "task_id": "SYNC-001",
        "action": "synchronize_data",
        "params": {"source": "shopee", "target": "inventory"}
    },
    "priority": "high"
})
```

### Broadcast para Todos

```python
requests.post("http://localhost:5000/events/broadcast", json={
    "event": {
        "type": "sync_required",
        "data": {"timestamp": "2026-07-13T20:00:00Z"}
    },
    "agent_type": "claude"  # Broadcast só para Claude agentes
})
```

---

## 📮 Receber Mensagens (Webhook)

Cada agente deve implementar um webhook:

```python
from flask import Flask, request, jsonify

app = Flask(__name__)

@app.route("/events", methods=["POST"])
def handle_event():
    event = request.json
    
    message_id = event["message_id"]
    task = event["data"]
    
    # Executar tarefa
    result = execute_task(task)
    
    # Confirmar processamento
    requests.post(
        f"http://localhost:5000/messages/{message_id}/ack",
        json={"result": result}
    )
    
    return jsonify({"success": True})

app.run(port=8000)
```

---

## 🔄 Ciclo Completo de Colaboração

```
Cenário: Claude + Gemini + GPT resolvem problema juntos

1. Claude identifica problema
   ↓
   POST /messages/send → Gemini
   "Que alternativas existem?"

2. Gemini recebe via webhook
   ↓
   Analisa e retorna 3 opções

3. Claude processa resposta
   ↓
   POST /messages/send → GPT
   "Qual é a mais otimizada?"

4. GPT retorna análise

5. Claude sintetiza conclusão
   ↓
   POST /events/broadcast
   "Vamos com opção #2"

6. Todos os agentes recebem
   ↓
   Executam em paralelo
   
7. Resultado agregado
   ↓
   Salvo em database central
```

---

## 🏆 Recursos Compartilhados

Todos agentes acessam:

### GitHub Issues
```python
# Qualquer agente pode ler/comentar issues
import requests

# Ler issue
issue = requests.get(
    "https://api.github.com/repos/Vivaliz-site/site-shopvivaliz/issues/280",
    headers={"Authorization": f"token {GITHUB_TOKEN}"}
)

# Comentar resultado
requests.post(
    issue["comments_url"],
    json={"body": "✅ Tarefa concluída por GPT-Agent-1"},
    headers={"Authorization": f"token {GITHUB_TOKEN}"}
)
```

### MCP Protocol
```python
# Qualquer agente pode acessar resources via MCP
from scripts.mcp_client import MCPClient

mcp = MCPClient("http://localhost:5555")

# Ler status
status = mcp.read_resource("status://system")

# Executar comando
result = mcp.execute_tool("execute_git_command", {"command": "pull origin main"})
```

### Database Centralizado
```python
# Todos agentes veem histórico
from scripts.shopvivaliz_db import ShopVivalizDB

db = ShopVivalizDB()

# Ver syncs de todos agentes
syncs = db.get_syncs()

# Registrar nova execução
db.record_task("TASK-001", "Sync", "done", "high", "claude-1")
```

---

## ⚙️ Configuração Avançada

### Agent Registry (`agent_registry.json`)

```json
{
  "agents": [
    {
      "agent_id": "claude-1",
      "name": "Claude-Worker-1",
      "type": "claude",
      "webhook_url": "http://webhook-claude:8000/events",
      "capabilities": ["sync", "execute", "monitor"],
      "status": "active",
      "registered_at": "2026-07-13T20:00:00Z"
    },
    {
      "agent_id": "gemini-1",
      "name": "Gemini-Analyzer",
      "type": "gemini",
      "webhook_url": "http://webhook-gemini:8000/events",
      "capabilities": ["analyze", "optimize"],
      "status": "active"
    }
  ]
}
```

### Message Queue (`message-queue.json`)

```json
{
  "messages": [
    {
      "message_id": "msg-001",
      "from_agent": "claude-1",
      "to_agent": "gemini-1",
      "type": "task",
      "status": "pending",
      "priority": "high",
      "created_at": "2026-07-13T20:00:00Z"
    }
  ]
}
```

---

## 🚀 Iniciar Sistema Multi-Agente

### 1. Start Agent API (Hub Central)
```bash
python scripts/shopvivaliz_agent_api.py server 5000
```

### 2. Registrar Agentes
```bash
# Claude
python scripts/shopvivaliz_agent_api.py register claude http://webhook-claude:8000

# Gemini
python scripts/shopvivaliz_agent_api.py register gemini http://webhook-gemini:8000

# GPT
python scripts/shopvivaliz_agent_api.py register gpt http://webhook-gpt:8000
```

### 3. Cada Agente Implementa Seu Webhook
```python
# Seu webhook deve receber POST em /events
# e confirmar com PUT /messages/{id}/ack
```

### 4. Monitorar
```bash
# Ver agentes conectados
curl http://localhost:5000/agents

# Ver inbox de um agente
curl "http://localhost:5000/messages/inbox?agent_id=claude-1"

# Health check
curl http://localhost:5000/health
```

---

## 📊 Exemplo Real: Sync Multi-Agente

```bash
# 1. Claude cria tarefa
curl -X POST http://localhost:5000/messages/send \
  -H "Content-Type: application/json" \
  -d '{
    "from_agent": "claude-1",
    "to_agent": "gemini-1",
    "type": "task",
    "data": {
      "action": "analyze_inventory",
      "source": "shopee"
    },
    "priority": "high"
  }'

# 2. Gemini recebe via webhook → analisa

# 3. Gemini confirma resultado
curl -X POST http://localhost:5000/messages/msg-001/ack \
  -H "Content-Type: application/json" \
  -d '{"result": {"analysis": "...", "recommendation": "..."}}'

# 4. Claude processa → próximo agente

# 5. Resultado final em database
```

---

## 🎯 Benefícios

| Antes | Depois |
|-------|--------|
| 1 Agente (Claude) | N Agentes (Any IA) |
| Comunicação Síncrona | Async + Síncrona |
| Sem Orquestração | Message Queue |
| Sem Registry | Agent Registry |
| Resultado em Issues | Database + GitHub |
| Monitoramento Manual | Dashboards + Alertas |

---

## 📖 API Reference

### REST Endpoints

| Método | Path | Descrição |
|--------|------|-----------|
| POST | `/agents/register` | Registrar novo agente |
| GET | `/agents` | Listar agentes |
| POST | `/messages/send` | Enviar mensagem |
| GET | `/messages/inbox?agent_id=X` | Obter inbox |
| POST | `/messages/{id}/ack` | Confirmar mensagem |
| POST | `/events/broadcast` | Broadcast para todos |
| GET | `/health` | Health check |

---

## 🔐 Segurança

- Tokens de API em `.env`
- Webhooks validam origem
- Rate limiting por agente
- Audit log em database
- Encryption de mensagens (optional)

---

**🎉 Sistema pronto para múltiplos agentes de qualquer IA!**

---

**Últimaatualização:** 2026-07-13  
**Versão:** 2.0.0
