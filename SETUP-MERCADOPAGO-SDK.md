# Setup Mercado Pago SDK - ShopVivaliz

## Status: ✅ IMPLEMENTADO COM SDK OFFICIAL

**Data:** 2026-07-14  
**Versão SDK:** 2.0+  
**Documentação:** https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side

---

## 🚀 Instalação (VM Production)

### Passo 1: Instalar SDK via Composer

```bash
cd /home/ubuntu/site-shopvivaliz
composer install
```

Isso vai instalar:
- `mercadopago/sdk` (versão 2.0+)
- Todas as dependências automáticamente

### Passo 2: Verificar Instalação

```bash
ls -la vendor/autoload.php
php api/mercadopago-orders-sdk.php
```

---

## 📋 Integração Implementada

### Endpoint
- **URL:** `/api/mercadopago-orders-sdk.php`
- **Método:** POST
- **Content-Type:** application/json

### Requisição

```json
{
  "external_reference": "PED-20260714213526",
  "total_amount": 76.00,
  "items": [
    {
      "sku_number": "RODIZIO-75MM",
      "category": "Rodízios",
      "title": "Rodízio 75mm",
      "description": "Rodízio 75mm",
      "unit_price": 76.00,
      "quantity": 1
    }
  ],
  "payer": {
    "email": "cliente@test.com",
    "first_name": "Cliente",
    "last_name": "Teste",
    "phone": "(37) 99999-1234"
  }
}
```

### Resposta (Sucesso)

```json
{
  "success": true,
  "order_id": "ORDER-ID-GERADO-PELO-MP",
  "external_reference": "PED-20260714213526",
  "total_amount": 76.00,
  "status": "pending"
}
```

---

## ✅ Fluxo de Funcionamento

1. **Checkout**
   - Usuario preenche formulário
   - Clica "Confirmar pedido"

2. **Sistema**
   - Gera PED-YYYYMMDDHHMMSS
   - Chama `/api/mercadopago-orders-sdk.php`
   - Passa dados do pedido

3. **SDK Mercado Pago**
   - Valida dados (MercadoPagoConfig)
   - Cria order via `OrderClient`
   - Retorna Order ID válido

4. **Resultado**
   - Order ID salvo no BD
   - Cliente recebe confirmação
   - Pode autenticar no MP Developers

---

## 🔍 Verificação

### Teste Local (Desenvolvimento)

Se houver Composer instalado:

```bash
composer install
php api/mercadopago-orders-sdk.php
```

### Teste em Produção

Na VM Oracle:

```bash
ssh ubuntu@137.131.156.17
cd /home/ubuntu/site-shopvivaliz
composer install
curl -X POST http://localhost/api/mercadopago-orders-sdk.php \
  -H "Content-Type: application/json" \
  -d '{...}'
```

---

## 🛠️ Troubleshooting

### Erro: "vendor/autoload.php not found"
```bash
composer install
```

### Erro: "Access token not configured"
- Verificar `.env` possui `MERCADOPAGO_ACCESS_TOKEN`
- Verificar permissões do arquivo `.env`

### Erro: "Missing required fields"
- Verificar `external_reference` não está vazio
- Verificar `total_amount` > 0
- Verificar `items` array não está vazio

---

## 📚 Documentação Oficial

- [Mercado Pago SDK PHP](https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side)
- [Orders API](https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders)
- [GitHub SDK](https://github.com/mercadopago/sdk-php)

---

**Status:** ✅ Pronto para Produção
