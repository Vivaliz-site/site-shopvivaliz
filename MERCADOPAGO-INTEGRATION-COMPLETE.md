# Integração Completa Mercado Pago - ShopVivaliz

**Status:** ✅ IMPLEMENTAÇÃO COMPLETA SERVER-SIDE + CLIENT-SIDE  
**Data:** 2026-07-14  
**Documentação:** 
- Server: https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side
- Client: https://www.mercadopago.com.br/developers/pt/docs/sdks-library/client-side/mp-js-v2
- API: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders

---

## 📦 Arquivos Implementados

### 1. **`composer.json`**
- Dependências do projeto
- Inclui `mercadopago/sdk: ^2.0`

### 2. **`api/mercadopago-orders-sdk.php`** (Server-side)
- Cria Order via SDK Official
- Usa `MercadoPago\Client\Order\OrderClient`
- Método: POST
- Retorna Order ID válido do MP

### 3. **`api/process-payment.php`** (Server-side)
- Processa pagamento via API
- Usa `MercadoPago\Client\Payment\PaymentClient`
- Recebe token do Payment Brick
- Retorna Payment ID

### 4. **`includes/mercadopago-checkout-js.php`** (Client-side)
- Carrega MP.js v2
- Renderiza Payment Brick
- Fluxo: Order → Payment → Webhook
- Método: Inclua no checkout.php

---

## 🚀 Fluxo Completo de Pagamento

### Cliente-side (Navegador)

```javascript
1. Usuario preenche checkout
   ↓
2. Clica "Finalizar Pagamento"
   ↓
3. initializePaymentFlow()
   ├─ Coleta dados (nome, email, itens, total)
   ├─ Chama /api/mercadopago-orders-sdk.php
   │  → Cria Order no Mercado Pago
   │  → Recebe Order ID
   └─ Renderiza Payment Brick
   ↓
4. Usuario preenche formulário de pagamento
   ├─ Número do cartão (ou boleto, etc)
   ├─ Validade, CVV
   └─ Clica "Pagar"
   ↓
5. Payment Brick envia para /api/process-payment.php
   ├─ Recebe token seguro
   ├─ Chama Mercado Pago Payment API
   └─ Retorna Payment ID
   ↓
6. Confirmação ao cliente
   └─ "Pagamento processado com sucesso!"
```

### Server-side (PHP)

```
POST /api/mercadopago-orders-sdk.php
  ├─ MercadoPagoConfig::setAccessToken()
  ├─ OrderClient::create()
  └─ Retorna: { order_id, status }

POST /api/process-payment.php
  ├─ MercadoPagoConfig::setAccessToken()
  ├─ PaymentClient::create()
  ├─ Salva Payment ID no BD
  └─ Retorna: { payment_id, status }
```

---

## 📋 Requisitos

### Instalação

```bash
cd /home/ubuntu/site-shopvivaliz
composer install
```

### Configuração

No `.env`:
```
MERCADOPAGO_ACCESS_TOKEN=<seu-token>
MERCADOPAGO_PUBLIC_KEY=<sua-public-key>
```

---

## 🎯 Integração no Checkout

No seu `checkout/index.php`, adicione:

```html
<!-- Incluir JavaScript do Mercado Pago -->
<?php require_once __DIR__ . '/../includes/mercadopago-checkout-js.php'; ?>

<!-- Adicionar este botão ao invés de "Confirmar pedido" -->
<button type="button" class="primary-btn" onclick="initializePaymentFlow()">
  Finalizar Pagamento
</button>

<!-- Container onde o Payment Brick será renderizado -->
<div id="paymentBrick_container"></div>

<!-- Mensagens de erro/sucesso -->
<div id="payment-messages"></div>

<!-- Campos ocultos que serão passados -->
<input type="hidden" id="pedido-id" value="PED-...">
<input type="hidden" id="order-total" value="76.00">
<input type="hidden" id="cart-items" value='[{...}]'>
```

---

## ✅ Verificação

### 1. Teste de Order Creation

```bash
curl -X POST https://dev.shopvivaliz.com.br/api/mercadopago-orders-sdk.php \
  -H "Content-Type: application/json" \
  -d '{
    "external_reference": "PED-20260714213526",
    "total_amount": 76.00,
    "items": [{
      "sku_number": "RODIZIO-75MM",
      "title": "Rodízio 75mm",
      "unit_price": 76.00,
      "quantity": 1
    }],
    "payer": {"email": "test@test.com"}
  }'
```

Resposta esperada:
```json
{
  "success": true,
  "order_id": "ORDER-ID-VALIDO",
  "status": "pending"
}
```

### 2. Teste de Payment Processing

No navegador, abra o console (F12) e rode:
```javascript
initializePaymentFlow()
```

Verifique:
- ✅ Order criada
- ✅ Payment Brick renderizado
- ✅ Sem erros no console

---

## 🔐 Segurança

**IMPORTANTE:**
- ✅ Access Token NUNCA em client-side
- ✅ Public Key OK em client-side
- ✅ Tokens em variáveis de ambiente (.env)
- ✅ Comunicação via HTTPS
- ✅ SDK valida automaticamente

---

## 🛠️ Troubleshooting

### Erro: "vendor/autoload.php not found"
```bash
composer install
```

### Erro: "Order creation failed"
- Verificar Access Token no .env
- Verificar total_amount > 0
- Verificar items array não vazio

### Erro: "Payment Brick não renderiza"
- Verificar Public Key no .env
- Verificar que MP.js v2 carregou (console)
- Verificar que #paymentBrick_container existe no HTML

---

## 📚 Documentação Oficial

- [SDK PHP](https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side)
- [MP.js v2](https://www.mercadopago.com.br/developers/pt/docs/sdks-library/client-side/mp-js-v2)
- [Payment Brick](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/payment-brick)
- [Orders API](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders)

---

**Status:** ✅ Pronto para Produção
