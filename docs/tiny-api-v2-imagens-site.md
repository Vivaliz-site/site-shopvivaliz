# Tiny API V2 - Imagens no Catalogo

## Endpoints implantados

- `GET /api/olist/products-proxy.php`
  - Lista produtos via Tiny API V2 `produtos.pesquisa.php`.
  - Quando recebe `id`, busca o produto completo via `produto.obter.php`.

- `GET /api/olist/product-obter-v2.php?id=ID_DO_PRODUTO`
  - Proxy direto para `produto.obter.php`.
  - Usado para validacao pontual de `anexos` e `imagens_externas`.

- `POST /api/agent/olist-images-import-v2.php`
  - Importa imagens da Tiny API V2 para `olist_products` e `olist_product_images`.
  - Atualiza `olist_products.primary_image_url`, `images_count`, `image_sync_status`.
  - Atualiza `products.image_url` quando encontra SKU correspondente.

Todos os endpoints usam `SQUAD_TOKEN` e leem o token Tiny/Olist de `.env`, sem expor credenciais na resposta.

## Campos de imagem usados

O detalhe do produto na API V2 pode retornar imagens em:

- `retorno.produto.anexos[]`
- `retorno.produto.imagens_externas[]`

O importador deduplica URLs iguais e preserva a ordem retornada pela API. A primeira URL vira imagem primaria.

## Validacao local usada em 2026-06-30

Planilha validada:

- `C:\Users\FRED\Downloads\produtos_2026-06-30-17-53-48.csv`

Resultado da validacao V2:

- 200 linhas lidas.
- 126 produtos comparados antes do bloqueio temporario da API.
- 0 divergencias de link entre planilha e API nos produtos comparados.
- 74 produtos ficaram pendentes por limite da Tiny API: `API Bloqueada - Excedido o numero de acessos`.

Importacao local a partir dos links exportados do Tiny:

- 200 produtos processados.
- 980 imagens baixadas/copiedas para `uploads/olist`.
- 189 produtos com imagem.
- 11 produtos sem imagem na planilha.
- SQL de vinculo gerado em `storage/reports/olist_imagens_site_mapeamento.sql`.

## Por que antes nao importava tudo

O fluxo anterior dependia principalmente da API V3/OAuth e do script `scripts/sync-olist-images.py`.
Na validacao local, a chamada V3 retornou HTTP 401, indicando token OAuth invalido/expirado.
Além disso, o proxy V2 existente apenas listava produtos; ele nao buscava o detalhe `produto.obter.php`,
que e onde ficam `anexos` e `imagens_externas`.

Com a implantacao V2, o site agora tem caminho de detalhe e importacao por API V2.
