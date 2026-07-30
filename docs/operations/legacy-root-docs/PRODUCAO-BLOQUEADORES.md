# 🔴 BLOQUEADORES CRÍTICOS PARA PRODUÇÃO

**Data:** 2026-07-13  
**Status:** REQUERENDO AÇÃO IMEDIATA

---

## 1. OLIST TOKEN EXPIRADO ⚠️ CRÍTICO

### Problema
- Token JWT expirou em: **2026-07-09 23:17:13 UTC** (4 dias atrás)
- **Impacto:** 100% dos pedidos NÃO sincronizam com Olist/ERP
- Arquivo: `.env` → `OLIST_REFRESH_TOKEN`
- Evidência: `logs/auto-sync-2026-07-10.log` - múltiplos 401 errors

### Ação Requerida
```bash
# 1. Tentar renovação automática (5 min)
curl -X POST http://dev.shopvivaliz.com.br/api/olist/refresh-token.php

# 2. Se falhar (401), fazer re-auth manual em:
https://www.olist.com.br/developer/settings/access-tokens

# 3. Testar com novo pedido
# 4. Verificar logs de sincronização
```

### Verificação de Sucesso
- ✓ Novo token em `.env`
- ✓ Novo pedido cria com `tiny_push: "ok"`
- ✓ Pedido aparece em dashboard Olist

---

## 2. EMAIL SMTP NÃO CONFIGURADO ⚠️ CRÍTICO

### Problema
- Email sender não configurado em `.env`
- **Impacto:** Notificações de pedidos/status não chegam
- Resultado: Clientes não recebem confirmação de compra
- Arquivo: `.env` → faltam `SMTP_*` variables

### Ação Requerida
```bash
# Adicionar ao .env:
SMTP_HOST=smtp.gmail.com        # ou seu provider
SMTP_PORT=587
SMTP_USER=seu-email@domain.com
SMTP_PASS=sua-senha-app
SMTP_FROM=noreply@shopvivaliz.com.br
```

### Verificação de Sucesso
- ✓ Email de teste enviado
- ✓ Novo pedido dispara email ao cliente
- ✓ Log mostra "Email sent: OK"

---

## 3. SECRETS GITHUB FALTANDO 🟡 IMPORTANTE

### Problema
- Alguns secrets não configurados em GitHub Settings
- **Impacto:** Workflows falham durante deploy

### Secrets Obrigatórios
```
✅ FTP_SERVER
✅ FTP_USERNAME  
✅ FTP_PASSWORD
✅ FTP_PORT
❓ OLIST_REFRESH_TOKEN    (renovar!)
❓ ANTHROPIC_API_KEY
❓ OPENAI_API_KEY
❓ GEMINI_API_KEY
```

### Ação Requerida
1. GitHub → Settings → Secrets and variables → Actions
2. Adicionar/atualizar cada secret
3. Testar CI pipeline

---

## 4. VM ORACLE NÃO VALIDADA 🟡 IMPORTANTE

### Problema
- VM Oracle (`137.131.156.17`) não foi testada para produção
- **Impacto:** Site pode não estar acessível após push
- Informação: CLAUDE.md menciona VM existe, mas não validado aqui

### Ação Requerida
```bash
# 1. Verificar acesso SSH
ssh -i chave.pem ubuntu@137.131.156.17

# 2. Verificar git-auto-sync.py rodando
ps aux | grep git-auto-sync

# 3. Verificar Apache
sudo systemctl status apache2

# 4. Testar site
curl -I https://shopvivaliz.com.br

# 5. Verificar .env em produção
cat /home/ubuntu/site-shopvivaliz/.env
```

### Verificação de Sucesso
- ✓ SSH acesso OK
- ✓ Apache rodando
- ✓ Site responde com HTTP 200
- ✓ .env tem configurações corretas

---

## 5. WORKFLOWS REDUNDANTES 🟡 IMPORTANTE

### Problema
- 59 workflows no `.github/workflows/`
- Múltiplos com funções sobrepostas
- **Impacto:** Conflitos, execução redundante, overhead

### Workflows Críticos (manter)
```
✓ shopvivaliz-qa.yml              → QA lint
✓ auto-validation-and-fix.yml     → Auto-fix issues
✓ ai-autonomous-executor.yml      → Task queue executor
✓ 24-7-complete-system.yml        → Monitoring
```

### Workflows para Consolidar/Remover
```
? autonomous-cycle.yml             (revisar)
? autonomous-orchestrator.yml      (revisar)
? autonomous-proactive.yml         (revisar)
? ci-autonomo-continuo.yml         (revisar)
... [50+ mais]
```

### Ação Requerida
1. Revisar cada workflow
2. Manter apenas os críticos
3. Remover ou consolidar redundantes
4. Documentar propósito de cada um

---

## CRONOGRAMA DE RESOLUÇÃO

### HOJE (2026-07-13)
- [ ] Renovar Olist token (5 min)
- [ ] Configurar SMTP email (10 min)
- [ ] Testar ambos (15 min)
- **Total: 30 min**

### AMANHÃ (2026-07-14)
- [ ] Validar VM Oracle (20 min)
- [ ] Atualizar GitHub secrets (10 min)
- [ ] Executar deploy test (30 min)

### PRÓXIMA SEMANA
- [ ] Consolidar workflows (2-3 horas)
- [ ] Teste de carga (1 hora)
- [ ] Rehearsal de produção (1 hora)

---

## AFTER ACTION - Próxima Janela de Deploy

✅ QUANDO esses 5 items estiverem resolvidos:
- Daemon auto-sync: **PRONTO** ✓
- Sincronização bidirecional: **PRONTO** ✓
- Arquitetura: **PRONTO** ✓
- Olist token: **[PENDENTE]** ⏳
- Email SMTP: **[PENDENTE]** ⏳

**ESTIMATIVA:** Deploy em produção possível em **< 2 horas** após resolver bloqueadores.

---

## Contatos/Referências

- CLAUDE.md: Documentação do sistema
- SYNC-VALIDATION-REPORT.md: Validação de sync
- audit_findings_critical.md (memory): Findings detalhados
- VM: `ubuntu@137.131.156.17`
- Live Site: `https://shopvivaliz.com.br/`
- Admin Monitor: `https://shopvivaliz.com.br/admin/monitor/`
