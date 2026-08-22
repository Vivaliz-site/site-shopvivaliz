# GOOGLE-01 - Auditoria Google e zero vendas ShopVivaliz

Data: 2026-08-21

## Evidencias confirmadas

- Google Ads Antique/Decore foi criado com orcamento controlado de R$10/dia e guardrail de pausa quando custo acumulado > R$12 sem compra.
- Conversao principal `Compra` existe/importa GA4 purchase e estava ativa em auditorias anteriores.
- Eventos de compra prematuros foram removidos: `purchase`/Ads so devem ocorrer apos pagamento aprovado.
- GTM/GA4 estao centralizados no carregamento publico; `purchase` server-side usa GA4 Measurement Protocol apos pagamento aprovado quando secrets existem.
- Sitemap publico `/sitemap.xml` publica URLs canonicas finais e produtos indexaveis.
- API publica do catalogo disponivel tem 162 produtos compraveis com SKU, preco, estoque e imagem HTTPS.
- Feeds Google Shopping/Merchant foram corrigidos nesta etapa: ambos agora publicam 175 itens com imagens do ERP/Tiny S3.

## Causas provaveis de zero vendas ate agora

1. Historicamente houve trafego pago sem conversao: relatorio anterior registrou 311 cliques, 3,98 mil impressoes, CTR 7,83%, custo R$84,17 e 0 conversoes entre 09/07 e 05/08.
2. Nesse periodo havia problemas de conversao/UX ja documentados: Merchant/feed vazio, sitemap sem produtos, descricoes fracas, cotacao de frete/checkout, tracking duplicado e eventos de compra antes do pagamento.
3. A campanha Antique/Decore especifica teve pouca/nenhuma entrega confirmada em auditorias mais recentes, e quando reativada no dia validado apresentava 0 impressoes, 0 cliques, R$0,00 e 0 conversoes.
4. O Merchant feed estava vazio ate esta correcao, impedindo qualidade/diagnostico de Shopping/Performance e reduzindo sinais de produto ao Google.
5. Ainda ha lacunas que exigem painel autenticado/credenciais: status atual no Merchant Center, relatorios atuais Ads/GA4/Search Console, termos de busca, leiloes, parcela de impressoes, diagnosticos de item e atribuicao por campanha.

## Correcoes feitas nesta rodada

- Corrigido `svgf_feed_absolute_url()` para aceitar imagens externas somente quando forem do host/caminho oficial ERP/Tiny: `https://s3.amazonaws.com/tiny-anexos-*`.
- Mantido bloqueio para imagens externas genericas; imagem de produto continua ERP-only.
- Corrigido uso dos feeds para o enriquecedor canônico `svcie_enrich_images(svcr_products())`.
- Criado teste `tests/google-merchant-feed-nonempty-test.php` para falhar se Shopping/Merchant voltarem a publicar feed vazio, placeholder, imagem manual ou menos de 100 itens.

## Validacao

- `google-shopping-feed.php`: HTTP 200, 175 `<item>`, imagens ERP/Tiny S3.
- `google-merchant-feed.php`: HTTP 200, 175 `<item>`, imagens ERP/Tiny S3.
- `tests/google-merchant-feed-nonempty-test.php`: OK.
- `tests/google-shopping-feed-utils-test.php`: OK.
- `tests/seo-product-feed-quality-test.php`: OK com 162 produtos disponiveis.

## Proxima operacao recomendada

- Reprocessar feed no Google Merchant Center e verificar diagnosticos de itens.
- No Google Ads, manter orcamento R$10/dia e guardrail > R$12 sem compra.
- Separar campanha Search de intencao alta de campanhas Shopping/Performance apos Merchant ficar aprovado.
- Auditar termos de busca e negativos apos haver cliques reais.
- Confirmar GA4 purchase e importacao no Ads apos a primeira compra real/pagamento aprovado.
