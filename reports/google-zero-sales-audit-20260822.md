# ShopVivaliz — Auditoria Google e zero vendas

Data: 2026-08-22

## Escopo
Google Ads, GA4/GTM, Merchant/Shopping Feed, Search Console/sitemap, catálogo público e funil de compra.

## Evidência confirmada
- Google Ads: histórico operacional indicou campanha `ShopVivaliz-Search-Vasos-Antique-Decore-2026-08` com orçamento controlado em R$10/dia e conversão principal `Compra` ativa em checagens anteriores. Em 2026-08-20 o guard automático pausou por falha de invariantes de ad group (`AD_GROUP_INVARIANT_FAILED`) no arquivo `ops/google-ads-antique-decore-status-latest.txt`.
- Conversão: o site bloqueia eventos `purchase` antes do pagamento; compra GA4 server-side só ocorre após pagamento aprovado. Isso é correto para evitar conversões falsas.
- Catálogo: API pública validada com produtos disponíveis, preço, estoque e imagem ERP/Tiny.
- Merchant/Shopping: feeds `google-shopping-feed.php` e `google-merchant-feed.php` foram corrigidos para publicar 175 itens com imagens ERP/Tiny S3.
- SEO/Search Console: sitemap canônico `/sitemap.xml` tem URLs finais; relatórios anteriores apontavam problemas de indexação já tratados por alias/canonical/sitemap.

## Causa-raiz provável de zero vendas até aqui
1. Entrega de mídia insuficiente ou interrompida: campanha com períodos de pausa/guard e baixa ou nenhuma atividade recente confirmada.
2. Histórico anterior tinha cliques sem venda quando ainda havia problemas de catálogo, feed Merchant vazio, páginas de produto/checkout/textos e rastreamento inconsistentes.
3. Merchant/Shopping feed estava vazio no momento desta auditoria; isso reduz elegibilidade em Shopping/Free Listings e qualidade de ecossistema Google.
4. Compra só é registrada após pagamento aprovado; portanto 0 conversões pode representar ausência real de compras, não apenas perda de evento, mas precisa ser confirmado no Ads/GA4 autenticado.

## Correções já aplicadas neste ciclo
- Feed Merchant/Shopping alinhado ao catálogo ERP/Tiny e validado com 175 itens.
- API catálogo corrigida para paginação por `offset` sem sobreposição.
- Sitemap/schema/feed público validados por testes.
- Textos públicos, blog, acessibilidade, carrinho, footer, estoque, imagens ERP-only e sync pedido/NF/rastreio via API v3 corrigidos em etapas anteriores.

## Pendências que exigem painel autenticado ou API Google
- Confirmar status atual da campanha/ad groups no Google Ads após o guard de 2026-08-20.
- Conferir Search terms, palavras negativas, lances, parcela de impressões, motivos de baixa entrega e quality score.
- Conferir GA4 aquisição por campanha, checkout funnel e eventos `begin_checkout`, `add_payment_info`, `purchase` após as correções.
- Conferir Merchant Center item-level diagnostics, aprovação/reprovação e destino do feed.
- Conferir Search Console após recrawl do sitemap corrigido.

## Critério de aceite GOOGLE-01
- Feeds Google com >100 itens reais, preço, disponibilidade e imagem ERP/Tiny.
- Catálogo público disponível com preço/estoque/imagem e sem itens sem estoque no feed `available=1`.
- Relatório separando evidência observada, causa provável e pendências autenticadas.
