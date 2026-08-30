# Google ecosystem audit - ShopVivaliz - 2026-08-24

## Evidencia confirmada nesta etapa
- Feeds Google Shopping/Merchant estavam publicando XML valido, mas com 0 itens.
- Causa raiz: os feeds ainda aceitavam imagem apenas no dominio shopvivaliz.com.br. O catalogo correto agora usa imagens oficiais do produto no ERP/Tiny em `https://s3.amazonaws.com/tiny-anexos-*`.
- Correcao: `svgf_feed_absolute_url()` passou a aceitar imagens externas somente quando forem do host/caminho ERP/Tiny (`s3.amazonaws.com/tiny-anexos-*`), mantendo bloqueio para imagens externas arbitrarias.
- Ambos feeds passaram a usar o mesmo enriquecimento ERP-only do catalogo publico: `svcie_enrich_images(svcr_products())`.

## Validacao
- `google-shopping-feed.php`: 175 itens por CLI e HTTP 200 com cache-buster.
- `google-merchant-feed.php`: 175 itens por CLI e HTTP 200 com cache-buster.
- `tests/google-feed-readiness-test.php`: exige pelo menos 100 itens, imagens ERP/Tiny, preco e disponibilidade em estoque.
- `tests/google-shopping-feed-utils-test.php`: utilitarios do feed continuam OK.

## Ads / GA4 / conversao
- Compra deve ser registrada somente apos pagamento aprovado, via webhook/pos-processador GA4.
- Eventos `purchase` prematuros no checkout continuam bloqueados; eventos pre-pagamento sao apenas funil.
- Historico operacional indicava campanha Antique/Decore com entrega muito baixa/zero gasto no periodo validado, e diagnosticos anteriores apontaram problemas de landing, catalogo, feed, GSC, UX/frete e rastreamento como causas provaveis da ausencia de venda.

## Search Console / Merchant
- Sitemap canonico em `/sitemap.xml` ja validado em SEO-01.
- Correcoes de GSC anteriores: slugs canonicos, sitemap apenas com produtos indexaveis, aliases historicos e remocao de URLs ruins.
- Merchant agora volta a receber itens reais; proximo passo externo e aguardar recrawl/reprocessamento no Merchant Center e revisar diagnosticos autenticados.

## Causa-raiz consolidada para zero vendas ate aqui
1. Baixa entrega/gasto real da campanha em parte do periodo operacional.
2. Landing/catalogo historicamente instaveis, com URLs antigas e produto indisponivel/cacheado.
3. Feed Merchant vazio ate esta correcao, impedindo Shopping/Merchant de ajudar descoberta e diagnostico.
4. UX de carrinho/frete/estoque/textos e confianca estava inconsistente antes dos reparos.
5. Conversao dependia de integracao correta GA4/Ads e pagamento aprovado; eventos prematuros foram removidos por seguranca.

## Pendencias externas/autenticadas
- Revalidar no Google Merchant Center diagnosticos de item apos novo processamento do feed.
- Revalidar no Google Ads metricas atuais da campanha e importacao da conversao Compra.
- Revalidar no GA4 DebugView/Realtime quando houver checkout real ou pedido-teste controlado.
