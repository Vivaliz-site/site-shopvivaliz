# ShopVivaliz - GOOGLE-01 readiness e zero vendas

Data: 2026-08-22

## Evidencias confirmadas nesta rodada

- Google Shopping feed publico corrigido: `google-shopping-feed.php` gera 175 `<item>` no docroot de producao.
- Google Merchant feed publico corrigido: `google-merchant-feed.php` gera 175 `<item>` no docroot de producao.
- Feeds publicos via HTTP com cache-buster retornaram 175 itens cada, com imagens `https://s3.amazonaws.com/tiny-anexos-*` vindas do ERP/Tiny e sem `/uploads/catalog-fixed/`.
- `api/catalog/products.php?available=1` validado com 162 produtos disponiveis, sem item sem estoque/preco/imagem no feed publico.
- `sitemap.xml` validado com URLs de produto e schema Product/Offer validado em pagina de produto.
- Conversao GA4/Ads: o codigo impede `purchase` antes de pagamento aprovado; purchase server-side fica no webhook/pos-processador de pagamento.
- GTM/GA4: ha GTM `GTM-PHZ55CP3` e GA4 `G-1H55K1TZ5D` no codigo, com consent mode e captura de gclid/utm.

## Correcoes feitas nesta rodada

- `includes/google-shopping-feed-utils.php`: imagens externas do feed continuam bloqueadas por padrao, mas agora permite estritamente URLs oficiais do ERP/Tiny em `https://s3.amazonaws.com/tiny-anexos-*`.
- `google-shopping-feed.php` e `google-merchant-feed.php`: feeds alinhados ao enriquecedor ERP-only `svcie_enrich_images(svcr_products())`, removendo caminho antigo que gerava feed vazio.
- Criado `tests/google-readiness-smoke-test.php` para prevenir regressao de feed vazio, imagens locais/manuais, API catalogo sem produtos e sitemap sem produtos.

## Diagnostico de zero vendas ate aqui

Evidencia historica mostra que houve campanhas com cliques e custo sem compra, mas a loja tinha problemas tecnicos relevantes que reduziam conversao: feed Merchant vazio, problemas de landing/canonicals antigos, textos de checkout/contato manuais, frete/cotacao confusos, tracking de compra antes de pagamento em fluxos antigos, catalogo com lentidao e filtros/paginacao incorretos, e itens/imagens inconsistentes antes da regra ERP-only.

A causa mais provavel nao e um unico ponto; e uma combinacao de baixa entrega qualificada + confianca/UX/frete/catalogo/tracking historicamente instaveis. Nesta etapa, os bloqueios tecnicos mais graves para Merchant/SEO/catalogo foram corrigidos. Para concluir atribuicao de Ads com certeza ainda depende de painel autenticado/API Google Ads atual para confirmar custo, termos, queries, impressoes, conversoes importadas e status da campanha em periodo atual.

## Proximos checks autenticados recomendados

- Google Ads: confirmar periodo atual, gasto por campanha, termos de pesquisa, keywords, negativas, localizacao, dispositivos, status de anuncios/grupos, conversoes importadas e se a campanha esta limitada.
- GA4: funil por `session_campaign`, `source/medium`, eventos `view_item`, `add_to_cart`, `begin_checkout`, `add_shipping_info`, `add_payment_info`, `purchase`.
- Merchant Center: conferir aprovacao dos 175 itens, erros de preco/estoque/imagem/frete e destino.
- Search Console: solicitar reprocessamento do sitemap canonico e acompanhar reducao de 404/noindex/canonical mismatch.

## Criterio de aceite desta etapa

- Feeds Google nao podem ficar vazios.
- Feeds devem usar imagens ERP/Tiny, nao fallback local/manual.
- Catalogo publico deve expor apenas produtos disponiveis com preco, estoque e imagem.
- Sitemap deve incluir produtos canonicos.
- Purchase nao pode ser enviado antes do pagamento aprovado.
