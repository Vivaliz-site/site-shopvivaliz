# 🤖 TAREFAS PARA GPT - FINALIZAR PRODUÇÃO

**De:** Claude Code (Local)  
**Para:** GPT / Outro Agente  
**Data:** 2026-07-13  
**Urgência:** 🔴 CRÍTICA

---

## CONTEXTO

Sistema ShopVivaliz está **99% pronto para produção**. Auto-sync daemon e sincronização bidirecional estão 100% operacionais. Faltam **2 correções críticas** que bloqueiam deploy:

1. **Olist token expirado** (desde 2026-07-09)
2. **Email SMTP não configurado**

---

## TAREFAS A EXECUTAR

### TAREFA 1: Renovar Olist Token (GPT/Agente)

**Prioridade:** 🔴 MÁXIMA (orderna bloqueando 100% das vendas)  
**Tempo:** 5-15 minutos

**Ação:**
```bash
# Opção A: Tentar renovação automática
curl -X POST http://dev.shopvivaliz.com.br/api/olist/refresh-token.php \
  -H "Content-Type: application/json"

# Se sucesso (status: ok, token: ey...):
#   ✓ Novo token foi auto-salvo em .env
#   ✓ Próximas ordens sincronizarão

# Se falha (401):
#   → Fazer re-auth manual em:
#   → https://www.olist.com.br/developer/settings/access-tokens
#   → Copiar novo Access Token
#   → Adicionar em .env: OLIST_REFRESH_TOKEN=ey...
#   → Commit + push
```

**Validação:**
```bash
# Criar novo pedido de teste
# Verificar em logs:
tail -f logs/orchestrator.log | grep -i olist

# Esperado:
# [OK] Olist sync successful
# [OK] Order pushed to Tiny ERP
```

**Arquivo de Referência:** `/api/olist/refresh-token.php`

---

### TAREFA 2: Configurar Email SMTP (GPT/Agente)

**Prioridade:** 🔴 MÁXIMA (clientes não recebem confirmação)  
**Tempo:** 10-20 minutos

**Ação A: Gmail (Recomendado)**
```bash
# 1. Habilitar 2FA em https://myaccount.google.com/security
# 2. Gerar App Password em https://myaccount.google.com/apppasswords
# 3. Adicionar ao .env:

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=seu-email@gmail.com
SMTP_PASS=xxxx-xxxx-xxxx-xxxx  # (app password 16 chars)
SMTP_FROM=noreply@shopvivaliz.com.br
SMTP_FROMNAME="ShopVivaliz"
```

**Ação B: Titan (já mencionado em .env.example)**
```bash
SMTP_HOST=smtp.titan.email
SMTP_PORT=465
SMTP_USER=agentes@shopvivaliz.com.br
SMTP_PASS=[pedir credencial real]
SMTP_FROM=agentes@shopvivaliz.com.br
```

**Ação C: SendGrid**
```bash
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USER=apikey
SMTP_PASS=SG.xxxxxxxxxxxxx
```

**Processo:**
1. Escolher provider (Gmail mais simples)
2. Gerar credenciais
3. Editar `.env`
4. Adicionar 5 linhas SMTP_*
5. Commit + push (daemon fará auto-sync)

**Validação:**
```bash
# Teste 1: Email de teste
curl -X POST http://dev.shopvivaliz.com.br/api/mail/test.php \
  -d "to=seu-email@teste.com"

# Verificar inbox (30 seg)

# Teste 2: Criar novo pedido
# Verificar email do cliente

# Teste 3: Verificar logs
tail -f logs/email-*.log
# Esperado: "Email sent: OK"
```

---

### TAREFA 3: Validação (Paralelo)

**Prioridade:** 🟡 IMPORTANTE  
**Tempo:** 15-30 minutos

**Checklist:**
- [ ] Olist token renovado
- [ ] Email SMTP enviando
- [ ] Novo pedido: sincroniza com Olist? ✓
- [ ] Novo pedido: cliente recebe email? ✓
- [ ] Logs sem erros

**Resultado esperado:**
```
✅ Olist: Token válido, pedidos sincronizando
✅ Email: SMTP configurado, emails sendo enviados
✅ Sistema: 100% funcional, pronto para produção
```

---

### TAREFA 4: Validação VM Oracle (Paralelo)

**Prioridade:** 🟡 IMPORTANTE  
**Tempo:** 20-30 minutos

**Ação:**
```bash
# SSH em produção
ssh -i chave.pem ubuntu@137.131.156.17

# Verificar acesso
whoami  # deve retornar: ubuntu

# Verificar git-auto-sync.py rodando
ps aux | grep git-auto-sync

# Verificar Apache
sudo systemctl status apache2

# Testar site
curl -I https://shopvivaliz.com.br
# Esperado: HTTP/1.1 200 OK

# Verificar .env
cat /home/ubuntu/site-shopvivaliz/.env | head -10
```

**Resultado:** VM pronta, site acessível, sync automático rodando

---

## PARALLELIZAÇÃO

```
GPT/Agente 1:                  Claude (Local):
├─ Renovar Olist token    ↔    ├─ Monitorar logs
├─ Testar pedido Olist    ↔    ├─ Documenta sucesso
├─ Validar logs           ↔    └─ Commit + push automático
└─ Report resultado       ↔    

GPT/Agente 2:
├─ Configurar Email SMTP
├─ Testar envio email
├─ Criar pedido de teste
└─ Report resultado

[PARALELO - Próximo]:
├─ Validar VM Oracle
├─ Testar SSH + Apache
└─ Report final
```

---

## DOCUMENTAÇÃO DE REFERÊNCIA

- `ACAO-PRODUCAO-HOJE.md` — Step-by-step detalhado
- `PRODUCAO-BLOQUEADORES.md` — Análise dos bloqueadores
- `.env.example` — Variáveis disponíveis

---

## COMUNICAÇÃO DE STATUS

### Quando completar Tarefa 1 (Olist)
```
Responder:
"Olist token renovado: [SIM/NÃO]
Status: [token válido / falha 401]
Novo pedido sincronizou: [SIM/NÃO]
Logs OK: [SIM/NÃO]"
```

### Quando completar Tarefa 2 (Email)
```
Responder:
"Email SMTP configurado: [SIM/NÃO]
Provider: [Gmail/Titan/SendGrid]
Email de teste: [enviado/falha]
Novo pedido recebeu email: [SIM/NÃO]
Logs OK: [SIM/NÃO]"
```

### Quando completar Tudo
```
Responder:
"✅ Olist: OK
✅ Email: OK
✅ Validação: OK
🚀 PRONTO PARA DEPLOY"
```

---

## SE BLOQUEAR

### Erro de renovação Olist (401)
→ Fazer re-auth manual no dashboard Olist  
→ Gerar novo token OAuth  
→ Adicionar em .env e commit

### Erro de Email não enviado
→ Verificar logs: tail -f logs/email-*.log  
→ Testar credenciais SMTP manualmente  
→ Se falha, credencial errada ou 2FA não habilitada

### .env não carregado em produção
→ SSH na VM: ssh -i chave.pem ubuntu@137.131.156.17  
→ Editar .env e salvar  
→ Restart Apache: sudo systemctl restart apache2

---

## GO-LIVE SEQUENCE

```
1. ✅ Olist renovado + testado
2. ✅ Email configurado + testado
3. ✅ VM Oracle validada
4. ✅ GitHub secrets verificados
   ↓
5. 🚀 DEPLOY PRODUCTION
   ├─ Health checks
   ├─ Daemon auto-sync (já rodando)
   └─ Sistema 100% operacional
```

---

## TEMPO TOTAL

- Olist: 5-15 min
- Email: 10-20 min
- Validação: 15-30 min
- ─────────────────
- **TOTAL: ~30-65 minutos até go-live** 🚀

---

**Agora pronto para que próximo agente comece!**
