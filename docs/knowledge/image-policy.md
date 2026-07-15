# Política de Imagens

## Regra principal

O storefront não deve exibir placeholders, ilustrações genéricas ou a logo da Vivaliz no lugar de imagens de produtos ou categorias.

## Categorias

- Cada categoria deve usar uma imagem real de um produto pertencente à própria categoria.
- A seleção deve priorizar produtos com estoque, preço e slug válidos.
- Categorias sem imagem real devem ficar ocultas até que a origem seja corrigida.
- O vínculo deve ser feito por categoria normalizada e SKU/ID confiável.

## Produtos

- `image_url` vazia, contendo `placeholder` ou apontando para a logo não é considerada imagem válida.
- Falhas de carregamento devem ser registradas e não substituídas silenciosamente por uma imagem enganosa.
- Imagens importadas da Olist/Tiny devem manter o vínculo com o SKU correto.

## Validação

- Endpoint de categorias: `/api/catalog/category-images.php`
- Health de cobertura: `/api/catalog/image-health.php`
- Quality gate local: `php scripts/quality/validate-category-images.php`

## Evidência

Uma correção de imagem só é considerada concluída quando a categoria ou produto renderiza uma imagem real ligada ao item correto e o health check confirma cobertura válida.
