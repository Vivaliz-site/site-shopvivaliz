# Integridade do Carrinho

## Regra principal

O carrinho não deve confiar apenas nos dados armazenados no navegador. Antes do checkout, itens, quantidades, preços e estoque precisam ser validados no servidor.

## Endpoint

`POST /api/cart/validate.php`

Payload:

```json
{
  "items": [
    {"sku": "SKU-EXEMPLO", "quantity": 2}
  ]
}
```

A resposta válida contém itens recalculados, preço unitário, subtotal, estoque e total. Erros possíveis incluem:

- `empty_cart`
- `product_not_found`
- `invalid_price`
- `insufficient_stock`

## Fluxo

1. O cliente monta o carrinho localmente.
2. Ao avançar, o endpoint valida cada SKU contra o catálogo confiável.
3. Preço e subtotal são recalculados no servidor.
4. Estoque insuficiente bloqueia o avanço.
5. O checkout exige validação recente do carrinho.
6. A criação do pedido continua responsável pela validação final.

## Segurança

- Quantidades são limitadas entre 1 e 99.
- Valores enviados pelo navegador não são usados como fonte comercial.
- O timestamp local de validação melhora a experiência, mas não substitui validação server-side no pedido.

## Testes

```bash
php scripts/quality/validate-cart-integrity.php
bash tests/storefront-smoke.sh
```

Um carrinho só é considerado válido quando todos os itens existem, possuem preço maior que zero e estoque suficiente.
