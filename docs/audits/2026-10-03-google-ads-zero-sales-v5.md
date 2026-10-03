# Auditoria Extrema V5 — Google Ads sem vendas — 2026-10-03

## Escopo

Diagnóstico ponta a ponta de aquisição paga, GA4/GTM, Merchant Center, Search Console, catálogo, oferta, frete, checkout e pagamento para o período de 2026-08-01 a 2026-10-02. A auditoria separa falhas históricas de falhas ainda ativas e não cria pedidos/pagamentos artificiais em produção.

## Evidência executiva

- Google Ads: 981 cliques, R$ 558,90 de gasto e 0 conversões de compra.
- Campanha Vedante: 890 cliques/R$ 473,66; 633 cliques e R$ 259,80 vieram da rede CONTENT apesar de a campanha ser de Pesquisa. A configuração atual já está Search-only e a campanha está pausada.
- GA4: 756 sessões `google / cpc`; não há evento `purchase` no período.
- Paid Search GA4: 34 usuários em `view_item`, 1 em `add_to_cart` e 1 em `begin_checkout`.
- Pedidos do site: nenhum `payment_approved` em agosto ou setembro.
- RODOVED100: estoque disponível 465; estoque não é a causa do zero de vendas.

## Causas e achados

### COMPROVADO — desperdício histórico de mídia

A campanha de Pesquisa do vedante entregou parte majoritária dos cliques na rede de conteúdo: 633/890 cliques e R$ 259,80/R$ 473,66. A configuração atual já foi corrigida e as campanhas de maior gasto estão pausadas.

### COMPROVADO — oferta entregue pouco competitiva

No checkout mobile auditado, RODOVED100 custa R$ 35,02. `VIVALIZ10` reduz R$ 3,50, e o frete mais barato para o CEP público de teste 01310-100 foi R$ 12,64, total entregue R$ 44,16. Pesquisa de mercado em 2026-10-03 mostrou produtos equivalentes de 100 cm frequentemente abaixo desse total e com frete grátis. Não reativar mídia sem validar margem e preço entregue.

### COMPROVADO — GTM legado enviava dados para GA4 divergente

A produção carregava `GTM-PHZ55CP3`, cujo container publicado injeta `G-QWYPLYMZ9`. O GA4 oficial do projeto é `G-1H55K1TZ5D` e já funciona de forma independente pelo carregador direto/first-party. CDP comprovou que o stream divergente nasce do GTM legado. Simulação com o GTM legado bloqueado preservou `page_view`, `view_item`, `add_to_cart` e `view_cart` no GA4 oficial.

Correção: retirar fallback hard-coded do GTM e permitir GTM somente por configuração explícita. Após deploy, `GOOGLE_TAG_MANAGER_ID` deve ficar vazio até existir um container revisado que não duplique o GA4 oficial.

### COMPROVADO — texto de cupom falso

O frontend dizia que `VIVALIZ10` exigia carrinho acima de R$ 100. Teste gráfico real aplicou o cupom em R$ 35,02 com resposta HTTP 200 e desconto de R$ 3,50. A mensagem foi corrigida para não inventar mínimo.

### COMPROVADO — compra server-side GA4 incompleta

O webhook de pagamento aprovado usa Measurement Protocol e depende de `GA4_SECRET`. Essa chave não está materializada no runtime. O OAuth disponível possui `analytics.readonly`, mas não permissão de Analytics Admin; a tentativa de listar/criar secrets via Admin API retorna 403. O fallback browser depende de o cliente retornar do gateway.

Estado: BLOQUEADO_POR_CREDENCIAL até um Measurement Protocol API secret do stream `G-1H55K1TZ5D` ser criado no GA4 e armazenado como `GA4_SECRET` no secret store/runtime. Nunca versionar o valor.

### COMPROVADO — checkout atual operacional

E2E gráfico mobile 390x844 no backend: produto -> carrinho -> checkout funciona sem overflow, erro JS ou falha de API do site. O CTA real é `Comprar agora`; carrinho e checkout renderizam corretamente. A política do projeto proíbe canário que gere pedido/pagamento real em produção, por isso o teste termina antes da submissão.

`production-functional-audit.sh` passou: páginas críticas 200, fila de pagamento saudável, cotação real de frete, Mercado Pago conectado, Melhor Envio conectado e Olist conectado. `mercadopago-payment-tests.php`: 11/11 PASS.

### COMPROVADO — Merchant e Search Console não são o bloqueio do SKU anunciado

Merchant canonical `5381803710`: 178 produtos processados, 177 aprovados e 1 desaprovado; zero account-level issues. RODOVED100 está aprovado para Shopping Ads e Free Listings no Brasil. Há passivo de qualidade de imagens no catálogo (principalmente `image_link_broken`, `image_link_internal_error`, imagens pequenas/baixa qualidade), que deve ser tratado em trilha própria.

Search Console 2026-08-01..2026-10-02: 542 páginas com impressões, 4.819 impressões e 67 cliques. RODOVED100 teve 92 impressões/2 cliques, posição média ~9,05. Portanto não há evidência de desindexação geral que explique o zero de vendas pagas.

## Falha histórica provável do pagamento

O código atual documenta que versões anteriores não devolviam corretamente `payment_session_token`, fazendo `svmp_session_matches()` rejeitar `invalid_payment_session`. Sete tentativas de checkout aparentemente externas de agosto chegaram a criar pedido local e identidade de sessão, mas não persistiram preferência/pagamento Mercado Pago. A associação causal individual é INCONCLUSIVA porque o histórico sanitizado não preserva a data exata do hotfix por tentativa.

## Correções desta V5

1. Remover o fallback hard-coded do GTM legado.
2. Manter o GA4 oficial direto/first-party quando nenhum GTM explícito estiver configurado.
3. Corrigir texto incorreto do cupom `VIVALIZ10`.
4. Adicionar regressão para impedir retorno do GTM legado e da alegação falsa de R$ 100.
5. Corrigir regressão de teste da política comercial que esperava texto obsoleto/sem acento.
6. Atualizar checklists canônicos de tráfego/rastreamento.

## Gate antes de voltar a investir

Não reativar campanhas pagas enquanto qualquer item abaixo estiver aberto:

1. `GA4_SECRET` criado e Measurement Protocol validado com `/debug/mp/collect` sem mensagens de validação.
2. Uma venda genuína aprovada deve produzir `purchase` no GA4 e aparecer na ação de conversão importada do Google Ads.
3. Margem/COGS e CAC máximo por categoria/SKU precisam estar definidos; o readiness local hoje falha por `roi10_assumptions_missing`.
4. Preço + frete do SKU anunciado precisa ser competitivo para a consulta-alvo.
5. Search-only para campanhas de pesquisa; sem Content/Display e sem Search Partners até nova validação explícita.
6. Termos de busca e negativas revisados; evitar consultas DIY, concorrentes/marketplaces e intenções incompatíveis.

## Classificação V5

- Site/checkout atual: APTO no gate funcional e E2E até pré-pagamento.
- Aquisição paga: NÃO APTO para reativação.
- Mensuração de compra: BLOQUEADO_POR_CREDENCIAL (`GA4_SECRET`).
- Merchant do SKU anunciado: APTO; catálogo global requer remediação de imagens.
- SEO/indexação: operacional; volume orgânico ainda baixo.
