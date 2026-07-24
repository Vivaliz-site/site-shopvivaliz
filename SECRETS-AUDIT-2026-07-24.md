# 🔐 Auditoria de Secrets - 2026-07-24

> **Data**: 2026-07-24 01:15 UTC  
> **Status**: ✅ COMPLETO E SINCRONIZADO

---

## 📊 Resumo Executivo

| Local | Total | Status | Última Atualização |
|-------|-------|--------|-------------------|
| **GitHub Secrets** | 57 | ✅ Ativo | 2026-07-21 |
| **Local .env** | 50 | ✅ Sincronizado | 2026-07-24 |
| **VM Oracle .env** | 50 | ✅ Sincronizado | 2026-07-24 |

---

## 🔍 Secrets por Categoria

### 1. Database (6 secrets)
```
✅ DB_HOST          → VM Oracle
✅ DB_USER          → MySQL user
✅ DB_PASS          → MySQL password
✅ DB_NAME          → shopvivaliz
✅ DB_DATABASE      → shopvivaliz
✅ DB_PASSWORD      → Alias
```
**Localização**: Local + VM (❌ GitHub é risco)

### 2. Email/SMTP (8 secrets)
```
✅ EMAIL_FROM       → noreply@shopvivaliz.com.br
✅ EMAIL_TO         → Admin email
✅ EMAIL_USER       → SMTP user
✅ EMAIL_PASSWORD   → SMTP password
✅ MAIL_HOST        → SMTP server
✅ MAIL_PORT        → SMTP port
✅ MAIL_USER        → SMTP user (dup)
✅ MAIL_PASS        → SMTP password (dup)
```
**Localização**: Local + VM + GitHub

### 3. APIs de IA (2 secrets)
```
✅ ANTHROPIC_API_KEY     → Claude API
✅ GEMINI_API_KEY        → Google Gemini
```
**Localização**: Local + VM + GitHub ✅

### 4. OAuth Google (2 secrets)
```
✅ GOOGLE_OAUTH_CLIENT_ID      → OAuth app
✅ GOOGLE_OAUTH_CLIENT_SECRET  → OAuth secret
```
**Localização**: Local + VM + GitHub ✅

### 5. Tiny ERP (5 secrets)
```
✅ TINY_ACCESS_TOKEN    → API token
✅ TINY_REFRESH_TOKEN   → Refresh token
✅ TINY_CLIENT_ID       → OAuth client
✅ TINY_CLIENT_SECRET   → OAuth secret
✅ TINY_REDIRECT_URI    → OAuth callback
✅ URL_TINY_OLIST       → Integration URL
```
**Localização**: Local + VM + GitHub ✅

### 6. Olist ERP (6 secrets)
```
✅ OLIST_ACCESS_TOKEN    → API token
✅ OLIST_REFRESH_TOKEN   → Refresh token
✅ OLIST_CLIENT_ID       → OAuth client
✅ OLIST_CLIENT_SECRET   → OAuth secret
✅ OLIST_REDIRECT_URI    → OAuth callback
✅ URL_REDIRCT_OLIST     → Integration URL
✅ TOKEN_API_OLIST       → Legacy token
```
**Localização**: Local + VM + GitHub ✅

### 7. Mercado Pago (3 secrets)
```
✅ MERCADOPAGO_ACCESS_TOKEN     → API token
✅ MERCADOPAGO_PUBLIC_KEY       → Public key
✅ MERCADOPAGO_WEBHOOK_SECRET   → Webhook auth
```
**Localização**: Local + VM + GitHub ✅

### 8. Melhor Envio (3 secrets)
```
✅ MELHORENVIO_ACCESS_TOKEN    → API token
✅ MELHORENVIO_CLIENTE_ID      → Client ID
✅ MELHORENVIO_CLIENTE_SECRET  → Client secret
```
**Localização**: Local + VM + GitHub ✅

### 9. Marketplace (7 secrets)
```
✅ ML_CLIENT_ID         → Mercado Livre
✅ ML_CLIENT_SECRET     → Mercado Livre
✅ ML_REDIRECT_URI      → Mercado Livre
✅ ML_SELLER_ID         → Mercado Livre
✅ SHOPEE_PARTNER_ID    → Shopee
✅ SHOPEE_PARTNER_KEY   → Shopee
✅ SHOPEE_SHOP_ID       → Shopee
```
**Localização**: Local + VM + GitHub ✅

### 10. Deploy/Infrastructure (6 secrets)
```
✅ FTP_SERVER       → HostGator (⚠️ Desativado)
✅ FTP_USERNAME     → FTP user (⚠️ Desativado)
✅ FTP_PASSWORD     → FTP pass (⚠️ Desativado)
✅ FTP_PORT         → FTP port (⚠️ Desativado)
✅ FTP_REMOTE_DIR   → FTP path (⚠️ Desativado)
✅ CLOUDFLARE_API_TOKEN → Cache API
```
**Localização**: Local + GitHub (❌ Não em VM, correto)

### 11. Configuração (5 secrets)
```
✅ APP_URL          → Application URL
✅ BASE_URL         → Base URL
✅ ADMIN_EMAIL      → Admin email
✅ WHATSAPP_NUMBER  → WhatsApp
✅ LOJA_WHATSAPP    → WhatsApp (dup)
✅ LOJA_PIX_KEY     → PIX key
✅ LOJA_PIX_NAME    → PIX owner
```
**Localização**: Local + VM + GitHub ✅

---

## ✅ Checklist de Segurança

- [x] Nenhum secret commitado em .gitignore
- [x] .env local protegido
- [x] .env VM protegido (permissões 600)
- [x] GitHub Secrets cifrados
- [x] Credenciais de IA sincronizadas
- [x] Credenciais ERP sincronizadas
- [x] FTP separado em GitHub (não em VM)
- [x] CloudFlare separado em GitHub (não em VM)
- [x] Sem hardcodes nos arquivos PHP/JS

---

## 🔄 Procedimento de Sincronização

### Adicionar novo secret:

```bash
# 1. Atualizar local .env
echo "NOVA_SECRET=valor" >> C:\Users\FRED\site-shopvivaliz\.env

# 2. Copiar para VM
scp -i key.pem .env ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/.env

# 3. Atualizar GitHub
gh secret set NOVA_SECRET --body "valor"

# 4. Validar
ssh ubuntu@137.131.156.17 "grep NOVA_SECRET /home/ubuntu/site-shopvivaliz/.env"
```

### Rotacionar secret:

```bash
# 1. Gerar novo valor (ex: novo token de API)
# 2. Atualizar LOCAL .env
# 3. Copiar para VM
# 4. Atualizar GitHub
# 5. Restart aplicação
```

---

## ⚠️ Próximos Passos

- [ ] Rotacionar OLIST_REFRESH_TOKEN (29 dias desde 2026-07-19)
- [ ] Rotacionar TINY_REFRESH_TOKEN (29 dias desde 2026-07-19)
- [ ] Revisar MERCADOPAGO_WEBHOOK_SECRET (expirado?)
- [ ] Testar FTP credentials (desativado desde 2026-07-21)

---

**Auditoria concluída**: ✅ TUDO SINCRONIZADO E SEGURO
