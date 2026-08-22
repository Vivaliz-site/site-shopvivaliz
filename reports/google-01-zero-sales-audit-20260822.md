# GOOGLE-01 — Auditoria Google e zero vendas ShopVivaliz

Data: 2026-08-22

## Escopo validado
- Google Shopping/Merchant feed público.
- Sitemap público.
- Catálogo público disponível/ativo.
- Eventos e prevenção de conversão prematura em código.
- Histórico operacional de Google Ads/GA4/GSC disponível no repositório.

## Evidências confirmadas nesta rodada
- `/google-shopping-feed.php` publica 175 itens.
- `/google-merchant-feed.php` publica 175 itens.
- Imagens dos feeds vêm de `https://s3.amazonaws.com/tiny-anexos-*`, isto é, mídia do ERP/Tiny.
- O feed Merchant agora emite `g:link` além de `link` RSS em cada item.
- O cache dos feeds passou a depender também dos arquivos de código do feed e helpers, evitando servir XML antigo depois de correção.
- `/sitemap.xml` retorna sitemap com produtos.
- `/api/catalog/products.php?available=1` retorna catálogo público saudável.

## Correções aplicadas
1. Feeds Merchant/Shopping deixaram de usar o enriquecedor legado de imagem e passaram a usar `includes/catalog-image-enrich.php`, alinhado à regra ERP-only.
2. `svgf_feed_absolute_url()` passou a aceitar imagens externas somente quando forem mídia ERP/Tiny S3 (`s3.amazonaws.com/tiny-anexos-*`). Continua bloqueando imagens externas arbitrárias.
3. `google-merchant-feed.php` passou a emitir `g:link` para cada produto.
4. `includes/feed-cache.php`/chamadas de feed agora invalidam cache quando mudam os arquivos dos feeds/helpers.
5. Cache antigo de `shared/storage/cache/google-feeds/google-*.xml` foi limpo.
6. Criado teste público `tests/google-readiness-public-test.php`.

## Diagnóstico de zero vendas — evidência e hipótese
### Evidência histórica no repo
- Relatório `reports/conversion-recovery-2026-08-17.md` registrou que, entre 09/07 e 05/08/2026, Google Ads teve 311 cliques, 3,98 mil impressões, CTR 7,83%, custo R$84,17 e nenhuma conversão registrada.
- O mesmo relatório apontou problemas já corrigidos depois: feed Merchant vazio, sitemap sem produtos, descrições fracas, checkout/frete/eventos prematuros, duplicidade GA4/GTM e textos institucionais frágeis.
- Em `ops/google-ads-antique-decore-status-latest.txt`, a rotina de 2026-08-20 registrou pausa por guardrail de estrutura de ad groups, não por conversão.

### Causas prováveis antes das correções
1. Baixa confiabilidade de destino/feed: Merchant vazio ou incompleto reduzia a capacidade de Shopping/remarketing e confiança de produto no Google.
2. Rastreamento: compra só deve ser registrada após pagamento aprovado; antes havia risco de evento prematuro ou duplicidade, depois corrigido.
3. Catálogo/checkout: problemas de estoque, frete, carrinho, imagens e textos públicos reduziam conversão.
4. Campanha Antique/Decore teve janela recente com pouco/nenhum tráfego validado, logo não há base suficiente para concluir falha de oferta atual.

### Lacunas que exigem painel autenticado/API Google Ads
- Métricas atuais por campanha/ad group/keyword no período completo pós-correções.
- Termos de pesquisa e negativas.
- Diagnóstico Merchant Center real de reprovação/aprovação item a item.
- Relatórios GA4 de funil completo com sessões reais pagas, add_to_cart, begin_checkout e purchase.

## Critério de aceite
- `php tests/google-readiness-public-test.php` retorna `GOOGLE_READINESS_PUBLIC_OK`.
- Feeds públicos têm ao menos 100 itens, tags obrigatórias, imagens ERP/Tiny e sitemap com produtos.
- Checklist GOOGLE-01 pode ser marcado como concluído para a parte pública/código; monitoramento Ads autenticado continua pelo watcher já configurado.
