# 🔐 VERIFICAÇÃO DE SECRETS - 2026-07-24

**Status:** ✅ AUDITORIA CONCLUÍDA  
**Data:** 2026-07-24  
**Ambiente:** Local .env

---

## 📊 Resumo

| Categoria | Total Esperado | Presente | Faltando | Status |
|-----------|----------------|----------|----------|--------|
| Database | 6 | 4 | 2 | ⚠️ INCOMPLETO |
| Email/SMTP | 8 | 6 | 2 | ⚠️ INCOMPLETO |
| APIs IA | 3 | 2 | 1 | ⚠️ INCOMPLETO |
| OAuth Google | 2 | 2 | 0 | ✅ COMPLETO |
| Tiny ERP | 5 | 5 | 0 | ✅ COMPLETO |
| Olist ERP | 7 | 7 | 0 | ✅ COMPLETO |
| Mercado Pago | 3 | 3 | 0 | ✅ COMPLETO |
| Melhor Envio | 3 | 3 | 0 | ✅ COMPLETO |
| Marketplace (ML/Shopee) | 7 | 7 | 0 | ✅ COMPLETO |
| Deploy/FTP | 5 | 0 | 5 | ❌ NÃO APLICÁVEL (VM-only) |
| Configuração | 7 | 5 | 2 | ⚠️ INCOMPLETO |
| **TOTAL** | **57** | **45** | **12** | **🟡  79%** |

---

## ❌ SECRETS FALTANDO NO .env LOCAL

### 1️⃣ Database (2 faltando)

| Secret | Status | Notas |
|--------|--------|-------|
| DB_HOST | ✅ Presente | localhost |
| DB_USER | ✅ Presente | shopvivaliz |
| DB_PASS | ✅ Presente | shopvivaliz123 |
| DB_NAME | ✅ Presente | shopvivaliz |
| **DB_DATABASE** | ❌ **FALTANDO** | Alias para DB_NAME (opcional) |
| **DB_PASSWORD** | ❌ **FALTANDO** | Alias para DB_PASS (opcional) |

**Ação:** Adicionar (opcional - são aliases):
```bash
DB_DATABASE=shopvivaliz
DB_PASSWORD=shopvivaliz123
```

### 2️⃣ Email/SMTP (2 faltando)

| Secret | Status | Notas |
|--------|--------|-------|
| EMAIL_FROM | ✅ Presente | shopvivaliz@gmail.com |
| EMAIL_TO | ✅ Presente | fredmourao@gmail.com,atendimento@shopvivaliz.com.br |
| **EMAIL_USER** | ❌ **FALTANDO** | Alias para MAIL_USER |
| **EMAIL_PASSWORD** | ❌ **FALTANDO** | Alias para MAIL_PASS |
| MAIL_HOST | ✅ Presente | smtp.gmail.com |
| MAIL_PORT | ✅ Presente | 587 |
| MAIL_USER | ✅ Presente | shopvivaliz@gmail.com |
| MAIL_PASS | ✅ Presente | ukts yplc vtij jjpx |

**Ação:** Adicionar (opcional - são aliases):
```bash
EMAIL_USER=shopvivaliz@gmail.com
EMAIL_PASSWORD=ukts yplc vtij jjpx
```

### 3️⃣ APIs IA (1 faltando)

| Secret | Status | Notas |
|--------|--------|-------|
| ANTHROPIC_API_KEY | ❌ **FALTANDO** | Claude API (CRÍTICO) |
| GEMINI_API_KEY | ✅ Presente | Google Gemini |
| GOOGLE_GEMINI_API_KEY | ✅ Presente | Alias para GEMINI_API_KEY |

**Ação:** ⚠️ **ADICIONAR URGENTE** (necessário para automações IA):
```bash
ANTHROPIC_API_KEY=sk_live_[sua_chave_aqui]
```

### 4️⃣ Deploy/FTP (5 não aplicável a LOCAL)

| Secret | Local | VM | GitHub | Notas |
|--------|-------|-----|--------|-------|
| FTP_SERVER | ❌ Não em local | ❌ | ✅ | GitHub-only |
| FTP_USERNAME | ❌ Não em local | ❌ | ✅ | GitHub-only |
| FTP_PASSWORD | ❌ Não em local | ❌ | ✅ | GitHub-only |
| FTP_PORT | ❌ Não em local | ❌ | ✅ | GitHub-only |
| FTP_REMOTE_DIR | ❌ Não em local | ❌ | ✅ | GitHub-only |

**Status:** ✅ Correto - Deploy está na VM Oracle (não FTP)

### 5️⃣ Configuração (2 faltando)

| Secret | Status | Notas |
|--------|--------|-------|
| APP_URL | ❌ **FALTANDO** | Application URL |
| BASE_URL | ❌ **FALTANDO** | Base URL |
| ADMIN_EMAIL | ❌ **Necessário?** | Não encontrado |
| WHATSAPP_NUMBER | ❌ **Necessário?** | Não encontrado |
| LOJA_WHATSAPP | ❌ **Necessário?** | Não encontrado |
| LOJA_PIX_KEY | ❌ **Necessário?** | Não encontrado |
| LOJA_PIX_NAME | ❌ **Necessário?** | Não encontrado |

**Ação:** Adicionar (se aplicável):
```bash
APP_URL=https://dev.shopvivaliz.com.br
BASE_URL=https://dev.shopvivaliz.com.br
```

---

## ✅ SECRETS PRESENTES E CORRETOS

**Categoria: OAuth Google**
- ✅ GOOGLE_OAUTH_CLIENT_ID
- ✅ GOOGLE_OAUTH_CLIENT_SECRET

**Categoria: Tiny ERP**
- ✅ TINY_ACCESS_TOKEN
- ✅ TINY_REFRESH_TOKEN
- ✅ TINY_CLIENT_ID (indireto via Olist)
- ✅ TINY_CLIENT_SECRET (indireto via Olist)
- ✅ TINY_REDIRECT_URI (indireto)
- ✅ URL_TINY_OLIST

**Categoria: Olist ERP**
- ✅ OLIST_ACCESS_TOKEN
- ✅ OLIST_REFRESH_TOKEN
- ✅ OLIST_CLIENT_ID
- ✅ OLIST_CLIENT_SECRET
- ✅ OLIST_INTEGRADOR_ID
- ✅ OLIST_INTEGRADOR_TOKEN
- ✅ OLIST_WEBHOOK_TOKEN
- ✅ URL_REDIRCT_OLIST (typo: REDIRCT em vez de REDIRECT)

**Categoria: Mercado Pago**
- ✅ MERCADOPAGO_ACCESS_TOKEN
- ✅ MERCADOPAGO_PUBLIC_KEY
- ✅ MERCADOPAGO_WEBHOOK_SECRET

**Categoria: Melhor Envio**
- ✅ MELHORENVIO_ACCESS_TOKEN
- ✅ MELHORENVIO_CLIENTE_ID
- ✅ MELHORENVIO_CLIENTE_SECRET

**Categoria: Marketplace (Mercado Livre + Shopee)**
- ✅ ML_CLIENT_ID
- ✅ ML_CLIENT_SECRET
- ✅ ML_REDIRECT_URI
- ✅ SHOPEE_PARTNER_ID
- ✅ SHOPEE_PARTNER_KEY
- ✅ SHOPEE_REFRESH_TOKEN
- ✅ SHOPEE_ACCESS_TOKEN
- ✅ SHOPEE_SHOP_ID

**Categoria: Outros**
- ✅ QUOTE_SIGNING_KEY
- ✅ SMTP_FROM, SMTP_FROMNAME, SMTP_HOST, SMTP_PASS, SMTP_PORT, SMTP_USER
- ✅ SQUAD_GEMINI_MODEL

---

## 🔴 AÇÕES OBRIGATÓRIAS

### 1. ADICIONAR ANTHROPIC_API_KEY (CRÍTICO)

```bash
# Abrir .env
vi C:\Users\FRED\site-shopvivaliz\.env

# Adicionar:
ANTHROPIC_API_KEY=sk_live_[sua_chave_aqui]
```

**Por quê:** Necessário para automações Claude em workflows

### 2. ADICIONAR APP_URL E BASE_URL (RECOMENDADO)

```bash
APP_URL=https://dev.shopvivaliz.com.br
BASE_URL=https://dev.shopvivaliz.com.br
```

**Por quê:** Usados em scripts e validações de URL

### 3. ADICIONAR ALIASES OPCIONAIS (OPCIONAL)

```bash
# Aliases de database
DB_DATABASE=shopvivaliz
DB_PASSWORD=shopvivaliz123

# Aliases de email
EMAIL_USER=shopvivaliz@gmail.com
EMAIL_PASSWORD=ukts yplc vtij jjpx
```

---

## 🔄 Sincronização Obrigatória Após Alterações

**Quando adicionar novos secrets, sincronizar em 3 ambientes:**

```powershell
# 1. Editar Local
vi .env

# 2. Copiar para VM
scp -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" .env ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/.env

# 3. Atualizar GitHub Secrets (via web ou CLI)
gh secret set ANTHROPIC_API_KEY --body "sk_live_..."

# 4. Validar em 3 locais
grep ANTHROPIC_API_KEY .env
ssh ubuntu@137.131.156.17 "grep ANTHROPIC_API_KEY /home/ubuntu/site-shopvivaliz/.env"
gh secret list --repo Vivaliz-site/site-shopvivaliz | grep ANTHROPIC
```

---

## 📋 Verificação por Ambiente

### Local (C:\Users\FRED\site-shopvivaliz\.env)
```
Total secrets: 50
Críticos: ANTHROPIC_API_KEY (FALTANDO ⚠️)
Status: 🟡 79% completo
```

### VM Oracle (/home/ubuntu/site-shopvivaliz/.env)
```
Status: Sincronizado com Local (após edições)
Críticos: Qualquer mudança local deve ser copiada
```

### GitHub Secrets
```
Total: 57 (conforme auditoria anterior)
Status: Alguns secrets são GitHub-only (Deploy/FTP)
Críticos: ANTHROPIC_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY
```

---

## 🎯 Próximas Ações

**HOJE:**
1. ✅ Adicionar ANTHROPIC_API_KEY ao .env local
2. ✅ Adicionar APP_URL e BASE_URL
3. ✅ Sincronizar para VM + GitHub

**OPCIONAIS (esta semana):**
- Adicionar aliases DB_DATABASE e DB_PASSWORD
- Adicionar aliases EMAIL_USER e EMAIL_PASSWORD
- Adicionar ADMIN_EMAIL, WHATSAPP_NUMBER, PIX keys (se usar)

---

**Status:** ⚠️ BLOQUEANTE (ANTHROPIC_API_KEY necessário)  
**Último Update:** 2026-07-24  
**Próxima Revisão:** 2026-08-07
