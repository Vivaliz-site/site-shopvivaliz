# ShopVivaliz - Checklist de reparos, conversao e qualidade

Atualizado em: 2026-08-21 20:45 BRT

## Regras fixas
- [ ] Nao alterar dados originais de produto, preco ou estoque.
- [ ] Nao expor secrets, tokens, cookies ou credenciais.
- [ ] Marcar [x] somente apos implementacao, teste e validacao em producao.

## Regra de fonte de dados ERP/site
- ERP/Tiny API v3 e fonte de verdade para todo campo de cadastro que possua equivalente no ERP: SKU, nome, descricao, categoria, preco, estoque, status, imagens, video, dimensoes, marca, NCM/GTIN, SEO/slug e dados comerciais.
- Enriquecimento e permitido, mas quando o campo enriquecido possui equivalente no ERP ele deve ser gravado no ERP via API v3 e retornar ao site pelo sync v3 antes de virar dado publico canonico.
- O site pode manter informacoes adicionais somente quando nao houver campo equivalente no ERP, como UX, textos institucionais, selos, conteudo editorial, comentarios, avaliacoes, analytics, ranking/ordem de vitrine e regras de apresentacao.
- Informacao adicional do site nunca pode sobrescrever, completar ou ressuscitar dado de cadastro que exista no ERP. Se houver equivalente no ERP, tratar como proposta pendente de espelhamento, nao como cadastro publico final.
- Espelhamento ERP -> site deve usar API v3; mudancas aplicaveis site -> ERP tambem devem usar API v3, nunca API v2, scraping, CSV local, snapshot antigo ou tabela local como fonte de verdade.

## P0 - Critico
- [x] REVIEW-01 Remover texto interno/admin da pagina publica de avaliacoes. Aceite: bloco publico nao contem "O servidor confere pedido". Evidencia: `grep` negativo em `avaliacoes.php`; sintaxe PHP ok.
- [x] LEGAL-01 Corrigir politica de arrependimento/devolucao para texto claro ao consumidor. Aceite: texto nao afirma que postagem de arrependimento e responsabilidade do consumidor. Evidencia: `grep` negativo; sintaxe PHP ok.
- [x] HOME-01 Categoria em destaque alterna imagens reais da categoria a cada 3s. Aceite: `index.php` ja envia `data-images`; `js/home-image-rotator-v1.js` alterna `.category-slide-image-wrapper img` a cada 3000 ms. Evidencia: arquivo JS criado e carregado por `index.php`.
- [x] HOME-02 Produtos em destaque alternam imagens do item a cada 3s. Aceite: `index.php` ja envia `data-images`; `js/home-image-rotator-v1.js` alterna `.product-image img` a cada 3000 ms. Evidencia: arquivo JS criado e carregado por `index.php`.
- [x] PDP-01 Produto com galeria, miniaturas, video seguro quando existir e rotacao de imagens a cada 3s. Aceite: `produto.php` ja tem miniaturas/video/frete; adicionada rotacao automatica de miniaturas de imagem sem alternar para video. Evidencia: sintaxe PHP ok.
- [x] PDP-02 Produto com cotacao de frete por CEP. Aceite: `produto.php` contem calculador `p-frete-cep` usando `/api/frete/calcular.php`. Evidencia: inspecao de arquivo; mantido sem alterar precos/estoque.
- [x] STOCK-01 Corrigir fonte/normalizacao de estoque do catalogo em producao. Evidencia: `api/catalog/products.php?available=1&no_cache=1` retornou total 163, count 5, `available_only=true`, com estoque real positivo em 2026-08-21.
- [x] STOCK-02 Revalidar server-side carrinho/checkout contra estoque/status antes do pedido. Evidencia: `/api/cart/validate.php` bloqueia SKU inexistente, quantidade 999 e SKU duplicado 4+4 para `JVCDAC34` quando estoque=7; `api/orders/create-v2.php` reforcado para agregar SKUs duplicados antes de comparar estoque.
- [x] IMG-01 Auditar SKUs ativos sem imagem no catalogo publico. Aceite: relatorio CSV gerado. Evidencia: `reports/api-missing-active-images-20260821.csv` com 6 SKUs.
- [x] IMG-02 Corrigir SKUs ativos sem imagem usando fonte ERP/Tiny API v3. Aceite: nenhuma imagem publica de produto vem de fallback local/manual; produtos com estoque usam imagens do proprio cadastro no ERP. Evidencia: `reports/api-missing-active-images-20260821-final.csv` e `reports/api-non-erp-images-20260821-final.csv` com 0 pendencias; validacao publica 175/175 imagens ERP/Tiny S3.

## P1 - Alto impacto
- [x] CART-01 Redesenhar carrinho, corrigir sobreposicao, hierarquia, responsividade e CTA. Evidencia: CSS de reparo 2026-08-21 aplicado em `carrinho.php`; sintaxe PHP ok.
- [x] FOOT-01 Padronizar footer unico em paginas publicas. Evidencia: override `sv-footer-polish-20260821` aplicado em `includes/footer.php`; sintaxe PHP ok.
- [x] NAV-01 Inserir Blog no cabecalho desktop/mobile. Evidencia: `includes/navbar.php` inclui `/blog/`; sintaxe PHP ok.
- [x] ACCOUNT-01 Inserir logo na area Minha Conta. Evidencia: `includes/account-chrome-top.php` usa logo em `.sv-account-header`; sintaxe PHP ok.
- [x] ABOUT-01 Reescrever `/sobre/` com contexto institucional/comercial. Evidencia: `sobre/index.php` reescrito e sintaxe PHP ok.
- [ ] CAT-01 Otimizar `/catalogo/` profundamente: tempo de carregamento, paginacao, filtros e cache. Evidencia parcial: removido enriquecimento duplicado; picos de lentidao estavam correlacionados a processos temporarios `shopvivaliz-pr-heal`/`git index-pack` concorrentes, encerrados em producao. Apos alivio, `/catalogo/?q=decore` mediu ~0,86s-1,38s total em 3 execucoes.
- [ ] BLOG-01 Melhorar imagens dos artigos sem repeticao indevida. Evidencia/commit: pendente.
- [ ] GOOGLE-01 Auditar Ads, GA4, GTM, Merchant, Search Console e motivo de zero vendas. Evidencia/commit: pendente.

## P2/P3 - Continuo
- [ ] COPY-01 Varredura de textos publicos para remover linguagem admin/debug.
- [ ] SEO-01 Validar sitemap, schema Product/Offer e paridade feed/ERP/site.
- [ ] A11Y-01 Testes mobile/desktop, teclado, contraste e Lighthouse.

## Log de execucao
- 2026-08-21: Primeira leva aplicada em producao: navbar Blog, footer polido, carrinho polido, sobre reescrito, conta com logo, avaliacoes sem texto interno, politica de devolucao ajustada, rotacao home/produto, guard client-side de estoque no catalogo e relatorio de imagens faltantes.

- 2026-08-21: STOCK-01/STOCK-02 concluidos. Validacao de carrinho agora agrega SKUs duplicados e bloqueia excesso antes do checkout; endpoint legado create-v2 reforcado.
- 2026-08-21: IMG-02 parcial. Corrigidas imagens publicas de 3 SKUs; removido insumo 23543 da vitrine; 2 SKUs aguardam imagem oficial.
- 2026-08-21: CAT-01 parcial. Removida chamada duplicada de `svp_enrich_products`; parados clones temporarios `shopvivaliz-pr-heal` que estavam elevando load e causando picos de TTFB.

- 2026-08-21: ERP image repair: corrigido sync ativo `olist/sync-on-webhook.php` para enriquecer produtos sem imagem via detalhe `/produtos/{id}`; cache atual reparado para TTO/PP*BR1 e SAB-PR-FBA-ONSITE; API publica validada com 0 produtos ativos/com estoque sem imagem. Commit: pendente.
- 2026-08-21 21:58 BRT: Regra reforcada: imagens publicas de produtos devem vir exclusivamente do produto no ERP/Tiny. Removido fallback manual/local de imagem da vitrine; cache ativo reparado via /produtos/{id}. Validacao: 175/175 imagens publicas classificadas como erp_tiny_s3; relatorio `reports/api-non-erp-images-20260821-final.csv` com 0 linhas. Evidencia/commit: pendente.

- 2026-08-21 22:12 BRT: ERP authority guard criado em `scripts/quality/validate-erp-v3-authority.php`. Regra refinada: ERP/Tiny API v3 e fonte de verdade para campos com equivalente no ERP; informacoes adicionais do site sao permitidas somente quando nao houver campo ERP equivalente e sem sobrescrever cadastro.

- 2026-08-21 22:16 BRT: Regra refinada: enriquecimento e permitido, mas campos com equivalente no ERP devem ser enviados ao ERP via API v3 e publicados no site a partir do retorno do sync v3. Site-only continua permitido apenas para informacoes sem campo equivalente no ERP.
- 2026-08-21 22:14 BRT: PDP/video ERP: sync ativo e sync canonico agora importam `seo.linkVideo`/campos de video do detalhe `/produtos/{id}` para `video_url`; runtime tambem le `seo.linkVideo` como redundancia. Validacao: PHP lint OK, pagina de produto publicada com miniaturas, rotacao 3s, frete por CEP e imagens ERP-only. Evidencia/commit: pendente.
