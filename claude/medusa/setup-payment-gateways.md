# 💳 Configuração de Gateways de Pagamento

## Gateways Suportados

- ✅ Stripe (Cartão de Crédito)
- ✅ PayPal
- ✅ Boleto (Pagar.me/Braspag)
- ✅ PIX (Automático)
- ✅ 2Checkout

## 1. Stripe (Cartão de Crédito)

### Obter Credenciais
1. Abrir: https://dashboard.stripe.com/
2. Settings → API Keys
3. Copiar: Publishable Key + Secret Key

### Configurar no Medusa

**.env (backend):**
```
STRIPE_API_KEY=sk_live_...
STRIPE_PUBLIC_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

**npm install:**
```bash
npm install @medusajs/payment-stripe
```

**Registrar no medusa-config.ts:**
```typescript
import {
  StripeBase,
  StripeProviders,
} from "@medusajs/payment-stripe"

const plugins = [
  {
    resolve: "@medusajs/payment-stripe",
    options: {
      apiKey: process.env.STRIPE_API_KEY,
      webhook_secret: process.env.STRIPE_WEBHOOK_SECRET,
    },
  },
]
```

---

## 2. PayPal

### Obter Credenciais
1. Abrir: https://developer.paypal.com/
2. Apps & Credentials
3. Copiar: Client ID + Secret

### Configurar no Medusa

**.env:**
```
PAYPAL_CLIENT_ID=...
PAYPAL_CLIENT_SECRET=...
PAYPAL_WEBHOOK_ID=...
```

**npm install:**
```bash
npm install @medusajs/payment-paypal
```

---

## 3. Boleto (Pagar.me)

### Obter Credenciais
1. Abrir: https://dashboard.pagar.me/
2. Configurações → Chaves de API
3. Copiar: Chave pública + Chave privada

### Configurar no Medusa

**.env:**
```
PAGARME_API_KEY=...
PAGARME_PUBLIC_KEY=...
```

---

## 4. PIX (Automático)

**.env:**
```
PIX_ENABLED=true
PIX_BANK_CODE=...
PIX_ACCOUNT_HOLDER=...
```

---

## 5. 2Checkout

### Obter Credenciais
1. Abrir: https://account.2checkout.com/
2. Integration → API
3. Copiar credenciais

---

## Buscar Credenciais do Projeto Anterior

Se você tem outro projeto com essas credenciais:

### Procurar em:
- `.env` files
- `config/` directories
- `secrets/` files
- Environment variables em CI/CD
- Documentação privada
- Arquivo de senhas (se houver)

### Arquivos a Verificar:
```
- .env
- .env.production
- .env.local
- config/payment.php
- config/gateways.json
- secrets.json
- credentials.yml
```

---

## Registrar Webhooks

### Stripe
```
POST /admin/webhooks

{
  "event": "payment.captured",
  "url": "https://seu-dominio.com/webhooks/stripe"
}
```

### PayPal
Configurar em: https://developer.paypal.com/webhooks

URL: `https://seu-dominio.com/webhooks/paypal`

---

## Testar Gateway Localmente

```bash
# Stripe test keys (començam com pk_test_ e sk_test_)
STRIPE_API_KEY=sk_test_123...
STRIPE_PUBLIC_KEY=pk_test_456...

# Testar transação:
npm run test:payment
```

---

## Status dos Gateways

| Gateway | Status | Prioridade |
|---------|--------|-----------|
| Stripe | ⏳ Pronto | 🔴 Alta |
| PayPal | ⏳ Pronto | 🟡 Média |
| Boleto | ⏳ Pronto | 🟡 Média |
| PIX | ⏳ Pronto | 🟢 Baixa |

---

## Próximos Passos

1. ✅ Reunir credenciais de projeto anterior
2. ⏳ Instalar pacotes Medusa de cada gateway
3. ⏳ Configurar .env
4. ⏳ Registrar webhooks
5. ⏳ Testar com transações de teste
6. ⏳ Deploy para produção

---

## Suporte

Se precisar de credenciais do projeto anterior:
- Verificar GitHub Secrets
- Verificar CI/CD logs
- Contatar administrador/dev que tinha acesso
- Usar contas de teste se as reais não estiverem disponíveis

Para contas de teste (recomendado começar com essas):
- Stripe: https://stripe.com/docs/testing
- PayPal: https://developer.paypal.com/docs/platforms/get-started/
