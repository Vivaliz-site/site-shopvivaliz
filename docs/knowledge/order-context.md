# Contexto Validado do Pedido

## Regra principal

O corpo da requisição deve ser lido uma única vez. Depois da validação, somente o contexto autoritativo pode ser usado para gravar o pedido.

## Fluxo

1. `POST /api/orders/create.php` encaminha para `create-validated.php`.
2. `create-validated.php` lê `php://input` uma única vez.
3. Produtos, preços, estoque, frete, assinatura, rate limit e idempotência são validados.
4. O corpo e os itens resolvidos são armazenados em `order-request-context.php`.
5. `process-validated.php` usa somente `svorc_body()` e `svorc_items()`.
6. O processador não volta a ler o corpo HTTP nem confia nos preços enviados pelo navegador.

## Endpoints

- `/api/orders/create.php` — entrada pública única.
- `/api/orders/create-validated.php` — validação autoritativa.
- `/api/orders/process-validated.php` — gravação usando contexto validado.
- `/api/orders/context-health.php` — confirma leitura única e encadeamento correto.

## Erros

- `validated_context_missing` indica que o processador foi chamado sem passar pela validação.
- `item_price_mismatch`, `order_items_invalid` e erros de frete continuam sendo tratados antes da gravação.

## Validação

```bash
php scripts/quality/validate-order-context.php
bash tests/storefront-smoke.sh
```

A correção só é considerada concluída quando não existe segunda leitura de `php://input` e a entrada pública usa obrigatoriamente o validador.
