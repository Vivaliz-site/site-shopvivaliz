# 💬 CHAT COM HISTÓRICO - GUIA COMPLETO

**Versão:** 9.2.85  
**Data:** 2026-06-28  
**Status:** ✅ PRONTO PARA USAR

---

## 🎯 O QUE FOI IMPLEMENTADO

Um sistema completo de chat com histórico persistente que permite:

1. **Criar novas conversas** com um clique
2. **Continuar conversas anteriores** carregando histórico
3. **Armazenar mensagens** de forma permanente
4. **Conversar com 3 agentes IA** (Gemini, Claude, ChatGPT)
5. **Resposta automática** em 2-3 minutos

---

## 📱 INTERFACE DO MONITOR

```
┌─────────────────────────────────────────────────────┐
│         Trio IA Monitor - ShopVivaliz (Header)       │
└─────────────────────────────────────────────────────┘

┌──────────────┐ ┌────────────────┐ ┌──────────────────┐
│   SIDEBAR    │ │   MAIN AREA    │ │   CHAT AREA      │
│              │ │                │ │                  │
│ Historico    │ │ Total: 41      │ │ Conversando      │
│ de Chats     │ │ Completas: 17  │ │ com Agentes      │
│              │ │ Pendentes: 24  │ │                  │
│ + Nova Conv. │ │ Progresso: 41% │ │ [Mensagens]      │
│              │ │                │ │                  │
│ Chat Atual   │ │                │ │ [Input]    [Btn] │
│ Chat-001     │ │                │ │                  │
│ Chat-002     │ │                │ │                  │
│ Chat-003     │ │                │ │                  │
└──────────────┘ └────────────────┘ └──────────────────┘
```

---

## 🚀 COMO USAR

### 1️⃣ ACESSAR O MONITOR

```
https://dev.shopvivaliz.com.br/admin/monitor/
```

### 2️⃣ CRIAR UMA NOVA CONVERSA

**Opção A: Clique no botão "+ Nova Conversa"**
```
1. Clique no botão "+ Nova Conversa" na sidebar
2. Digite um nome para a conversa (ex: "Dúvidas sobre layout")
3. Clique em "OK"
4. Chat pronto para usar!
```

**Opção B: Enviar mensagem sem chat ativo**
```
1. Na conversa atual, digite uma mensagem
2. Clique em "Enviar"
3. Sistema cria chat automaticamente com timestamp
4. Mensagem salva no histórico
```

### 3️⃣ ENVIAR MENSAGENS

```
1. Digite sua mensagem no input
2. Pressione ENTER ou clique em "Enviar"
3. Mensagem aparece à direita (mensagem do usuario)
4. Sistema mostra: "Agentes respondendo em 2 minutos..."
5. Agentes processam a mensagem
6. Resposta aparece à esquerda (mensagem dos agentes)
7. Mensagem é salva no histórico automaticamente
```

### 4️⃣ ACESSAR CONVERSA ANTERIOR

```
1. Na sidebar "Histórico de Chats"
2. Clique em qualquer chat anterior
3. Todas as mensagens anteriores carregam
4. Você pode continuar a conversa
5. Novas mensagens são adicionadas ao histórico
```

### 5️⃣ VER HISTÓRICO

```
Sidebar mostra:
- Título da conversa
- Número de mensagens
- Data/hora da última mensagem

Clique para carregar e continuar!
```

---

## 🔄 FLUXO COMPLETO

```
[USUARIO] Envia mensagem "Qual é o status?"
    ↓
[JAVASCRIPT] Valida e exibe na tela
    ↓
[CHAT-WEBHOOK] Registra em logs/monitor-messages.log
    ↓
[CHAT-HISTORY] Salva em logs/chats/chat_XXX.json
    ↓
[WORKFLOW] monitor-chat-responses.yml dispara (a cada 2 min)
    ↓
[PYTHON] chat-responder.py detecta mensagem
    ↓
[GEMINI+CLAUDE] Geram resposta
    ↓
[JSON] Salva em logs/monitor-responses.jsonl
    ↓
[CHAT-HISTORY] Adiciona resposta ao chat
    ↓
[USUARIO] Vê resposta na tela em ~2-3 minutos
```

---

## 📁 ESTRUTURA DE ARMAZENAMENTO

```
logs/
├── chats/
│   ├── chat_1719559200_a1b2c3d4.json
│   ├── chat_1719559800_b2c3d4e5.json
│   └── chat_1719560400_c3d4e5f6.json
├── monitor-messages.log
├── monitor-responses.jsonl
└── execution/
    ├── task-001.log
    └── task-002.log
```

### Formato de um Chat (JSON)

```json
{
  "id": "chat_1719559200_a1b2c3d4",
  "title": "Dúvidas sobre layout",
  "messages": [
    {
      "id": "msg_1719559210_x1y2z3a4",
      "role": "user",
      "content": "Qual é o status?",
      "timestamp": "2026-06-28T10:15:30Z"
    },
    {
      "id": "msg_1719559350_y2z3a4b5",
      "role": "agent",
      "content": "Status atual: 17/41 tarefas completadas...",
      "timestamp": "2026-06-28T10:18:45Z"
    }
  ],
  "created_at": "2026-06-28T10:15:20Z",
  "updated_at": "2026-06-28T10:18:45Z"
}
```

---

## 🔧 ENDPOINTS DA API

### 1. Listar Chats
```bash
GET /api/monitor/chat-history.php?action=list

Response:
{
  "success": true,
  "chats": [
    {
      "id": "chat_1719559200_a1b2c3d4",
      "title": "Dúvidas sobre layout",
      "messages_count": 5,
      "created_at": "2026-06-28T10:15:20Z",
      "updated_at": "2026-06-28T10:18:45Z",
      "first_message": "Qual é o status?"
    }
  ],
  "total": 3
}
```

### 2. Criar Chat
```bash
POST /api/monitor/chat-history.php?action=create

Body:
{
  "title": "Nova conversa"
}

Response:
{
  "success": true,
  "chat_id": "chat_1719559200_a1b2c3d4",
  "message": "Chat criado"
}
```

### 3. Carregar Chat
```bash
GET /api/monitor/chat-history.php?action=load&chat_id=chat_1719559200_a1b2c3d4

Response:
{
  "success": true,
  "chat": {
    "id": "chat_1719559200_a1b2c3d4",
    "title": "Dúvidas sobre layout",
    "messages": [ ... ],
    "created_at": "2026-06-28T10:15:20Z",
    "updated_at": "2026-06-28T10:18:45Z"
  }
}
```

### 4. Adicionar Mensagem
```bash
POST /api/monitor/chat-history.php?action=add-message

Body:
{
  "chat_id": "chat_1719559200_a1b2c3d4",
  "message": "Qual é o status?",
  "role": "user"
}

Response:
{
  "success": true,
  "message": {
    "id": "msg_1719559210_x1y2z3a4",
    "role": "user",
    "content": "Qual é o status?",
    "timestamp": "2026-06-28T10:15:30Z"
  }
}
```

---

## 📊 DADOS PERSISTIDOS

### Chat Webhook (`/api/monitor/chat-webhook.php`)
- Registra TODA mensagem enviada
- Salva em: `logs/monitor-messages.log`
- Formato: 1 JSON por linha (JSONL)

### Chat History (`/api/monitor/chat-history.php`)
- Salva conversa completa
- Salva em: `logs/chats/chat_*.json`
- 1 arquivo por conversa

### Monitor Messages Log
```jsonl
{"timestamp":"2026-06-28T10:15:30Z","message":"Qual é o status?","user_agent":"...","ip":"..."}
{"timestamp":"2026-06-28T10:16:45Z","message":"Como adiciono produtos?","user_agent":"...","ip":"..."}
```

---

## ⚙️ CONFIGURAÇÃO

### Diretórios Criados Automaticamente
```
logs/
logs/chats/          ← Histórico de conversas
logs/monitor-messages.log ← Todas as mensagens
logs/execution/      ← Logs de execução das tarefas
```

### Permissões Necessárias
```
logs/chats/          755
logs/monitor-messages.log  644
```

---

## 🎯 EXEMPLOS DE USO

### Exemplo 1: Primeira Conversa
```
1. Acessa https://dev.shopvivaliz.com.br/admin/monitor/
2. Digita: "Como importar produtos?"
3. Clica: Enviar
4. Sistema cria chat automaticamente
5. Chat salvo como: chat_1719559200_abc123.json
6. Agentes respondem em ~2 min
7. Resposta salva no histórico
```

### Exemplo 2: Continuar Conversa
```
1. Acessa monitor novamente (3 horas depois)
2. Sidebar mostra: "Como importar produtos?" (5 mensagens)
3. Clica no chat
4. Histórico completo carrega
5. Digita: "E se tiver imagens grandes?"
6. Mensagem adicionada ao histórico existente
7. Agentes veem contexto anterior
```

### Exemplo 3: Múltiplas Conversas
```
Chat-A: "Dúvidas de layout"     (3 mensagens)
Chat-B: "Integracao com Olist"  (8 mensagens)
Chat-C: "Performance"           (5 mensagens)

Usuário pode:
- Alternar entre chats
- Cada um tem seu contexto
- Agentes entendem histórico de cada conversa
```

---

## 🔍 TROUBLESHOOTING

### Mensagem não aparece na sidebar
```
1. Verificar se logs/chats/ foi criado
2. Verificar permissões: chmod 755 logs/chats/
3. Recarregar a página
```

### Chat vazio após carregar
```
1. Verificar se chat_*.json foi criado
2. Ver conteúdo: cat logs/chats/chat_*.json
3. Se vazio: chat não salvou mensagens
```

### Agentes não respondem
```
1. Aguardar 2-3 minutos (workflow dispara)
2. Verificar: logs/monitor-messages.log
3. Verificar: logs/monitor-responses.jsonl
4. Verificar: GitHub Actions rodou monitor-chat-responses.yml
```

### Sidebar não atualiza
```
1. Clicar em "Nova Conversa"
2. Recarregar página: F5
3. Abrir console: F12 > Console
4. Verificar erros
```

---

## 📈 MELHORIAS FUTURAS

1. **Exportar conversa** para PDF/Excel
2. **Buscar em histórico** por palavra-chave
3. **Marcar favoritos** para conversas importantes
4. **Compartilhar conversa** com outro usuário
5. **Modo escuro** automático
6. **Notificações** quando agentes respondem
7. **Anexos de arquivo** nas mensagens
8. **Reações** às mensagens
9. **Threads** para responder específico
10. **Analytics** do chat

---

## 📞 SUPORTE

Se encontrar problemas:

1. Verificar [DEPLOY-TROUBLESHOOTING.md](DEPLOY-TROUBLESHOOTING.md)
2. Verificar logs em `logs/` 
3. Testar endpoints manualmente com curl
4. Verificar GitHub Actions status

---

## ✅ CHECKLIST DE VALIDAÇÃO

- [x] Chat-history.php criado
- [x] Chat-webhook.php criado
- [x] Monitor HTML atualizado
- [x] Sidebar implementada
- [x] API endpoints funcionais
- [x] Histórico persistente
- [x] Agentes respondendo
- [x] Documentação completa

**Status: PRONTO PARA PRODUÇÃO** ✅

---

*Desenvolvido por Trio IA - Gemini, Claude, ChatGPT*  
*ShopVivaliz © 2026*
