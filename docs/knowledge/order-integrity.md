# Integridade de Pedidos

## Regra principal

A criação do pedido deve rejeitar qualquer divergência entre os valores enviados pelo navegador e os dados autoritativos do catálogo.

## Validações obrigatórias

- Produto deve existir no catálogo confiável.
- Preço deve ser maior que zero.
- Quantidade deve respeitar o estoque disponível.
- Itens duplicados devem ser agregados por SKU.
- Frete deve possuir cotação assinada, válida e não expirada.
- A chave de assinatura deve estar configurada em ambiente seguro.
- Valores enviados pelo cliente não podem prevalecer sobre o servidor.

## Endpoints

- `POST /api/orders/create.php` é direcionado ao validador.
- `/api/orders/create-validated.php` aplica as validações comerciais e de frete.
- `/api/orders/health.php` verifica catálogo, armazenamento e configuração de assinatura.

## Erros relevantes

- `order_items_invalid`
- `item_price_mismatch`
- `shipping_quote_required`
- `shipping_quote_expired`
- `shipping_quote_invalid`
- `quote_signing_key_missing`

## Testes

```bash
php scripts/quality/validate-order-integrity.php
bash tests/storefront-smoke.sh
```

O pedido só é considerado confiável quando itens, preços, estoque e frete foram validados pelo servidor imediatamente antes da gravação.
