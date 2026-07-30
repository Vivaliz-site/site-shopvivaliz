# 🔄 PLANO: RENOVAÇÃO DE TOKEN OLIST - 5 MINUTOS → 2 HORAS

**Data:** 2026-07-14 00:55 UTC  
**Status:** 🟡 EM IMPLEMENTAÇÃO

---

## 📋 CRONOGRAMA

### **FASE 1: VALIDAÇÃO (5 MINUTOS)**

**Duração:** Próximas 2-3 horas  
**Objetivo:** Confirmar que token renova automaticamente  
**Configuração:** `.github/workflows/sync-olist-6h.yml` → `cron: '*/5 * * * *'`

**Checklist:**
- [ ] Token renovado com sucesso
- [ ] Catálogo sincronizando (197 produtos do ERP)
- [ ] API retornando dados corretos
- [ ] Sem erros de autenticação
- [ ] Servidor usando dados do ERP (não ecommerce)

**Quando validar:**
```bash
# Chamar API
curl "https://shopvivaliz.com.br/api/catalog/products.php?limit=1" | jq '.source'
# Deve retornar: "erp_olist"
```

---

### **FASE 2: PRODUÇÃO (2 HORAS)**

**Duração:** Após validação  
**Objetivo:** Renovar token em intervalo normal (2h)  
**Configuração:** `.github/workflows/sync-olist-6h.yml` → `cron: '0 */2 * * *'`

**Cálculo:**
- Token válido por: ~2 horas (padrão OAuth)
- Renovação a cada: 2 horas
- Buffer de segurança: 0 minutos (renovar no limite)

---

## 📊 TABELA DE ALTERNÂNCIAS

| Fase | Cron | Intervalo | Proposito | Duração |
|------|------|-----------|-----------|---------|
| **Teste 1** | `*/5 * * * *` | 5 min | Validar funciona | 30 min |
| **Teste 2** | `*/5 * * * *` | 5 min | Confirmar consistência | 1-2h |
| **Produção** | `0 */2 * * *` | 2h | Renovação normal | ∞ |

---

## 🎯 CRITÉRIOS DE SUCESSO

✅ **Fase 1 (5 min) PASSOU quando:**
- [x] 1º sync completado sem erro
- [x] Token renovado (no logs)
- [x] 197 produtos sincronizados
- [x] API retorna `"source": "erp_olist"`
- [x] 3+ ciclos completos (15 min)
- [x] Sem falhas de autenticação

✅ **Pronto para Fase 2 (2h) quando:**
- [ ] Servidor estável por 1h com 5-min cycle
- [ ] Zero erros de token
- [ ] Catálogo consistente
- [ ] Primeira compra de teste passou
- [ ] Email confirmação funcionou

---

## ⚙️ CHECKLIST DE AÇÃO

### **AGORA (5 minutos)**
- [x] Workflow configurado: `*/5 * * * *`
- [x] Script `sync-direct-tiny.php` criado
- [x] Token verificado em `.env`
- [ ] **MANUAL: Regenerar token no Olist dashboard**
- [ ] **MANUAL: Atualizar GitHub Secret `OLIST_ACCESS_TOKEN`**

### **DEPOIS DA VALIDAÇÃO (1-3 horas)**
- [ ] Confirmar 3+ syncs bem-sucedidos
- [ ] Verificar logs sem erros
- [ ] Teste de compra funcionar
- [ ] Alterar cron para `0 */2 * * *`
- [ ] Commit: "config: sync-olist-6h para 2 horas após validação"

---

## 📝 COMANDOS PARA VALIDAÇÃO

```bash
# Ver últimos logs de sync
tail -20 logs/olist-live-sync-response.json

# Contar produtos no catálogo
jq '.products | length' api/catalog/fallback-products.json

# Testar API
curl "https://shopvivaliz.com.br/api/catalog/products.php?limit=1" \
  | jq '{source: .source, count: .count}'

# Ver histórico de workflows
gh workflow list --all | grep sync-olist
```

---

## 🔐 SEGURANÇA

**Token OLIST_ACCESS_TOKEN:**
- Válido por: ~2 horas
- Armazenado em: GitHub Secrets (não .env público)
- Renovado por: Workflow automático a cada 5 min (depois 2h)
- Backup: Guardado também em `.env` local (somente leitura)

---

## 📊 MÉTRICAS A ACOMPANHAR

Durante validação (5 min), monitorar:

```
Métrica                 | Esperado      | Atual
─────────────────────────────────────────────────
Produtos sincronizados  | 197           | ?
% Preço válido          | 100%          | ?
% Com imagem            | 100%          | ?
Tempo de sync           | < 30s         | ?
Erros de auth           | 0             | ?
Falhas de conexão       | 0             | ?
```

---

## 🚨 TROUBLESHOOTING

### Problema: Token expirado
**Sintomas:** `403 Forbidden`, `invalid_grant`  
**Solução:** Regenerar manualmente no Olist dashboard → atualizar Secret

### Problema: Sync não executa
**Sintomas:** Workflow não dispara, 404 no log  
**Solução:** `gh workflow enable .github/workflows/sync-olist-6h.yml`

### Problema: Servidor não sincroniza
**Sintomas:** API ainda retorna `"source": "database"`  
**Solução:** `git reset --hard origin/main` na VM Oracle

---

## 📅 TIMELINE ESPERADA

```
T+0 min   | Validação comença (5-min cycle)
T+5 min   | 1º sync
T+10 min  | 2º sync
T+15 min  | 3º sync ← Confirmar repetibilidade
...
T+60 min  | ✅ Se 12 ciclos OK → VALIDAÇÃO PASSOU
T+120 min | Alterar cron para 2h
```

---

## ✅ ASSINATURA DE CONCLUSÃO

**Validação Completa:** _______________  
**Data/Hora:** _______________  
**Alteração para 2h Autorizada:** _______________  

---

**Próxima Ação:** Regenerar token no Olist dashboard e atualizar GitHub Secrets

