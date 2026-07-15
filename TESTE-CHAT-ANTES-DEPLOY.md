# 🧪 TESTE DO CHAT - ANTES DE FAZER DEPLOY

**Objetivo:** Validar se o histórico de chat funciona completamente

**Tempo estimado:** 5-10 minutos

---

## 📋 CHECKLIST DE TESTES

### 1️⃣ PREPARAÇÃO

- [ ] Arquivo `logs/chats/` criado
- [ ] Arquivo `logs/monitor-messages.log` criado
- [ ] Arquivo `api/monitor/chat-webhook.php` criado
- [ ] Arquivo `api/monitor/chat-history.php` criado
- [ ] Monitor atualizado com nova interface

**Verificar:**
```bash
ls -la logs/chats/
ls -la api/monitor/chat-*.php
ls -la admin/monitor/index.html
```

---

### 2️⃣ TESTAR NO NAVEGADOR

**URL:** https://dev.shopvivaliz.com.br/admin/monitor/

**Verificar:**
- [ ] Dashboard carrega sem erros
- [ ] Sidebar aparece à esquerda
- [ ] Botão "+ Nova Conversa" visível
- [ ] Área de chat à direita
- [ ] Input e botão "Enviar" funcionam

---

### 3️⃣ TESTAR ENVIO DE MENSAGEM

**Ação 1: Enviar mensagem**
```
1. Clique no input de chat
2. Digite: "Olá agentes, funciona?"
3. Clique em "Enviar" ou pressione ENTER
4. Aguarde 5 segundos
```

**Esperado:**
- [ ] Mensagem aparece no chat (à direita)
- [ ] Cor diferente (azul/roxo)
- [ ] Timestamp aparece
- [ ] Input limpa
- [ ] Sistema mostra: "Agentes respondendo em 2 minutos..."

---

### 4️⃣ VERIFICAR ARQUIVOS CRIADOS (Local)

**Executar no terminal:**
```bash
# Verificar se mensagem foi registrada
cat logs/monitor-messages.log

# Deve aparecer algo como:
# {"timestamp":"2026-06-28T...","message":"Olá agentes, funciona?","user_agent":"...","ip":"..."}
```

**Esperado:**
- [ ] `logs/monitor-messages.log` não está mais vazio
- [ ] Contém JSON com sua mensagem

**Executar:**
```bash
# Verificar se chat foi criado
ls -la logs/chats/

# Deve listar arquivos como:
# chat_1719559200_a1b2c3d4.json
```

**Esperado:**
- [ ] Um ou mais arquivos `chat_*.json` foram criados
- [ ] Arquivo contém sua mensagem

---

### 5️⃣ AGUARDAR RESPOSTA DOS AGENTES

**Tempo:** ~2-3 minutos

**Durante este tempo:**
- [ ] Workflow `monitor-chat-responses.yml` dispara
- [ ] Script `chat-responder.py` executa
- [ ] Gemini + Claude geram resposta
- [ ] Resposta é salva no histórico

**Verificar no servidor:**
```bash
# Ver se agentes responderam
cat logs/monitor-responses.jsonl

# Deve ter um JSON por linha com respostas
```

**Esperado:**
- [ ] `logs/monitor-responses.jsonl` tem conteúdo
- [ ] Resposta está lá

---

### 6️⃣ VER RESPOSTA NO CHAT

**Após 2-3 minutos:**
- [ ] Resposta dos agentes aparece no monitor
- [ ] Cor diferente (cinza/branco)
- [ ] Mensagem dos agentes visível
- [ ] Timestamp correto

---

### 7️⃣ TESTAR HISTÓRICO

**Ação 2: Criar novo chat**
```
1. Clique em "+ Nova Conversa"
2. Digite nome: "Teste 2"
3. Clique OK
```

**Esperado:**
- [ ] Chat vazio aparece na tela
- [ ] Chat aparece na sidebar
- [ ] Pode digitar nova mensagem

**Ação 3: Voltar ao primeiro chat**
```
1. Na sidebar, clique em "Olá agentes, funciona?"
2. Aguarde carregar
```

**Esperado:**
- [ ] Histórico completo aparece
- [ ] Sua mensagem "Olá agentes, funciona?"
- [ ] Resposta dos agentes
- [ ] Pode continuar conversa

---

### 8️⃣ TESTAR CONTINUAÇÃO

**Ação 4: Continuar conversa**
```
1. Clique no primeiro chat
2. Digite: "Que legal, agora funciona!"
3. Clique Enviar
```

**Esperado:**
- [ ] Nova mensagem adicionada
- [ ] Agentes veem contexto anterior
- [ ] Resposta considera histórico
- [ ] Arquivo JSON atualizado com nova mensagem

**Verificar:**
```bash
cat logs/chats/chat_*.json | grep "continuação"
```

---

## 📊 RESULTADO DOS TESTES

### Se TUDO passou:
✅ SISTEMA PRONTO PARA DEPLOY

```
[OK] Interface HTML carrega
[OK] Sidebar funciona
[OK] Envio de mensagens
[OK] Webhook registra
[OK] Histórico criado
[OK] Agentes respondem
[OK] Histórico carregado
[OK] Continuação de conversa
```

### Se ALGO falhou:
❌ VERIFICAR ANTES DE DEPLOY

Possíveis problemas:
1. **Diretórios não criados:**
   ```bash
   mkdir -p logs/chats
   chmod 755 logs/chats
   ```

2. **Webhook não funciona:**
   - Verificar se `/api/monitor/chat-webhook.php` acessível
   - Verificar permissões de escrita em `logs/`

3. **Chat não salva:**
   - Verificar se `/api/monitor/chat-history.php` acessível
   - Verificar conteúdo de `logs/chats/chat_*.json`

4. **Agentes não respondem:**
   - Verificar GitHub Actions: `monitor-chat-responses.yml`
   - Verificar APIs (Gemini/Claude) nos Secrets
   - Aguardar mais tempo (às vezes leva 5 minutos)

---

## 🚀 APÓS TESTES PASSAREM

1. Fazer commit de confirmação:
   ```bash
   git add -A
   git commit -m "test: chat historico validado e funcionando"
   git push
   ```

2. Documentar no README:
   - Versão do chat
   - Data de deploy
   - Funcionalidades testadas

3. Notificar stakeholders:
   - Sistema de chat com histórico online
   - Agentes respondendo automaticamente
   - Conversas persistentes

---

## 📝 TEMPLATE DE TESTE MANUAL

Copie e preencha ao testar:

```
DATA DO TESTE: _______________
TESTADOR: _______________

1. Interface HTML:
   [ ] Dashboard carrega
   [ ] Sidebar aparece
   [ ] Chat renderiza
   
2. Envio de mensagem:
   [ ] Mensagem aparece
   [ ] Input limpa
   [ ] Webhook registra
   
3. Histórico:
   [ ] Chat criado
   [ ] Arquivo JSON exists
   [ ] Dados salvos corretamente
   
4. Agentes:
   [ ] Workflow disparou
   [ ] Resposta gerada (2-3 min)
   [ ] Resposta aparece no chat
   
5. Continuação:
   [ ] Chat anterior carrega
   [ ] Nova mensagem adiciona
   [ ] Histórico preservado

RESULTADO FINAL: ✅ PASSOU / ❌ FALHOU

OBSERVACOES:
_________________________________
_________________________________
_________________________________
```

---

## ⏱️ TIMELINE

```
T+0min   - Enviar mensagem
T+0:30s  - Webhook registra
T+1min   - Chat JSON criado
T+2min   - Workflow dispara (monitor-chat-responses.yml)
T+2:30m  - chat-responder.py executa
T+2:45m  - APIs Gemini/Claude processam
T+3min   - Resposta salva em logs/monitor-responses.jsonl
T+3:30m  - Interface atualiza com resposta
```

---

## ✅ RESUMO

**Sistema está pronto para ser testado!**

1. Acesse o monitor
2. Envie mensagem
3. Aguarde agentes
4. Verifique histórico
5. Continue conversa
6. Report resultado

**Após validação:** FAZ COMMIT E PUSH!

---

*Teste completo = Deploy seguro*  
*Boa sorte!* 🚀
