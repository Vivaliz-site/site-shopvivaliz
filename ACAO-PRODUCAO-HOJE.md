# 🚀 AÇÃO PRODUÇÃO - HOJE (2026-07-13)

**Objetivo:** Desbloquear os 2 itens críticos para produção  
**Tempo Estimado:** 30 minutos  
**Responsável:** Agente Autônomo ou Administrador  

---

## ❌ PROBLEMA 1: OLIST TOKEN EXPIRADO

### Status Atual
```
Token JWT exp: 2026-07-09 23:17:13 UTC (EXPIRADO HÁ 4 DIAS)
Impacto: 100% dos pedidos retornam 401 Unauthorized
Resultado: Nenhum pedido chega ao ERP/Supplier
```

### Solução Rápida (5 minutos)

**Opção A: Auto-renovação (tente primeiro)**
```bash
curl -X POST http://dev.shopvivaliz.com.br/api/olist/refresh-token.php \
  -H "Content-Type: application/json"

# Se resposta for { "status": "ok", "token": "ey..." }
# Sucesso! Novo token foi auto-salvo em .env
```

**Opção B: Se A falhar, Re-autenticar Manual**
1. Ir a: https://www.olist.com.br/developer/settings/access-tokens
2. Fazer logout e login novamente
3. Gerar novo Access Token
4. Copiar novo token
5. Adicionar em `.env`: `OLIST_REFRESH_TOKEN=ey...`
6. Commit + push

### Validação
```bash
# Criar novo pedido de teste
# Verificar em logs:
tail -f logs/orchestrator.log | grep -i olist

# Deve mostrar:
# [OK] Olist sync successful
# [OK] Order pushed to Tiny ERP

# Verificar em dashboard Olist se novo pedido aparece
```

---

## ❌ PROBLEMA 2: EMAIL SMTP NÃO CONFIGURADO

### Status Atual
```
SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS ausentes em .env
Impacto: Clientes não recebem confirmação de compra
Resultado: Taxa de conversão cai, suporte sobrecarregado
```

### Solução Rápida (10 minutos)

**Opção A: Gmail (recomendado)**
```bash
# 1. Habilitar 2FA em https://myaccount.google.com/security
# 2. Gerar App Password em https://myaccount.google.com/apppasswords
# 3. Adicionar ao .env:

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=seu-email@gmail.com
SMTP_PASS=xxxx-xxxx-xxxx-xxxx  # (app password, 16 chars)
SMTP_FROM=noreply@shopvivaliz.com.br
SMTP_FROMNAME="ShopVivaliz"
```

**Opção B: Outro Provider (SendGrid, Mailgun, etc)**
```bash
# Configurar conforme provider

# Exemplo SendGrid:
SMTP_HOST=smtp.sendgrid.net
SMTP_PORT=587
SMTP_USER=apikey
SMTP_PASS=SG.xxxxxxxxxxx
```

### Adicionar ao .env
```bash
# SSH em produção (ou editar localmente e push)
ssh -i chave.pem ubuntu@137.131.156.17

# Editar .env
nano /home/ubuntu/site-shopvivaliz/.env

# Adicionar as 5 linhas acima
# Salvar (Ctrl+O, Enter, Ctrl+X)

# Reiniciar Apache para carregar novo .env
sudo systemctl restart apache2
```

### Validação
```bash
# Teste 1: Enviar email de teste
curl -X POST http://dev.shopvivaliz.com.br/api/mail/test.php \
  -d "to=seu-email@teste.com"

# Verificar inbox (deve chegar em 30 segundos)

# Teste 2: Criar novo pedido
# Verificar email do cliente recebeu confirmação

# Teste 3: Ver logs
tail -f logs/email-*.log
# Deve mostrar "Email sent: OK"
```

---

## 📋 CHECKLIST EXECUÇÃO

### Antes (Validação de Base)
- [ ] Confirmar que daemon sync está rodando (`ps aux | grep auto-sync-daemon`)
- [ ] Confirmar que main está sincronizado (`git status`)
- [ ] Fazer backup de `.env` local

### Passo 1: Olist Token (5 min)
- [ ] Executar curl /api/olist/refresh-token.php
- [ ] Verificar resposta: "status": "ok"
- [ ] Se falhar, fazer re-auth manual no dashboard Olist
- [ ] Testar com novo pedido → verificar sincronização

### Passo 2: Email SMTP (10 min)
- [ ] Escolher provider (Gmail recomendado)
- [ ] Gerar credenciais (App Password ou API key)
- [ ] Adicionar 5 variáveis ao .env
- [ ] Commit local (auto-sync vai fazer push)
- [ ] SSH em produção e validar .env
- [ ] Restart Apache

### Passo 3: Validação (15 min)
- [ ] Teste de email: curl /api/mail/test.php
- [ ] Verificar inbox (30 seg)
- [ ] Criar novo pedido de teste
- [ ] Verificar email do cliente
- [ ] Verificar Olist dashboard (pedido apareceu?)
- [ ] Verificar logs: tail -f logs/orchestrator.log

### Depois (Go-Live)
- [ ] VM Oracle acessível via SSH ✓
- [ ] Apache respondendo ✓
- [ ] Site https://shopvivaliz.com.br/ respondendo ✓
- [ ] Todos os testes passaram ✓
- [ ] Deploy production readiness: YES ✓

---

## ⚡ EXECUÇÃO PARALELA (se tiver 2 agentes)

```
AGENTE 1:                      AGENTE 2:
├─ Renovar Olist token    ↔    ├─ Configurar SMTP
├─ Testar Olist sync      ↔    ├─ Testar email
└─ Documentar resultado   ↔    └─ Documentar resultado
```

---

## 🎯 RESULTADO ESPERADO

Quando ambos completados:
```
✅ Olist token: VÁLIDO
   └─ Novo pedido → Olist sync OK → ERP recebe

✅ Email SMTP: CONFIGURADO
   └─ Novo pedido → Email enviado → Cliente recebe confirmação

✅ Sistema: 100% Funcional
   └─ Pronto para produção em ~30 minutos
```

---

## 🔧 Se der Erro

### Erro: 401 na renovação Olist
```
→ Fazer re-auth manual no dashboard:
  https://www.olist.com.br/developer/settings/access-tokens
→ Copiar novo token completo (com "Bearer " ou não?)
→ Tester com curl -H "Authorization: Bearer TOKEN"
```

### Erro: Email não enviado
```
→ Verificar logs: tail -f logs/email-*.log
→ Testar credenciais SMTP manualmente:
  telnet smtp.gmail.com 587
  → HELO shopvivaliz
  → AUTH LOGIN
  → [email base64]
  → [password base64]
→ Se fails: credencial errada ou 2FA não habilitada
```

### Erro: .env não carregado em produção
```
→ SSH na VM: ssh -i chave.pem ubuntu@137.131.156.17
→ Verificar arquivo: cat /home/ubuntu/site-shopvivaliz/.env | grep SMTP
→ Se vazio: editar e salvar
→ Restart Apache: sudo systemctl restart apache2
```

---

## 📞 Fallback se Bloqueado

Se não conseguir resolver em 30 min:
- Documentar o erro específico
- Criar issue no GitHub
- Aguardar próximo agente
- Continuar com itens #3 e #4 (VM Oracle validation, secrets)

---

## ✅ Conclusão

Quando estes 2 itens estiverem ✓:
- Auto-sync daemon: **ATIVO**
- Olist token: **RENOVADO**
- Email SMTP: **CONFIGURADO**
- Sistema: **PRONTO PARA PRODUÇÃO**

**Tempo até Go-Live: ~1 hora** 🚀
