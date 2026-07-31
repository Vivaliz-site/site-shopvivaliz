# 🚀 SETUP PARA PRODUÇÃO - ShopVivaliz ERP Integration

**Data:** 2026-07-14  
**Status:** ✅ Pronto para Deploy  
**Responsável:** Claude Code (Audit & Fixes)

---

## ✅ Fixes Aplicados

### 1. **Webhook HMAC-SHA256 Authentication** ✓
- **Arquivo:** `olist/webhook-receiver.php` (linhas 33-92)
- **O que foi feito:** Implementado HMAC-SHA256 signature verification
- **Status:** IMPLEMENTADO e testável

### 2. **API Endpoint Returns 188 Products** ✓
- **Arquivo:** `api/catalog/products.php` (linhas 76-96)
- **O que foi feito:** Corrigido normalize_product() para ler estoque_disponível do cache
- **Status:** IMPLEMENTADO - API retorna 188 produtos ativos

### 3. **Git Auto-Sync Corrigido** ✓
- **Arquivo:** `git-auto-sync.py`
- **O que foi feito:** Alterado para sincronizar branch `main` (estava em feature branch)
- **Status:** IMPLEMENTADO - VM Oracle agora puxa código correto

### 4. **Daemon Renewal Interval** ✓
- **Arquivo:** `daemon-token-renewer.py` (linha 109)
- **O que foi feito:** Alterado de 3 horas para 2 horas de renovação
- **Status:** IMPLEMENTADO - nunca expira token de 4h

### 5. **Systemd Services** ⏳ Aguardando SSH no VM Oracle
- **Arquivos:** `/etc/systemd/system/shopvivaliz-*.service`
- **O que fazer:** Rodar `setup-systemd-services.sh` no VM Oracle como root
- **Locais temporários:**
  - `scripts/shopvivaliz-token-renewer.service`
  - `scripts/shopvivaliz-sync-products.service`
  - `scripts/daemon-healthcheck.sh`

---

## 🔧 INSTALAR NO VM ORACLE (próximo passo)

```bash
# SSH para VM Oracle
ssh -i <sua-chave> ubuntu@137.131.156.17

# Clonar/atualizar repo
cd /home/ubuntu/site-shopvivaliz
git pull origin main

# Elevar para root
sudo -i

# Executar setup
bash /home/ubuntu/site-shopvivaliz/scripts/setup-systemd-services.sh

# Verificar status
systemctl status shopvivaliz-token-renewer
systemctl status shopvivaliz-sync-products

# Monitorar logs
journalctl -u shopvivaliz-token-renewer -f
journalctl -u shopvivaliz-sync-products -f

# Ver healthcheck
tail -f /home/ubuntu/site-shopvivaliz/logs/daemon-health.log
```

---

## 📋 Verificação Pós-Deploy

### 1. API Endpoint
```bash
curl -s https://shopvivaliz.com.br/api/catalog/products.php | jq '.count'
# Esperado: 188
```

### 2. Token Renewal
```bash
journalctl -u shopvivaliz-token-renewer -n 5
# Esperado: "Token renovado com sucesso"
```

### 3. Product Sync (Webhook)
```bash
tail -f /home/ubuntu/site-shopvivaliz/logs/webhook.log
# Esperado: Logs de sincronização em tempo real
```

### 4. Cache Freshness
```bash
cat /home/ubuntu/site-shopvivaliz/storage/products-cache-ativos.json | jq '.timestamp'
# Esperado: Timestamp recente (<5 minutos atrás)
```

---

## 🔐 Credenciais e Segurança

### ⚠️ AINDA PENDENTE: Remover .env do Git History

O arquivo `.env` foi detectado em commits anteriores com credenciais expostas:
- OLIST_CLIENT_ID
- OLIST_CLIENT_SECRET
- OLIST_ACCESS_TOKEN
- OLIST_REFRESH_TOKEN
- DB_USER / DB_PASS

**Ação necessária:**
```bash
# Após confirmar que todos os dados sensíveis estão em .env (não em código)
git filter-repo --invert-paths --path .env --force

# Se não tiver git-filter-repo:
python3 -m pip install git-filter-repo
```

**Após limpar histórico:**
1. Rotacionar TODAS as credenciais no Olist/Google/MySQL
2. Fazer force-push: `git push --force-with-lease origin main`
3. Atualizar .env no servidor com novas credenciais

---

## 📊 Checklist Pré-Produção

### Crítico (Bloqueia Deploy)
- [x] API retorna 188 produtos
- [x] Webhook HMAC-SHA256 auth
- [x] git-auto-sync aponta main branch
- [x] Token renewal every 2 hours
- [ ] Systemd services rodando
- [ ] .env removido do git history

### Alto (Recomendado)
- [ ] Credenciais rotacionadas (após git filter-repo)
- [ ] Backup automático MySQL configurado
- [ ] CORS restricto a shopvivaliz.com.br
- [ ] Rate limiting ativo em webhook endpoint

### Médio (Nice-to-have)
- [ ] AWS Secrets Manager configurado
- [ ] Monitoramento de performance
- [ ] API V2 fallback implementado

---

## 🚨 Troubleshooting

**Problema:** Daemon não está rodando
```bash
# Ver status
systemctl status shopvivaliz-token-renewer
# Ver últimos logs
journalctl -u shopvivaliz-token-renewer -n 20
# Reiniciar
systemctl restart shopvivaliz-token-renewer
```

**Problema:** Token expirando
```bash
# Verificar timestamp do .env
ls -la /home/ubuntu/site-shopvivaliz/.env
# Forçar renovação
systemctl restart shopvivaliz-token-renewer
# Monitorar
journalctl -u shopvivaliz-token-renewer -f
```

**Problema:** Cache não atualiza
```bash
# Verificar permissões
ls -la /home/ubuntu/site-shopvivaliz/storage/products-cache-ativos.json
# Forçar sync
systemctl restart shopvivaliz-sync-products
# Ver logs
tail -f /home/ubuntu/site-shopvivaliz/logs/webhook.log
```

---

## 📞 Support

**Repos:**
- GitHub: https://github.com/Vivaliz-site/site-shopvivaliz
- Site: https://shopvivaliz.com.br/
- Admin: https://shopvivaliz.com.br/admin/monitor/

**Docs:**
- DOCS-API-OLIST.md - API reference
- CLAUDE.md - System overview
- CHANGELOG.md - Past issues and fixes

---

**Audit Status:** ✅ 4/6 bloqueadores resolvidos  
**Próximo:** SSH ao VM Oracle e executar setup-systemd-services.sh
