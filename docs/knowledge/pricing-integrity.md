# Integridade de Preços

## Regra principal

Desde 2026-10-29, **preco publicado no site = preco efetivo da tabela Amazon (ID 991) do ERP Olist/Tiny v3**, substituindo a tabela PDV. A regra geral percentual e as excecoes por ID exato de produto devem ser respeitadas. Nao consultar anuncios Amazon nem modificar os precos do ERP. O preco base de cadastro e apenas entrada para o calculo da tabela Amazon, nunca fallback para uma falha de consulta da tabela.

Os webhooks de produto e preco devem reconsultar ERP e tabela Amazon, nunca confiar em preco recebido no payload. A reconciliacao periodica deve recalcular do cadastro original sem aplicar porcentagens cumulativas; se a tabela for indisponivel, preservar o ultimo cache validado e registrar falha. Validar integralmente SKU, lista, preco e preco promocional em catalogo, produto, carrinho e checkout.

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
