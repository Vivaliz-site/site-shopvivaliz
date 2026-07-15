# Imagens de Produtos

## Regra de exibição

Produtos sem imagem real validada não devem aparecer com logo, ícone genérico ou placeholder no catálogo. Na página de produto, a ausência de imagem deve ser informada de forma explícita.

## Critérios de imagem válida

- URL não vazia.
- Não conter `placeholder`.
- Não apontar para a logo da Vivaliz.
- Estar vinculada ao SKU ou ID correto.
- Carregar sem erro no navegador.

## Endpoints

- `/api/catalog/valid-image-products.php` retorna somente produtos com imagem considerada válida.
- `/api/catalog/image-by-product.php?sku=...` consulta a situação visual de um produto.
- `/api/catalog/image-health.php` resume a cobertura do catálogo.

## Comportamento do storefront

- Cards com imagem inválida são removidos do catálogo.
- Imagens que falham durante o carregamento removem o card correspondente.
- Página de produto usa o estado “Imagem indisponível” em vez de imagem falsa.
- Dados estruturados não devem anunciar uma imagem inválida.

## Diagnóstico

Execute:

```bash
php scripts/quality/validate-product-images.php
```

A correção só é concluída quando o produto correto possui imagem real e o vínculo pode ser comprovado por SKU ou ID.
