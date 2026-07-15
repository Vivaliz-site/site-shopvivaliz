# Integridade de Preços

## Regra principal

O ShopVivaliz não deve inventar, estimar ou exibir preço zero como se fosse um valor comercial válido.

## Critérios

- Preço válido deve ser maior que zero.
- Produto com estoque e sem preço deve ser sinalizado para correção de sincronização.
- Produto sem preço não pode seguir para compra direta.
- O servidor deve ser a fonte final para validação do preço usado no pedido.
- O catálogo estático pode atuar como fallback, mas dados válidos do banco prevalecem.

## Endpoints

- `/api/catalog/price-health.php` resume a cobertura de preços.
- `/api/catalog/products-with-valid-price.php` retorna somente produtos com preço válido.
- `/api/catalog/price-by-product.php?sku=...` consulta preço, estoque e possibilidade de compra.

## Storefront

- Cards sem preço válido exibem “Preço indisponível”.
- Botões de compra ficam bloqueados quando o preço não é válido.
- A página de produto oferece contato comercial em vez de criar pedido com valor ausente.

## Validação

```bash
php scripts/quality/validate-product-prices.php
```

Uma correção de preço só é considerada concluída quando a fonte comercial foi validada e o valor correto aparece no catálogo, produto, carrinho e pedido.
