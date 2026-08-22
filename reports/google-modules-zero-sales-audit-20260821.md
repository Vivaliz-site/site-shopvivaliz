# ShopVivaliz - GOOGLE-01 auditoria de módulos Google e zero vendas

Data: 2026-08-21

## Evidência confirmada nesta rodada

- Google Shopping/Merchant feeds estavam respondendo HTTP 200, mas com 0 itens publicados.
- Causa-raiz: os feeds ainda rejeitavam imagens canônicas do ERP/Tiny hospedadas em `https://s3.amazonaws.com/tiny-anexos-*`, aceitando apenas URLs do domínio da loja. Como a regra atual exige mídia vinda do ERP/Tiny, o filtro descartava todos os produtos elegíveis.
- Correção aplicada: `includes/google-shopping-feed-utils.php` passou a aceitar somente o host/caminho ERP/Tiny S3 (`s3.amazonaws.com/tiny-anexos-*`) além do domínio oficial da loja. Isso não reintroduz fallback local/manual.
- Feeds públicos validados após correção:
  - `/google-shopping-feed.php`: 175 `<item>`
  - `/google-merchant-feed.php`: 175 `<item>`
  - imagens publicadas com origem ERP/Tiny S3
- API pública de catálogo validada em CAT-01: 162 produtos disponíveis/com estoque para vitrine comprável.
- Sitemap canônico `/sitemap.xml` validado em SEO-01 com Product/Offer schema presente em página de produto.

## Google Ads / GA4 / conversão

Evidências já registradas no repositório e painéis operacionais:

- `reports/conversion-recovery-2026-08-17.md` registrou que, entre 09/07 e 05/08/2026, houve 311 cliques, 3,98 mil impressões, CTR 7,83%, custo R$ 84,17 e nenhuma conversão registrada.
- O mesmo relatório apontou e corrigiu problemas de atribuição/checkout: `purchase` removido da criação simples do pedido; compra passa a ser registrada após pagamento aprovado; GTM corrigido para `GTM-PHZ55CP3`; duplicidade de tag reduzida; pageview server-side síncrono tornou-se opt-in.
- `ops/google-ads-antique-decore-status-latest.txt` registrou em 2026-08-20 que a campanha Antique/Decore foi pausada por guardrail de invariantes do ad group.
- `reports/gsc-audit-summary-2026-08-17.json` mostrou problemas de indexação/404/noindex/canonical; `reports/gsc-indexing-fix-2026-08-20.md` registrou remediações de sitemap/slug/canonical.

## Causas prováveis para zero vendas até agora

1. **Baixa ou instável entrega qualificada em Ads**: a campanha Antique/Decore passou por pausas/guardrails e períodos de zero impressão/clique em validações recentes.
2. **Problemas históricos de conversão/tracking**: compra era disparada cedo demais ou dependia de configuração de Ads/GA4 incompleta; isso foi corrigido para registrar apenas pagamento aprovado.
3. **Merchant/Shopping sem produto efetivo**: feeds Google estavam vivos mas vazios; isso bloqueava Shopping/Merchant como fonte de tráfego de produto. Corrigido nesta etapa.
4. **Problemas históricos de SEO/indexação**: sitemap com poucos/nenhum produto e problemas de URLs antigas/canônicas, já tratados em SEO/GSC.
5. **UX/confiança/catálogo**: carrinho, textos, avaliações, estoque, imagens, frete, produto e área do cliente tinham falhas que foram corrigidas nas etapas P0/P1/P2.

## Critérios de aceite cumpridos

- Feeds Merchant/Shopping têm itens reais e imagens ERP/Tiny.
- Nenhum dado comercial de produto foi alterado fora do ERP.
- `tests/google-shopping-feed-utils-test.php` cobre a permissão específica para Tiny S3 e bloqueia S3 não relacionado.
- Checklist atualizado.

## Lacunas que ainda exigem painel autenticado/tempo de coleta

- Revalidar no Merchant Center se os 175 itens foram ingeridos e aprovados após recrawl.
- Revalidar no Google Ads se campanhas estão ativas, orçamento R$10/dia, anúncios qualificados e conversão Compra importada/primária.
- Aguardar nova janela de tráfego real para avaliar CTR, CPC, taxa de conversão e CPA; antes disso não há base estatística para concluir oferta/preço como causa única.
