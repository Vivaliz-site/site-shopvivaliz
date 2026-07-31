# Auditoria Ferramentas Google - ShopVivaliz

**Data:** 2026-07-19T17:54:09-03:00  
**Agente:** Codex  
**Resultado geral:** INCONCLUSIVO  

## Escopo

Checklist-base auditado:

- `docs/knowledge/traffic-visibility-checklist.md`
- `docs/checklist-trafego-visibilidade-ecommerce.md`

Ferramentas Google consideradas:

- Google Tag Manager
- Google Analytics 4
- Google Ads conversion tracking
- Google Ads API / campanhas
- Enhanced Conversions
- Google Merchant Center / feed
- Google Shopping / Performance Max / YouTube / Gmail
- Google CSS
- Google Search Console
- Google OAuth
- Google Gemini

## Evidencia

### Preparacao

- `git log --oneline -1`: `eceb63c5 auto: sincronizar produtos do ERP`
- `git status --porcelain` registrou working tree sujo antes da auditoria:
  - `M checkout-return.php`
  - `M storage/codex-bridge/state.json`
  - `?? daemon-google-token-renewer.py`
  - `?? includes/google-ads-refresh.php`
  - `?? reports/mcp-local-autostart-2026-07-19.md`
- Nao houve `git pull`, `git merge` ou `git reset`.

### Testes executados

- `php -l includes/analytics-tracking.php`
- `php -l includes/head-analytics.php`
- `php -l checkout-return.php`
- `php -l checkout.php`
- `php -l carrinho.php`
- `php -l catalogo.php`
- `php -l index.php`
- `php -l google-merchant-feed.php`
- `php -l google-shopping-feed.php`
- `php -l sitemap.php`
- `php -l public_html/sitemap.php`
- `python -m py_compile scripts/google_ads_real_readiness.py scripts/mcp-google-ads-readonly.py scripts/google_ads_review_campaigns.py scripts/google_ads_create_search_campaign.py`
- `python scripts/google_ads_real_readiness.py`
- Auditoria de variaveis Google em `.env` sem exibir valores.

## Resultado por ferramenta

| Ferramenta | Status | Evidencia | Observacao |
|---|---:|---|---|
| Google OAuth | PARCIAL | `.env` contem `GOOGLE_OAUTH_CLIENT_ID` e `GOOGLE_OAUTH_CLIENT_SECRET`; `CREDENCIAIS_CRIADAS.md` documenta criacao OAuth 2.0. | Credencial existe, mas segredo aparece em arquivo versionado. Rotacionar. |
| Google Tag Manager | PARCIAL | `includes/analytics-tracking.php` gera GTM via `GOOGLE_TAG_MANAGER_ID`, `GTM_ID` ou fallback `GTM-PHZ55CP3`; `includes/head-analytics.php` incluido em `index.php`, `catalogo.php`, `carrinho.php`, `checkout.php`, `checkout-return.php`. | `.env` local nao contem `GOOGLE_TAG_MANAGER_ID`/`GTM_ID`; usa fallback hardcoded. Validacao externa no Tag Manager nao feita. |
| GA4 client-side | PARCIAL | `includes/analytics-tracking.php` gera `gtag.js`; fallback `G-1H55K1TZ5D`; `js/shopvivaliz-google-events.js` emite `view_item`, `add_to_cart`, `begin_checkout`, `purchase`, `search`. | `.env` local nao contem `GA4_ID`/`GOOGLE_ANALYTICS_ID`; usa fallback hardcoded. Real-time GA4 nao verificado. |
| GA4 Measurement Protocol | FALHOU | `.env` local nao contem `GA4_SECRET`; `sendToGA4()` retorna sem enviar quando o secret falta. | Server-side GA4 nao esta ativo neste ambiente. |
| Google Ads conversion tracking | FALHOU | `python scripts/google_ads_real_readiness.py` retornou `NOT_READY` por falta de `GOOGLE_ADS_ID` e `GOOGLE_ADS_CONVERSION_LABEL`; `.env` local tambem nao contem esses campos. | Codigo existe em `checkout.php` e `checkout-return.php`, mas sem variaveis nao dispara conversao real. |
| Enhanced Conversions | FALHOU | `checkout-return.php` contem bloco `gtag('set', 'user_data', ...)`, mas ele so roda com `GOOGLE_ADS_ID` e `GOOGLE_ADS_CONVERSION_LABEL`; ambos ausentes no `.env` local. | Implementacao preparada, nao ativada. |
| Google Ads API / campanhas | FALHOU | `python scripts/google_ads_real_readiness.py` retornou `NOT_READY missing_or_placeholder_env=GOOGLE_ADS_CUSTOMER_ID,GOOGLE_ADS_DEVELOPER_TOKEN,GOOGLE_ADS_REFRESH_TOKEN,GOOGLE_ADS_ID,GOOGLE_ADS_CONVERSION_LABEL`. | `GOOGLE_ADS_CUSTOMER_ID` aparece em docs, mas nao no `.env` local. Developer token e refresh token nao comprovados. |
| MCP Google Ads readonly | PARCIAL | `.mcp.json` registra servidor `google-ads-readonly`; `scripts/mcp-google-ads-readonly.py` compila sem erro. | Sem credenciais Ads, ferramenta fica limitada a diagnostico local. |
| Google Merchant Center / feed | PARCIAL | `.env` local contem `GOOGLE_MERCHANT_ID`; `google-merchant-feed.php` passa em `php -l`; `robots.txt` aponta feed. | Conta/submissao no Merchant Center nao verificada. |
| Google Shopping / Performance Max | BLOQUEADO_POR_AUTENTICACAO | Checklist exige campanha Shopping/PMax; repo contem configs de campanha e feed. | Ativacao real depende de login/conta Ads/Merchant e credenciais API; nao comprovado. |
| YouTube / Gmail via PMax | BLOQUEADO_POR_AUTENTICACAO | Item aparece no checklist como parte de Shopping/PMax. | Sem campanha PMax comprovada. |
| Google CSS | PENDENTE | Checklist pede avaliar parceiro CSS homologado. | Nenhuma implementacao operacional local encontrada. |
| Google Search Console | BLOQUEADO_POR_AUTENTICACAO | `includes/analytics-tracking.php` suporta `GOOGLE_SITE_VERIFICATION`, mas `.env` local nao contem essa variavel; `sitemap.xml` existe. | Vinculacao do dominio e submissao do sitemap no Search Console nao comprovadas. |
| Sitemap / robots para Googlebot | PARCIAL | `robots.txt` aponta `https://shopvivaliz.com.br/sitemap.xml`; `public_html/sitemap.xml` existe. | `public_html/sitemap.xml` contem poucas URLs estaticas; `sitemap.php` dinamico existe, mas exposicao em producao nao foi verificada nesta auditoria. |
| Schema.org para Google Search | PARCIAL | `index.php` contem JSON-LD com `Product`, `Offer`, `AggregateRating`; `catalogo.php` contem schema. | Cobertura de todas as paginas de produto nao validada nesta rodada. |
| Google Gemini | FALHOU | `.env` local nao contem `GEMINI_API_KEY`; `api/agent/squad-chat.php` referencia Generative Language API. | Sem chave configurada neste ambiente. |
| Gmail SMTP | PARCIAL | `.env` local contem `SMTP_HOST` e `SMTP_USER`; docs citam Gmail. | Nao foi enviado email real nem verificada entrega em inbox. |

## Achados criticos

### 1. Segredo Google OAuth exposto em arquivos versionados

**Status:** FALHOU  

`CREDENCIAIS_CRIADAS.md` e `GITHUB_SECRETS_SETUP.md` estao versionados e contem `GOOGLE_OAUTH_CLIENT_SECRET` em claro. Mesmo que esse segredo seja de OAuth web app, deve ser tratado como comprometido.

Acao recomendada:

- Revogar/rotacionar o client secret no Google Cloud.
- Atualizar somente `.env` privado e GitHub Secrets.
- Remover os valores reais dos arquivos versionados e do historico, se aplicavel.

### 2. Relatorios antigos declaram sucesso sem evidencia atual

**Status:** FALHOU  

`INTEGRAÇÕES-ATIVADAS.md` e `STATUS_OPERACIONAL.txt` afirmam Google Ads pronto/conectado. A checagem atual contradiz isso: o readiness local falha por ausencia de variaveis obrigatorias.

Pela `VALIDATION-POLICY.md`, os relatorios antigos nao devem ser usados como prova de operacao real.

### 3. Google Ads nao esta pronto para campanha real via API

**Status:** FALHOU  

Ausentes no `.env` local:

- `GOOGLE_ADS_CUSTOMER_ID`
- `GOOGLE_ADS_DEVELOPER_TOKEN`
- `GOOGLE_ADS_REFRESH_TOKEN`
- `GOOGLE_ADS_ID`
- `GOOGLE_ADS_CONVERSION_LABEL`

Sem esses campos, nao ha prova de criacao, leitura, ativacao ou medicao real de campanha.

## Conclusao

O codigo local esta bem encaminhado para GTM/GA4, eventos de ecommerce, feed Merchant e diagnostico Google Ads, mas a operacao real das ferramentas Google ainda nao esta comprovada. O unico conjunto comprovado como criado e presente localmente e o OAuth Google, porem ele esta exposto em arquivos versionados e precisa ser rotacionado.

**Auditor externo aceitaria?** Nao, porque Google Ads API, conversao, Enhanced Conversions, GA4 server-side, Search Console e PMax dependem de credenciais/contas externas nao validadas nesta auditoria.

## Atualizacao 2026-07-19T18:25-03:00

### Regenerador Google Ads Token

**Status:** FALHOU_VALIDACAO_TOKEN

Evidencia:

- `daemon-google-token-renewer.py` existe localmente e na VM.
- Cron remoto configurado com lock, log e venv Python:
  - `0 * * * * cd /home/ubuntu/site-shopvivaliz && flock -n /tmp/shopvivaliz-google-token.lock .venv-google-ads/bin/python daemon-google-token-renewer.py --once >> logs/google-token-renewer.log 2>&1`
- `includes/google-ads-refresh.php` existe localmente e na VM; `php -l` sem erros na VM.
- `.venv-google-ads` criada na VM e `google-ads` importou com sucesso.
- `.venv-google-ads/bin/python daemon-google-token-renewer.py --once` na VM retornou erro HTTP 400:
  - `error=invalid_grant`
  - `error_description=Bad Request`

Conclusao: o regenerador esta instalado e agendado no runtime correto, mas o refresh token atual nao e aceito pelo Google. Precisa gerar novo `GOOGLE_ADS_REFRESH_TOKEN` valido para o client/secret atual.

### Google Ads Conversao

**Status:** PARCIAL_COM_GA4_IMPORT

Evidencia no Google Ads:

- Conta ativa auditada: `Shopvivaliz Ltda`, customer ID `528-309-1103`.
- Conversao existente: `www.shopvivaliz.com.br (web) purchase`.
- Origem: `Site (Google Analytics (GA4))`.
- Status de acompanhamento: `Nao ha conversoes recentes`.
- Otimizacao: `Principal`.
- Contagem: `Todas`.
- Janela de conversao de clique: `90 dias`.
- Incluida nas metas da conta: `Sim`.

Ajuste aplicado:

- `.env` local e remoto configurados com `GOOGLE_ADS_CONVERSION_SOURCE=GA4_IMPORT`.
- `scripts/google_ads_real_readiness.py` e `scripts/mcp-google-ads-readonly.py` ajustados para nao exigir `GOOGLE_ADS_CONVERSION_LABEL` quando a conversao vem importada do GA4.

### Google Ads API Center / MCC

**Status:** PARCIAL_TOKEN_TESTE

Evidencia:

- Conta gerenciadora criada/acessada: `shopvivaliz ltda`, ID `634-264-0666`.
- Central de API abriu na MCC e exibiu token de desenvolvedor criado.
- Nível de acesso exibido pelo Google Ads: `Conta de teste`.
- `GOOGLE_ADS_DEVELOPER_TOKEN` gravado no `.env` local, no GitHub Environment `Production` e no `.env` remoto (valor omitido).
- Segundo a documentacao oficial do Google Ads API sobre verificacao de marca, para analise de acesso basico o Google usa a verificacao de marca do projeto Google Cloud associado ao token de desenvolvedor. A documentacao tambem informa que antes da verificacao de marca e necessario associar o token ao projeto Cloud por uma chamada da API.

Pendente externo:

- Solicitar `Acesso básico` na Central de API para operar contas de producao.
- Concluir verificacao de marca/OAuth no Google Cloud se o Google exigir no fluxo de acesso basico.
- Reemitir `GOOGLE_ADS_REFRESH_TOKEN`, pois o atual falha com `invalid_grant`.

### Readiness atual

- Local: `READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED`.
- VM: `READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED` usando `.venv-google-ads/bin/python`.
- Limitacao real: token de desenvolvedor ainda em `Conta de teste`, portanto chamadas em contas de producao podem ser recusadas ate aprovacao de acesso basico.
## Atualizacao 2026-07-19T18:56-03:00

### Verificacao de marca OAuth / Google Ads API

**Status:** COMPROVADO_EM_ANALISE

Evidencia:

- Criada pagina publica especifica para explicar a finalidade do app OAuth: `https://shopvivaliz.com.br/google-ads-api-app`.
- URL publica validada com `curl -fsSI`, retornando `HTTP/2 200`.
- Branding do Google Auth Platform atualizado:
  - App: `ShopVivaliz`
  - Homepage do app: `https://shopvivaliz.com.br/google-ads-api-app`
  - Politica de privacidade: `https://shopvivaliz.com.br/politica-privacidade`
  - Termos: `https://shopvivaliz.com.br/termos`
  - Dominio autorizado: `shopvivaliz.com.br`
  - Logo presente no Branding.
- Modal de problemas anterior marcava: `A pagina inicial nao explica a finalidade do app`.
- Acao executada: selecionado `Corrigi os problemas` e pedido nova verificacao.
- Estado final exibido pelo Google Cloud: `Sua marca esta em analise`.

Arquivos criados/alterados para a correcao:

- `google-ads-api-app.php`
- `.htaccess`

## Atualizacao 2026-07-19T19:24:37-03:00

### Correcoes aplicadas antes de retomar checklist

**Status:** COMPROVADO_PARCIAL

Evidencias:

- Rotacao de imagens em producao:
  - Catalogo: script publico /js/auto-image-carousel.js?v=20260719-2 carregado; data-current-index mudou de 0 para 1 apos 4,2s; primeiras imagens trocaram de URL.
  - Home: produtos em destaque mudaram de URL apos 4,2s; data-current-index mudou para 1.
  - Produto: pagina /produto/kit4r-soprano-rodizios-35mm tinha 5 miniaturas; imagem principal mudou para a segunda URL apos 4,2s.
- Banner da home em producao:
  - Carrossel medido com area visivel aproximada 1179x597.
  - Imagem ativa carregou com naturalWidth=1024 e area visivel maior que zero.
- Checkout estado:
  - Campo #state-input agora e select.
  - Opcao MG exibe Minas Gerais.
  - Pedido e Melhor Envio continuam recebendo UF MG.
  - Payload Mercado Pago transforma UF em nome completo, exemplo Minas Gerais.
- Emails de pagamento:
  - boleto_generated contem URL do boleto e linha digitavel no HTML gerado.
  - payment_link_generated contem URL de checkout Mercado Pago no HTML gerado.
  - Endpoints create-boleto.php e create-preference.php agora enviam email apos persistir o boleto/link e gravam email_sent/email_sent_at para evitar reenvio repetido.
- Responsividade/zoom:
  - Criado css/zoom-responsive.css e incluido em home principal, home alternativa, catalogo, produto e checkout.
  - Teste browser desktop e mobile em home, catalogo e checkout: hasHorizontalOverflow=false.

Validacao tecnica:

- Local php -l: checkout.php, includes/mercadopago-gateway.php, api/emails/send-order-notification.php, api/mercadopago/create-boleto.php, api/mercadopago/create-preference.php, api/emails/test-send.php, index.php, home.php, catalogo.php, produto.php sem erro de sintaxe.
- VM php -l: mesmos arquivos PHP sem erro de sintaxe.
- git diff --check sem erro nos arquivos alterados; apenas avisos de normalizacao LF/CRLF.

Observacao:

- O warning local de PHP sobre extensao curl ausente e do ambiente Windows local, nao de sintaxe dos arquivos.

## Atualizacao 2026-07-19T19:28:00-03:00

### Checklist Google apos auditoria por navegador

**Status geral:** PARCIAL

Itens do checklist de migracao/Google auditados primeiro:

| Item Google | Status | Evidencia |
|---|---:|---|
| Google Search Console: propriedade do dominio | COMPROVADO | Propriedade `shopvivaliz.com.br` acessivel; usuario aparece como proprietario verificado. |
| Search Console: sitemap | COMPROVADO | `sitemap.xml` aparece como processado em 2026-07-19, com 205 paginas encontradas. |
| Search Console: HTTPS | COMPROVADO | Relatorio mostrou 7 URLs HTTPS e 0 URLs nao HTTPS. |
| Search Console: indexacao | PARCIAL | Visao geral mostrou 371 paginas indexadas e 2.269 nao indexadas; precisa acompanhar causas de exclusao. |
| Search Console: tokens de verificacao | PENDENTE | Console alertou 2 tokens de verificacao nao usados na propriedade. Remover somente apos confirmar que nao pertencem a integracoes ativas. |
| Google Analytics 4 | COMPROVADO | Propriedade GA4 acessivel; stream web `shopvivaliz.com.br www.shopvivaliz.com.br` recebendo trafego nas ultimas 48h. |
| Google Analytics: propriedade/codigo no site | COMPROVADO | Home publica contem GA4 `G-1H55K1TZ5D`; Search Console mostra associacao com Analytics. |
| Google Tag Manager | COMPROVADO_PARCIAL | Site publica container `GTM-PHZ55CP3`; instalacao no codigo confirmada. Validacao dentro do Tag Manager nao foi aberta nesta rodada. |
| Google Merchant Center | PARCIAL | Conta `ShopVivaLiz` ID `5381803710` acessivel e associada ao Search Console; origem automatica `shopvivaliz.com.br` ativa a cada 24h. |
| Merchant Center: produtos | FALHOU_PARCIAL | Apenas 2 produtos ativos visiveis; diagnostico apontou problema `Preco do produto ausente` e pagina indisponivel em produto amostrado. |
| Merchant Center: feed dedicado | PENDENTE | `robots.txt` divulga feed Merchant, mas Merchant Center esta usando origem automatica do site. Recomendada fonte primaria por feed dedicado se diagnostico continuar. |
| Google Ads: conta/campanhas | COMPROVADO_SEM_CAMPANHA_ATIVA | MCC `shopvivaliz ltda` ID `634-264-0666` acessivel; Google Ads mostrou `Voce nao tem campanhas ativas`. |
| Google Ads API | PARCIAL_BLOQUEADO | Developer token existe, mas nivel exibido e `Conta de teste`; marca OAuth esta em analise. |
| Google Ads token auto-renew | FALHOU | Regenerador instalado, mas refresh token atual falha com `invalid_grant`; precisa reemitir token valido. |
| Google Business Profile | INCONCLUSIVO | Tela `business.google.com/locations` nao mostrou listagem ShopVivaliz nessa conta/sessao. |
| Google OAuth/Brand Verification | COMPROVADO_EM_ANALISE | Google Cloud exibiu `Sua marca esta em analise` apos pedido de nova verificacao. |

### Acoes recomendadas imediatas

- Merchant Center: corrigir diagnostico de produtos, principalmente preco ausente e paginas indisponiveis; depois avaliar troca da origem automatica para feed dedicado `google-merchant-feed.php`.
- Search Console: investigar as 2.269 URLs nao indexadas e remover tokens de verificacao sobrando apenas com cautela.
- Google Ads: aguardar verificacao de marca e solicitar acesso basico; depois reemitir refresh token e validar regenerador com execucao real sem `invalid_grant`.
- Google Business Profile: confirmar qual conta Google administra o perfil da empresa, pois a sessao atual nao exibiu a listagem.

## Atualizacao 2026-07-19T19:58:00-03:00

### Merchant Center: limpeza e envio do feed dedicado

**Status:** COMPROVADO

Evidencias:

- Fonte antiga `Content API` removida no Merchant Center; a tabela `Fornecidas por voce` passou a exibir `Nenhum resultado`.
- Origem automatica `shopvivaliz.com.br` parada; Merchant exibiu que os produtos automaticos seriam removidos e, depois da confirmacao, mostrou que nao havia produtos automaticos gerenciados.
- Feed publico `https://shopvivaliz.com.br/google-merchant-feed.php` validado em producao:
  - HTTP 200.
  - 177 itens `<item>`.
  - 0 titulos com padrao interno `PRODUTO_...`.
  - 0 ocorrencias de boilerplate `compra mais segura`, `identificacao clara`, `imagens meramente` ou `fotos meramente`.
- Nova fonte por arquivo URL cadastrada no Merchant como `PRODUCTS SOURCE 1`.
- Atualizacao manual acionada no Merchant Center.
- Resultado exibido pelo Merchant:
  - `Total de produtos atualizados`: 177.
  - `Novos produtos adicionados`: 177.
  - `Nomes de atributos`: `Todos reconhecidos`.
  - `Arquivo do seu produto`: `Nenhum problema encontrado`.

Arquivos alterados:

- `includes/product-seo.php`
- `google-merchant-feed.php`
- `produto.php`
- `scripts/audit-product-seo.php`

Validacao tecnica:

- `php -l includes/product-seo.php`: sem erro de sintaxe.
- `php -l google-merchant-feed.php`: sem erro de sintaxe.
- `php -l produto.php`: sem erro de sintaxe.
- `php -l scripts/audit-product-seo.php`: sem erro de sintaxe.
- Auditoria item a item gerada em `reports/product-seo-audit-20260719-225713.csv`:
  - Total auditado: 181 produtos do catalogo.
  - Exportaveis no feed: 177 produtos.
  - Titulos com SKU generico: 0.
  - Alertas restantes: 26, referentes a dados operacionais do cadastro como estoque zerado, SKU ausente, slug ausente, imagem ausente ou preco invalido.

Observacoes:

- A otimizacao SEO foi aplicada produto a produto por regra conservadora: marca real quando detectada, nome real/humano, atributos tecnicos extraidos do proprio item e descricao limpa, sem inventar categoria no titulo.
- Os 4 produtos nao exportados precisam correcao no cadastro-fonte antes de entrarem no Merchant, pois falham em campos obrigatorios do feed.

## Atualizacao 2026-07-19T20:10:00-03:00

### Relacao dos 4 produtos fora do feed enviada por e-mail

**Status:** COMPROVADO

Evidencias:

- Script `scripts/report-merchant-excluded-products.php` executado no servidor de producao.
- Resultado: 4 produtos excluidos do feed por campos obrigatorios ausentes.
- Envio executado por `scripts/send-merchant-excluded-products-email.php`.
- Confirmacao de envio:
  - Enviado para `fredmourao@gmail.com`.
  - Enviado para `atendimento@shopvivaliz.com.br`.
  - Falhas: nenhuma.

Produtos relacionados:

| SKU | Produto | Preco | Estoque | Motivo |
|---|---|---:|---:|---|
| `Parafuso5x16` | Parafuso 5x16 | R$ 0,01 | 499776 | imagem ausente |
| `(sem SKU)` | FLOREIRA ANTIQUE 55 MACCHIATO | R$ 300,18 | 6 | id/sku ausente; imagem ausente |
| `(sem SKU)` | FLOREIRA ANTIQUE 55 ACO CORTEN | R$ 300,18 | 2 | id/sku ausente; imagem ausente |
| `(sem SKU)` | FLOREIRA ANTIQUE 55 CIMENTO QUEIMADO | R$ 300,16 | 4 | id/sku ausente; imagem ausente |

Conclusao:

- Nenhum dos 4 foi excluido por preco ausente.
- O feed Merchant continua correto ao bloquear itens sem imagem ou sem SKU.

## Atualizacao 2026-07-19T20:15:00-03:00

### IndexNow

**Status:** PARCIAL

Evidencias:

- Arquivo de chave publicado e validado publicamente:
  - URL: `https://shopvivaliz.com.br/036e6d865ffc4525b743d6dd53c3cb4a.txt`
  - HTTP 200.
  - Conteudo corresponde a chave configurada.
- Script criado e instalado em producao:
  - `scripts/submit-indexnow.php`
- Sintaxe no servidor:
  - `php -l scripts/submit-indexnow.php`: sem erro.
- Tentativa real de submissao para `https://api.indexnow.org/indexnow`:
  - URLs preparadas a partir do sitemap: 200.
  - Resultado: HTTP 403.
  - Mensagem: `SiteVerificationNotCompleted`.

Conclusao:

- A configuracao local/publica esta instalada.
- A submissao ainda nao foi aceita pelo IndexNow porque a verificacao da chave nao propagou no provedor. Retentar posteriormente sem alteracao manual.
- Retry automatico configurado no crontab de producao:
  - `17 2 * * * cd /home/ubuntu/site-shopvivaliz && /usr/bin/php scripts/submit-indexnow.php 200 >> logs/indexnow-submit.log 2>&1`

## Atualizacao 2026-07-19T20:18:00-03:00

### SEO de imagens e LCP em pagina de produto

**Status:** COMPROVADO_PARCIAL

Evidencias:

- `produto.php` recebeu:
  - `rel="preload" as="image"` para imagem principal.
  - `fetchpriority="high"` na imagem principal.
  - `alt="Imagem adicional de ..."` nas miniaturas da galeria.
- Validacao publica em pagina de produto:
  - HTTP 200.
  - `PRELOAD_IMAGE=1`.
  - `FETCHPRIORITY_HIGH=2`.
  - `ALT_ADDITIONAL=5`.
- Sintaxe em producao:
  - `php -l produto.php`: sem erro.

Observacao:

- Nomes fisicos de arquivos hospedados em S3 continuam dependentes da origem/cadastro-fonte. A melhoria implementada cobre HTML, alt text e prioridade de carregamento.

## Atualizacao 2026-07-19T20:28:00-03:00

### FAQ Schema e estrutura semantica de catalogo/produto

**Status:** COMPROVADO

Evidencias:

- `catalogo.php` recebeu JSON-LD adicional:
  - `WebSite` com `SearchAction`.
  - `FAQPage`.
  - Mantido `CollectionPage`.
- `produto.php` recebeu JSON-LD adicional:
  - `FAQPage` por produto, usando nome, estoque e preco do item.
  - Mantidos `Product` e `BreadcrumbList`.
- Validacao publica:
  - Catalogo: HTTP 200, `FAQPAGE=1`, `SEARCHACTION=1`, `COLLECTIONPAGE=1`.
  - Produto: HTTP 200, `FAQPAGE=1`, `PRODUCT=1`, `BREADCRUMB=1`.
- Sintaxe em producao:
  - `php -l catalogo.php`: sem erro.
  - `php -l produto.php`: sem erro.

## Atualizacao 2026-07-19T20:32:00-03:00

### Busca interna do catalogo

**Status:** COMPROVADO_PARCIAL

Evidencias:

- `catalogo.php` passou a aplicar:
  - normalizacao de acentos.
  - aliases/sinonimos para rodizios, cadeados, assentos, ferramentas, vasos/floreiras e pet.
  - tolerancia basica a erro de digitacao via `levenshtein` para termos com 5+ caracteres.
- Validacao local:
  - `rodizios=72`.
  - `rodizio=72`.
  - `floreira=62`.
  - `vaso=62`.
  - `ferramnta=78`.
  - `comedouro=41`.
- Validacao publica:
  - `/catalogo?q=ferramnta`: HTTP 200, status `78 produtos`.
  - `/catalogo?q=rodizio`: HTTP 200, status `72 produtos`.
  - `/catalogo?q=vaso`: HTTP 200, status `62 produtos`.

Observacao:

- Nao foi integrada ferramenta externa paga. A melhoria e local, deterministica e sem dependencia de conta externa.

## Atualizacao 2026-07-19T20:21:00-03:00

### Google Ads readiness

**Status:** PARCIAL_BLOQUEADO_POR_TOKEN

Evidencias:

- Readiness local:
  - `READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED`
  - Campanha: `Ferramentas-Vasos-ROI10-ABC-2026-07`
  - Orcamento diario: R$ 10,00.
  - CPC maximo: R$ 1,20.
  - Keywords: 8.
  - Headlines: 15.
  - Descricoes: 4.
- Readiness no servidor:
  - Mesmo resultado `READY_FOR_REAL_GOOGLE_ADS_CREATE_PAUSED`.
- Regenerador de token no servidor:
  - Falhou com HTTP 400.
  - Erro Google OAuth: `invalid_grant`.

Conclusao:

- A configuracao de campanha esta pronta apenas para criacao pausada.
- A API Ads ainda nao esta autenticada por causa do refresh token invalido.
- Nenhuma campanha paga foi criada ou ativada.
