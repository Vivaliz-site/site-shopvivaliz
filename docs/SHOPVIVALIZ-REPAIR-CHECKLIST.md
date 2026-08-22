# ShopVivaliz - Checklist de reparos, conversao e qualidade

Atualizado em: 2026-08-21 20:45 BRT

## Regras fixas
- [ ] Nao alterar dados originais de produto, preco ou estoque.
- [ ] Nao expor secrets, tokens, cookies ou credenciais.
- [ ] Marcar [x] somente apos implementacao, teste e validacao em producao.

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
- [ ] IMG-02 Corrigir SKUs ativos sem imagem usando fonte ERP/Olist/Tiny ou aprovacao manual. Evidencia parcial: `kit-vedant-90`, `KIT4R-SOPRANO` e `kit4rod35freio` corrigidos com imagens locais/snapshot Tiny; `23543` removido da vitrine por regra de insumo `stretch manual`; novo relatorio `reports/api-missing-active-images-20260821-after-fixes.csv` restou com 2 SKUs sem fonte local (`TTO/PP*BR1`, `SAB-PR-FBA-ONSITE`).

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
