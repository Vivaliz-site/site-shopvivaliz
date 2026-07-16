# v9.2.86 - Olist images by SKU

## Objetivo

Corrigir a exibicao das imagens do ShopVivaliz usando o cadastro ERP/Olist como origem oficial.

## Evidencia operacional

A automacao Selenium ja enviada pelo usuario acessava o ERP em `/produtos#list`, pesquisava por SKU no campo `pesquisa-mini`, abria o produto, entrava em `link-dadosAdicionais`, abria `#container-imagens`, removia imagens antigas, enviava arquivos pelo input `Filedata`, salvava imagens e salvava o produto.

A variante `img2_capa` enviava `imagem2` primeiro para virar capa, depois `imagem1`, `imagem3`, `imagem4`, `imagem5`.

## Conclusao

As imagens devem ser tratadas como existentes no cadastro ERP/Olist. A falha esta na leitura, importacao, reconciliacao ou exibicao local.

## Fluxo correto

1. Usar SKU como chave principal.
2. Resolver o cadastro ERP/Olist do SKU.
3. Ler imagens oficiais do cadastro.
4. Salvar URLs em tabela local de imagens.
5. Atualizar imagem principal do produto local.
6. Corrigir o front para priorizar imagem local reconciliada.

## Arquivos recomendados

- `admin/olist-images-audit.php`
- `admin/olist-images-import-by-sku.php`
- `includes/olist-api-client.php`
- `includes/product-image-resolver.php`
- `sql/20260627_olist_product_images.sql`

## Regra de capa

Quando a origem possuir imagem numerada, priorizar `imagem2` como capa. Depois ordenar `imagem1`, `imagem3`, `imagem4`, `imagem5`.

## Seguranca

Nao registrar nem expor login, senha, tokens, client secret, FTP ou chaves.
