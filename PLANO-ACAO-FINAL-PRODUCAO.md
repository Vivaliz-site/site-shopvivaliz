# 🎯 PLANO DE AÇÃO FINAL - PRODUÇÃO

**Data:** 2026-07-13 20:22 UTC  
**Responsável:** Claude Code  
**Status:** FASE FINAL DE CORREÇÕES  

---

## ✅ STATUS ATUAL

### Infraestrutura
- ✅ Auto-sync daemon: ATIVO (30s ciclos validado)
- ✅ Sincronização local/remoto: 100% validado
- ✅ Email SMTP: Configurado (Gmail)
- ✅ Renovador Olist: Ativado (2min teste)
- ✅ Git hooks: Segurança OK

### Bloqueadores Críticos
- 🔴 Token Olist: Expirado (precisa regenerar manualmente)
- 🟢 Email SMTP: Configurado ✓

### Issues Importantes
- 🟡 VM Oracle: Não testada (SSH falhou por autenticação)
- 🟡 GitHub Secrets: Alguns faltando
- 🟡 59 Workflows: Consolidar (próxima semana)

---

## 🎯 AÇÕES NECESSÁRIAS AGORA

### AÇÃO 1: Regenerar Token Olist (URGENTE - 5 MIN)

**Status:** Token JWT expirado, não consegue renovar sozinho  
**Solução:** Regenerar manualmente via dashboard

```
1. Ir a: https://www.olist.com.br/developer/settings/access-tokens
2. Logout → Login
3. Gerar novo "Access Token" (com permissão refresh_token)
4. Copiar token completo
5. Editar .env:
   OLIST_REFRESH_TOKEN=eyJhbGc... (novo token aqui)
6. Commit + push (daemon fará auto-sync)
```

**Próxima:** Workflow renovador executará a cada 2 min e manterá token válido

### AÇÃO 2: Verificar GitHub Secrets (5 MIN)

**Status:** Alguns secrets podem estar faltando  
**Verificação:**

```bash
# Terminal:
gh secret list

# Web:
https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
```

**Secrets Obrigatórios:**
- FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PORT
- OLIST_CLIENT_ID, OLIST_CLIENT_SECRET, OLIST_REFRESH_TOKEN
- ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY

**Se faltar:** Adicionar via web interface

### AÇÃO 3: Validar VM Oracle (10 MIN)

**Status:** SSH sem chave configurada  
**Opções:**

```bash
# Opção A: Configurar chave SSH
# (requerer chave privada do usuário)

# Opção B: Testar site diretamente
curl -I https://shopvivaliz.com.br/
# Esperado: HTTP/1.1 200 OK ou 301 (redirect)

# Opção C: Monitorar via GitHub Actions
# Health check runs automaticamente a cada 5 min
```

**Expectativa:** Site respondendo 200 OK

### AÇÃO 4: Monitorar Email + Olist (30 MIN)

**Status:** Configurado, aguardando primeiro teste  
**O que fazer:**

```bash
# Monitor automático (30s updates)
bash scripts/monitor-olist-renewal.sh

# Ou verificar logs
tail -f logs/olist-live-sync-response.json
tail -f logs/email-*.log
```

**O que esperar:**
- ✅ Workflow dispara a cada 2 min (teste)
- ✅ Token renovado ou mantido válido
- ✅ Catálogo sincronizado
- ✅ Novo pedido → email enviado

**Decisão em 30 min:** Sucesso? Mudar para 2h e deploy

---

## 📋 CHECKLIST DE CONCLUSÃO

### Hoje (2026-07-13)

**Antes de dormir:**
- [ ] Token Olist regenerado manualmente
- [ ] GitHub secrets verificados
- [ ] Email SMTP testado (novo pedido)
- [ ] Monitor acompanhado por 30 min
- [ ] Decisão: Sucesso ou falha?

**Se sucesso:**
- [ ] Mudar workflow cron: `0 */2 * * *` (2h)
- [ ] Commit final
- [ ] 🚀 DEPLOY PRODUÇÃO

**Se falha:**
- [ ] Identificar erro específico
- [ ] Diagnosticar causa
- [ ] Aplicar fix
- [ ] Retry monitor

### Próxima Semana

- [ ] Consolidar 59 workflows (reduzir redundância)
- [ ] Configurar SSH key para VM Oracle
- [ ] Adicionar token expiration alerting
- [ ] Setup monitoring completo

---

## 🚨 DECISÃO TREE

```
Começar com: Regenerar Token Olist
         ↓
         ├─ Sucesso (new token gerado)
         │  └─ Validar nos logs do workflow
         │     └─ Token válido? ✅
         │        └─ Email funciona? ✅
         │           └─ PRONTO PARA PRODUÇÃO
         │
         └─ Falha (token ainda inválido)
            └─ Verificar logs de erro
               └─ Diagnosticar + fix
                  └─ Retry monitor
```

---

## ⏱️ TIMELINE RECOMENDADA

```
Agora:      Regenerar token + GitHub secrets          [10 min]
+10 min:    Iniciar monitor Olist/Email               [30 min]
+40 min:    Verificar VM Oracle (curl test)           [5 min]
+45 min:    Decisão: Produção SIM/NÃO                 [5 min]
+50 min:    Se SIM: Mudar cron para 2h                [2 min]
+52 min:    Commit final + push                       [3 min]
+55 min:    🚀 DEPLOY PRODUÇÃO
```

---

## 📞 REFERÊNCIAS RÁPIDAS

### Regenerar Token
```
Dashboard: https://www.olist.com.br/developer/settings/access-tokens
Arquivo: .env (OLIST_REFRESH_TOKEN=)
Verificação: logs/olist-sync.log
```

### Verificar GitHub Secrets
```
Web: https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
CLI: gh secret list
```

### Monitorar Email + Olist
```
Script: bash scripts/monitor-olist-renewal.sh
Logs: tail -f logs/olist-*.log
Status: tail -f logs/email-*.log
```

### Testar VM Oracle
```
Site: curl -I https://shopvivaliz.com.br/
SSH: ssh -i chave.pem ubuntu@137.131.156.17
```

---

## ✨ RESULTADO ESPERADO (PRODUÇÃO)

```
Email:              ✅ Gmail SMTP funcional
Olist:              ✅ Token renovado a cada 2h
Sync:               ✅ Local = Remoto = Produção
Daemon:             ✅ Rodando 30s ciclos
VM Oracle:          ✅ Site respondendo
Monitoring:         ✅ Health checks automáticos
```

---

## 🎯 GO-LIVE CHECKLIST

- [ ] Token Olist: renovado
- [ ] Email SMTP: testado
- [ ] GitHub Secrets: verificados
- [ ] Workflow: testado (2 min ciclo)
- [ ] Monitor: sucesso por 30 min
- [ ] VM Oracle: respondendo
- [ ] Commit final: pushado
- [ ] Workflow: mudado para 2h
- ✅ **PRODUÇÃO LIBERADA**

---

**Próximo:** Execute as ações 1-4 acima! 🚀
