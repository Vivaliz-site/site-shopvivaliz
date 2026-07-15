# Segurança da Requisição de Pedido

## Objetivo

Evitar processamento duplicado, abuso de endpoint e divergência entre o corpo validado e o corpo usado na criação do pedido.

## Controles

- O corpo da requisição é lido e validado uma única vez pelo endpoint de validação.
- O contexto validado é armazenado por meio de `includes/order-request-context.php`.
- A chave de idempotência bloqueia reenvios do mesmo pedido por 15 minutos.
- O rate limit restringe tentativas repetidas do mesmo cliente.
- Itens, preços, quantidades, estoque e frete continuam sendo validados antes da criação.

## Chave de idempotência

O cliente pode enviar `idempotency_key`. Quando ausente, o servidor calcula uma chave a partir de cliente, CEP, itens e cotação de frete.

Erros relacionados:

- `duplicate_order_request`
- `rate_limit_exceeded`

## Health check

`GET /api/orders/security-health.php`

O endpoint deve retornar:

```json
{
  "ok": true,
  "endpoint": "orders-security",
  "checks": {}
}
```

## Validação local

```bash
php scripts/quality/validate-order-request-security.php
bash tests/storefront-smoke.sh
```

O health check confirma a presença dos controles, mas não substitui testes reais de concorrência e criação de pedido em ambiente de homologação.
