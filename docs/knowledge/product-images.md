# Imagens de Produtos

## Regra de exibição

Produtos sem imagem real validada não devem aparecer com logo, ícone genérico ou placeholder no catálogo. Na página de produto, a ausência de imagem deve ser informada de forma explícita.

## Critérios de imagem válida

- URL não vazia.
- Não conter `placeholder`.
- Não apontar para a logo da Vivaliz.
- Estar vinculada ao SKU ou ID correto.
- Carregar sem erro no navegador.
- Não apresentar evidência de pertencer a outro produto.

## Auditoria de imagem suspeita

Além de validar se existe imagem, o sistema deve auditar se a imagem parece pertencer ao produto correto.

Exemplos de risco:

- imagem em posição 3, 4 ou posterior que foge do produto principal;
- URL ou nome do arquivo sem correspondência com SKU, nome, categoria ou marca;
- imagem de canal/marketing misturada na galeria principal;
- asset genérico, logo ou placeholder;
- galeria extensa com imagens repetidas ou fracas.

A primeira camada é conservadora e não apaga nada automaticamente. Ela gera fila de revisão para decidir exclusão, quarentena ou aprovação.

## Endpoints

- `/api/catalog/valid-image-products.php` retorna somente produtos com imagem considerada válida.
- `/api/catalog/image-by-product.php?sku=...` consulta a situação visual de um produto.
- `/api/catalog/image-health.php` resume a cobertura do catálogo.
- `/api/catalog/product-image-mismatch-audit.php?limit=500` audita imagens suspeitas ou possivelmente não pertencentes ao produto.

## Scripts

```bash
php scripts/quality/validate-product-images.php
php scripts/quality/audit-product-image-mismatch.php 500
```

## Comportamento do storefront

- Cards com imagem inválida são removidos do catálogo.
- Imagens que falham durante o carregamento removem o card correspondente.
- Página de produto usa o estado “Imagem indisponível” em vez de imagem falsa.
- Dados estruturados não devem anunciar uma imagem inválida.
- Imagens suspeitas devem ser revisadas antes de exclusão definitiva.

## Diagnóstico

Execute:

```bash
php scripts/quality/validate-product-images.php
php scripts/quality/audit-product-image-mismatch.php 500
```

A correção só é concluída quando o produto correto possui imagem real e o vínculo pode ser comprovado por SKU ou ID.

Quando uma imagem é removida manualmente por não pertencer ao produto, a próxima etapa obrigatória é registrar essa rejeição para impedir reimportação da mesma URL/hash.