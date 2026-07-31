# 🚨 URGENTE: RENOVAR OLIST TOKEN AGORA

**Problema:** Token JWT expirou em 2026-07-09, bloqueando todas as sincronizações  
**Solução:** Renovação manual + automação futura  
**Tempo:** 15 minutos

---

## CAUSA RAIZ

Token OAuth JWT expirou → Workflow de renovação a cada 2h não consegue renovar (precisa do token pra renovar)

### Arquivo da Automação
- Workflow: `.github/workflows/sync-olist-6h.yml`
- Cron: `0 */2 * * *` (cada 2 horas) ✓
- Script: `olist/sync-products.php` (com renovação integrada) ✓
- **Problema:** Token expirado = workflow falha silenciosamente

---

## SOLUÇÃO IMEDIATA (15 MIN)

### Passo 1: Obter novo token Olist/Tiny

#### Opção A: Renovação Automática (se credentials estão OK)
```bash
# Tentar renovar via script que existe
php api/olist/refresh-token.php

# Se retornar:
# { "status": "ok", "token": "eyJhbGc..." }
# ✓ SUCESSO - Novo token foi salvo
```

#### Opção B: Re-autenticação Manual (se A falhar)
```
1. Ir a: https://www.olist.com.br/developer/settings/access-tokens
2. Fazer logout + login
3. Gerar novo Access Token
4. Copiar token completo (incluindo "Bearer" se houver)
5. Adicionar em .env:
   OLIST_REFRESH_TOKEN=eyJhbGc...
```

### Passo 2: Validar .env tem credenciais
```bash
grep "OLIST_CLIENT_ID\|OLIST_CLIENT_SECRET\|OLIST_REFRESH_TOKEN" .env

# Esperado:
# OLIST_CLIENT_ID=xxx
# OLIST_CLIENT_SECRET=xxx
# OLIST_REFRESH_TOKEN=eyJhbGc... (novo token aqui)
```

### Passo 3: Testar renovação
```bash
# Executar script de teste
php api/olist/refresh-token.php

# Resposta esperada:
# {
#   "status": "ok",
#   "message": "Token renovado com sucesso",
#   "token": "eyJhbGc...",
#   "access_token": "eyJhbGc...",
#   "expires_in": 3600
# }
```

### Passo 4: Commit + Push
```bash
# Daemon auto-sync fará isso automaticamente
git add .env
git commit -m "fix: renovar token Olist expirado"
git push origin main
```

---

## VERIFICAÇÃO PÓS-FIX

```bash
# 1. Verificar novo token em logs
tail -f logs/olist-sync.log

# 2. Criar novo pedido de teste
# 3. Verificar sincronização com Olist
# 4. Verificar orchestrator.log
tail -f logs/orchestrator.log | grep -i olist

# Esperado:
# [OK] Olist sync successful
# [OK] Order pushed to Tiny ERP
```

---

## GARANTIR RENOVAÇÃO CONTÍNUA

### Workflow Já Ativo ✓
- **Nome:** `.github/workflows/sync-olist-6h.yml`
- **Cronômetro:** `0 */2 * * *` (cada 2 horas)
- **Ação:** Renova tokens + sincroniza catálogo
- **Status:** ✓ ATIVO (vai disparar automaticamente)

### Verificação no GitHub
1. Ir a: https://github.com/Vivaliz-site/site-shopvivaliz/actions
2. Procurar por "Sincronizar Olist/Tiny"
3. Verificar se última execução foi bem-sucedida
4. Se houver erro, clicar em re-run

### Fallback Local (se workflow falhar)
Script `olist/sync-products.php` pode ser rodado manualmente:
```bash
php olist/sync-products.php
```

---

## SE FALHAR NOVAMENTE

### Problema: Script retorna 401
```
→ Significa que novo token também expirou ou é inválido
→ Tentar re-auth manual em dashboard Olist
→ Gerar novo token com permissões "refresh_token"
```

### Problema: Sem credenciais OLIST_CLIENT_ID
```
→ Adicionar ao .env:
OLIST_CLIENT_ID=seu-client-id
OLIST_CLIENT_SECRET=seu-client-secret

→ Obter em: https://www.olist.com.br/developer/settings
```

### Problema: Token renovado mas ainda falha
```
→ Verificar permissões do token (precisa de "refresh_token" scope)
→ Verificar se API Tiny/Olist está online
→ Verificar firewall/proxy (curl pode estar bloqueado)
```

---

## AUTOMAÇÃO FUTURA

Após token estar OK, adicionar monitoramento:

### Opção 1: Health Check com Alerta
```php
// Adicionar ao health check (5 min)
if (tempo_desde_ultima_renovacao > 2h) {
  ENVIAR ALERTA por email
}
```

### Opção 2: Token Expiration Monitoring
```bash
# Decodificar JWT e verificar "exp"
php scripts/check-token-expiration.php

# Se exp < 1 hora = ALERTA VERMELHO
```

### Opção 3: Webhook Olist (se disponível)
```bash
# Registrar webhook em dashboard Olist
# Quando token vai expirar, Olist avisa
```

---

## PRÓXIMOS PASSOS

1. ✅ Renovar token AGORA (15 min)
2. ✅ Testar sincronização (5 min)
3. ✅ Verificar workflow automático (5 min)
4. 🚀 Pronto para produção

---

## Checklist de Conclusão

- [ ] Token Olist renovado
- [ ] Script `refresh-token.php` retorna OK
- [ ] Novo pedido sincroniza com Olist
- [ ] Logs sem erro de autenticação
- [ ] Workflow `sync-olist-6h.yml` ativo
- [ ] Dashboard Olist mostra novos pedidos
- [ ] Email de confirmação enviado ao cliente
- ✅ PRONTO PARA PRODUÇÃO

---

**Tempo até Go-Live: ~15 min** ⏰
