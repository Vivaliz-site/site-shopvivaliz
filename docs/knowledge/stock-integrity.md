# Integridade de Estoque

## Regra principal

O ShopVivaliz não deve permitir compra de produto sem estoque disponível.

## Critérios

- Estoque disponível deve ser maior que zero.
- Valores negativos são inválidos e devem bloquear a validação.
- Produto com preço e sem estoque deve aparecer como esgotado.
- O servidor deve ser a fonte final para validar disponibilidade antes do pedido.
- Dados válidos do banco prevalecem sobre o catálogo estático quando disponíveis.

## Endpoints

- `/api/catalog/stock-health.php` resume a disponibilidade do catálogo.
- `/api/catalog/products-in-stock.php` retorna somente produtos disponíveis.
- `/api/catalog/stock-by-product.php?sku=...` consulta estoque e possibilidade de compra.

## Storefront

- Cards esgotados ficam sinalizados e com compra bloqueada.
- A página de produto não permite avançar com estoque zero.
- Estados “Disponível”, “Esgotado” e “Indisponível no momento” devem refletir o dado atual.

## Validação

```bash
php scripts/quality/validate-product-stock.php
```

Uma correção de estoque só é considerada concluída quando a fonte comercial foi validada e o mesmo estado aparece no catálogo, produto, carrinho e criação do pedido.
