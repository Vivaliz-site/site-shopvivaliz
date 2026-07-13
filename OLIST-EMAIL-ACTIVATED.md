# ✅ OLIST TOKEN RENEWAL + EMAIL SMTP ATIVADO

**Data:** 2026-07-13 20:19:03 UTC  
**Status:** ✅ CONFIGURADO E TESTANDO  
**Commit:** 42f0e06

---

## 📋 O QUE FOI FEITO

### 1. ✅ Email SMTP Configurado (Gmail)
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=shopvivaliz@gmail.com
SMTP_PASS=ukts yplc vtij jjpx
SMTP_FROM=shopvivaliz@gmail.com
EMAIL_FROM=shopvivaliz@gmail.com
EMAIL_TO=fredmourao@gmail.com,atendimento@shopvivaliz.com.br
```

**Status:** ✅ ATIVO  
**Teste:** Próximas 5 horas

### 2. ✅ Renovador Olist Ativado (Teste)
```
Workflow: .github/workflows/sync-olist-6h.yml
Cron: */2 * * * *  (a cada 2 MINUTOS)
Ação: Renova token + sincroniza catálogo
```

**Status:** ✅ ATIVO  
**Primeira execução:** Nos próximos 2 minutos

### 3. ✅ Monitoramento em Tempo Real
```
Script: scripts/monitor-olist-renewal.sh
Execução: bash scripts/monitor-olist-renewal.sh
Atualização: A cada 30 segundos
```

**Status:** ✅ PRONTO

---

## 🔄 CICLO DE RENOVAÇÃO ATUAL

```
GitHub Workflow (a cada 2 minutos):
├─ Checkout repo
├─ Setup PHP 8.3
├─ Executar olist/sync-products.php
│  ├─ Tentar renovar token
│  ├─ Sincronizar catálogo
│  └─ Salvar novo token em storage/private/tokens.json
├─ Validar preços/estoque/imagens
└─ Commit + push se houver mudanças

Email Automático (quando houver novo pedido):
├─ Detectar novo pedido
├─ Buscar dados do cliente
├─ Compilar email via template
├─ Conectar ao SMTP Gmail
└─ Enviar confirmação
```

---

## ⏱️ CRONOGRAMA DE TESTES

### Agora (2026-07-13 20:19)
- ✅ Email SMTP configurado
- ✅ Workflow renovador ativado
- ⏳ Aguardando primeira execução

### +2 minutos (20:21)
- [ ] Workflow dispara pela 1ª vez
- [ ] Renovar token (tentativa)
- [ ] Sincronizar catálogo
- [ ] Log: `logs/olist-live-sync-response.json`

### +5 minutos (20:24)
- [ ] 2ª execução do workflow
- [ ] Verificar se token foi renovado
- [ ] Testar novo pedido → email enviado?

### +15 minutos (20:34)
- [ ] 7 execuções do workflow completadas
- [ ] Padrão de sucesso/falha visível
- [ ] Email status consolidado

### +30 minutos (20:49)
- [ ] 15 execuções completadas
- [ ] Decisão: sucesso? voltar para 2h
- [ ] Decisão: falha? diagnóstico

---

## 🚨 PROBLEMA CONHECIDO

**Token JWT Expirado:** Não consegue renovar sozinho (invalid_grant)  
**Solução temporária:** Usar Access Token existente  
**Solução permanente:** Regenerar novo token via OAuth no dashboard Olist

### Como Gerar Novo Token (Manual)
1. Ir a: https://www.olist.com.br/developer/settings/access-tokens
2. Fazer logout + login
3. Gerar novo "Access Token"
4. Copiar token completo
5. Adicionar em `.env`: `OLIST_REFRESH_TOKEN=eyJhbGc...`
6. Commit + push

---

## 📊 MONITORAMENTO

### Opção 1: Monitor em Tempo Real (Automático)
```bash
# Terminal 1:
bash scripts/monitor-olist-renewal.sh

# Mostra:
# - Status do token (valid/expired)
# - Últimas execuções do workflow
# - Status do email
# - Atualiza a cada 30 segundos
```

### Opção 2: Verificar Logs
```bash
# Workflow execution
tail -f logs/olist-live-sync-response.json

# Token renewal
tail -f logs/olist-sync.log

# Email sending
tail -f logs/email-*.log

# Orders
tail -f logs/orchestrator.log
```

### Opção 3: GitHub Actions
```
https://github.com/Vivaliz-site/site-shopvivaliz/actions
Procurar por: "Sincronizar Olist/Tiny - Auto-Renew Token (2h)"
```

---

## ✅ CHECKLIST DE EXECUÇÃO

### Fase 1: Configuração (COMPLETO ✓)
- [x] Email SMTP Gmail configurado
- [x] Workflow renovador ativado (2min)
- [x] Monitor criado
- [x] Commit + auto-sync done

### Fase 2: Teste Inicial (AGORA)
- [ ] Aguardar 1ª execução (2 min)
- [ ] Verificar logs: sucesso?
- [ ] Criar pedido de teste
- [ ] Email chegou?

### Fase 3: Validação (5-15 min)
- [ ] 7+ execuções do workflow
- [ ] Token renovado? (verificar em logs)
- [ ] Catálogo sincronizado?
- [ ] Emails sendo enviados?

### Fase 4: Decisão (30 min)
- [ ] Tudo OK? Voltar para cron: `0 */2 * * *` (a cada 2h)
- [ ] Falha? Diagnóstico + fix
- [ ] Sucesso? 🚀 PRODUÇÃO

---

## 🔍 POSSÍVEIS ERROS E SOLUÇÕES

### Erro: Token ainda inválido
```
Causa: Token expirado não consegue renovar
Solução: Regenerar manualmente via dashboard Olist (ver acima)
Workaround: Usar Access Token existente por enquanto
```

### Erro: Email não enviado (conexão Gmail)
```
Causa: Firewall/proxy bloqueando SMTP 587
Solução: Testar conexão: telnet smtp.gmail.com 587
Fallback: Usar SMTP 465 (SSL) em vez de 587 (TLS)
```

### Erro: Workflow não executa
```
Causa: Workflows desativados em Settings
Solução: GitHub → Settings → Actions → All workflows enabled
Verificar: https://github.com/Vivaliz-site/site-shopvivaliz/actions
```

### Erro: Catálogo não sincroniza
```
Causa: API Olist/Tiny retorna erro
Solução: Verificar logs: logs/olist-live-sync-response.json
Verificar: Token ainda válido? Credenciais OK?
```

---

## 📞 RESUMO OPERACIONAL

| Item | Status | Próximo |
|------|--------|---------|
| Email SMTP | ✅ Configurado | Teste com novo pedido |
| Renovador Token | ✅ Ativado 2min | Aguardar 1ª execução |
| Monitor | ✅ Pronto | bash scripts/monitor-olist-renewal.sh |
| Automação | ✅ Deploy | Será ativada em 2 min |
| Produção | 🔴 Bloqueado | Aguardando sucesso do teste |

---

## 🎯 PRÓXIMAS AÇÕES

### Imediato (agora)
```
1. Aguardar 2 minutos (até 20:21)
2. Verificar logs do workflow
3. Se sucesso: criar pedido de teste
4. Verificar se email chegou
```

### Curtíssimo prazo (5-15 min)
```
1. Monitorar 7+ execuções
2. Verificar token renovado
3. Testar catalogo sincronizado
4. Validar emails sendo enviados
```

### Curto prazo (30 min)
```
1. Decisão: voltar para 2h ou diagnosticar
2. Se OK: mudar cron para '0 */2 * * *'
3. Fazer commit final
4. 🚀 PRODUCTION READY
```

---

## ⏰ TIMELINE

```
20:19 - Configuração completa
20:21 - Workflow 1ª execução
20:23 - Workflow 2ª execução
20:25 - Workflow 3ª execução
...
20:49 - Workflow 15ª execução (consolidar status)
21:00 - Decisão final + produção ✓
```

---

**Status Final:** Sistema pronto para monitoramento em tempo real!  
**Próximo:** Execute `bash scripts/monitor-olist-renewal.sh` e acompanhe 🚀
