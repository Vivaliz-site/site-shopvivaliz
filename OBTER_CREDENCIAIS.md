# 🔐 Como Obter Credenciais de Integração

## 1. Credenciais de Marketplace (Olist/Shopee/Amazon)

### Olist
1. Ir para: https://www.olist.com.br/
2. Conta → Integrações
3. OAuth: Copiar `client_id` e `client_secret`

**Salvar em .env:**
```
OLIST_CLIENT_ID=...
OLIST_CLIENT_SECRET=...
```

### Shopee
1. Ir para: https://partner.shopeemec.com/
2. Developer Console
3. Create App
4. Copiar `partner_id` e `partner_key`

**Salvar em .env:**
```
SHOPEE_API_KEY=...
SHOPEE_API_SECRET=...
SHOPEE_SHOP_ID=...
```

### Amazon
1. Ir para: https://sellercentral-na.amazon.com/
2. Settings → User Permissions
3. Developer Central → Create app
4. Copiar Access Key + Secret Key

**Salvar em .env:**
```
AMAZON_ACCESS_KEY=...
AMAZON_SECRET_KEY=...
```

### Tiny ERP (para sincronização)
1. Ir para: https://www.tiny.com.br/
2. Configurações → Integração
3. Gerar Token API

**Salvar em .env:**
```
TINY_TOKEN=...
```

---

## 2. Gateways de Pagamento

### Stripe
1. https://dashboard.stripe.com/
2. Developers → API keys
3. Copiar: `pk_live_...` e `sk_live_...`

### PayPal
1. https://developer.paypal.com/
2. Apps & Credentials
3. Copiar: `client_id` e `secret`

### Pagar.me (Boleto)
1. https://dashboard.pagar.me/
2. Configurações → Chaves de API
3. Copiar chaves

---

## 3. GitHub Secrets

Se as credenciais estão em outro projeto, buscar em:

**URL:** https://github.com/{usuario}/{repo}/settings/secrets/actions

Secrets que procurar:
- `OLIST_CLIENT_ID`
- `OLIST_CLIENT_SECRET`
- `SHOPEE_API_KEY`
- `SHOPEE_API_SECRET`
- `AMAZON_ACCESS_KEY`
- `AMAZON_SECRET_KEY`
- `STRIPE_API_KEY`
- `STRIPE_PUBLIC_KEY`
- `PAYPAL_CLIENT_ID`
- `PAYPAL_CLIENT_SECRET`
- `TINY_TOKEN`

---

## 4. Configurar no Medusa

### Arquivo .env (backend)

```bash
cd claude/medusa/apps/backend

# Copiar template
cp .env.example .env

# Editar .env com credenciais
nano .env
# ou
code .env
```

### Variáveis necessárias

```env
# Database
DATABASE_URL=postgresql://...

# Marketplaces
OLIST_CLIENT_ID=
OLIST_CLIENT_SECRET=
SHOPEE_API_KEY=
SHOPEE_API_SECRET=
AMAZON_ACCESS_KEY=
AMAZON_SECRET_KEY=
TINY_TOKEN=

# Pagamentos
STRIPE_API_KEY=
STRIPE_PUBLIC_KEY=
PAYPAL_CLIENT_ID=
PAYPAL_CLIENT_SECRET=

# EHA
EHA_WEBHOOK_SECRET=
MEDUSA_WEBHOOK_URL=
```

---

## 5. Sincronização Automática

Depois de configurar credenciais:

```bash
# Testar sincronização Olist
php claude/api/sync-olist-products.php

# Testar sincronização Shopee
php claude/api/sync-shopee-products.php

# Testar sincronização Amazon
php claude/api/sync-amazon-products.php
```

---

## 6. Segurança

⚠️ **NUNCA**:
- Commitar `.env` com credenciais reais
- Compartilhar credenciais em mensagens
- Usar credenciais em logs públicos

✅ **SEMPRE**:
- Usar `.env.example` como template (vazio)
- Manter credenciais reais localmente
- Usar GitHub Secrets em CI/CD
- Rotacionar chaves periodicamente

---

## 7. Próximos Passos

1. ✅ Obter credenciais
2. ✅ Preencher .env
3. ✅ Testar sincronizações
4. ✅ Configurar webhooks
5. ✅ Deploy para produção

---

## Suporte

Se não conseguir encontrar credenciais do projeto anterior:

1. Verificar GitHub histórico de commits
2. Pedir para dev/admin anterior
3. Criar novas contas de teste
4. Usar contas sandbox de cada serviço

Contas sandbox:
- Stripe: `pk_test_...` e `sk_test_...`
- PayPal: Sandbox Merchant Account
- Shopee: Dev Shop
