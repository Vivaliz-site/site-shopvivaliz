# Worklog completo — Google Ads / Merchant / Search Console / GA4 / SEO — 2026-08-07

Este documento resume as alteracoes e auditorias realizadas nesta sessao no repositorio `Vivaliz-site/site-shopvivaliz`.

## Google Ads — estrutura da campanha

Campanha trabalhada:

`Ferramentas-Vasos-ROI10-ABC-2026-07`

A configuracao foi evoluida de um unico grupo misto para dois grupos de intencao separados:

- `Carrinhos Fercar`
- `Caixas Fercar`

Cada grupo passou a ter:

- palavras-chave proprias;
- negativas proprias;
- CPC padrao proprio;
- landing page especifica;
- RSA com 15 titulos e 4 descricoes;
- `utm_content` proprio;
- paths exibidos coerentes com a intencao.

A campanha continua com protecoes:

- criada pausada;
- limite diario de R$ 10;
- CPC maximo de R$ 0,95;
- apenas EXACT/PHRASE;
- ROI/ROAS guardrail >= 10;
- revisao humana antes de habilitar.

Arquivo principal:

`scripts/google_ads_campaign_live_ready.json`

## Google Ads — criador e readiness

`scripts/google_ads_create_search_campaign.py` foi reforcado para:

- bloquear campanha duplicada pelo mesmo nome;
- criar campanha/grupos/anuncios pausados;
- criar criterios por grupo;
- aplicar negativas de campanha e de grupo;
- gerar URLs finais com UTM por intencao;
- validar host HTTPS ShopVivaliz;
- suportar `path1/path2` especificos por grupo.

`scripts/google_ads_real_readiness.py` passou a validar individualmente cada grupo e bloquear:

- CPC acima do guardrail;
- match type invalido;
- keywords duplicadas;
- conflito positivo/negativo;
- pouca variedade de assets;
- titulos/descricoes duplicados;
- titulos/descriptions fora do limite;
- pouca relevancia semantica;
- pouca intencao de compra;
- landing page desalinhada;
- tracking ausente;
- assumptions insuficientes para ROI 10.

## Google Ads — CI estatico

Foi criado:

`scripts/google_ads_config_lint.py`

E o workflow:

`.github/workflows/google-ads-config-ci.yml`

O lint checa a estrutura sem credenciais Google e impede regressao antes de qualquer readiness/API real.

O workflow foi executado com sucesso apos as alteracoes.

## Google Ads — limitacao de acesso observada

Pelo Opera, a conta Google estava autenticada para varias ferramentas, mas o Google Ads pediu nova autenticacao ao tentar acessar diretamente o cliente:

`528-309-1103`

Por isso, as melhorias foram aplicadas no codigo/configuracao auditada, mas nao foi feita nesta etapa uma mutacao direta da campanha ja existente pela interface.

## Merchant Center

Conta observada:

`ShopVivaLiz 5381803710`

Foi verificado no Merchant Center:

- aproximadamente 178 produtos;
- fonte `PRODUCTS SOURCE 2` processada no dia da auditoria;
- 178 produtos atualizados na execucao observada;
- atributos reconhecidos;
- sem erro de arquivo observado na tela;
- produtos examinados aparecendo aprovados.

O feed de producao esta em:

`google-merchant-feed.php`

Ele publica, quando disponivel:

- id;
- titulo;
- descricao;
- link;
- imagem principal;
- imagens adicionais;
- disponibilidade;
- preco;
- sale_price;
- condicao;
- marca;
- product_type;
- google_product_category;
- GTIN;
- MPN;
- identifier_exists;
- cor/material/tamanho;
- item_group_id;
- custom labels de categoria, faixa de preco e estoque.

## Auditoria live do e-commerce / Merchant / sitemap

O workflow existente:

`.github/workflows/ecommerce-excellence-audit.yml`

foi inspecionado e havia uma execucao real de producao bem-sucedida.

A auditoria live retornou:

- home 200;
- catalogo 200;
- sobre 200;
- contato 200;
- FAQ 200;
- blog 200;
- carrinho 200;
- checkout 200;
- produto de amostra 200;
- sitemap com 205 URLs;
- 179 itens Merchant no auditor live;
- sem severidade/blocker reportado.

A diferenca 178/179 deve ser tratada como fotografia de momentos diferentes entre tela do Merchant e auditor live, nao como erro automaticamente.

## Search Console / sitemap

O Search Console foi aberto para a propriedade de dominio `shopvivaliz.com.br`.

Foi observado um volume relevante de URLs nao indexadas, incluindo historico de 404 e duplicadas/canonicas.

Nao foram criados redirecionamentos cegos. A regra mantida e:

- URL realmente removida sem equivalente deve continuar 404/410;
- redirecionar apenas quando existir substituto semanticamente equivalente;
- reduzir duplicatas por canonical/normalizacao apropriada.

O sitemap de producao e gerado pelo codigo do projeto e a auditoria live confirmou acesso/parse bem-sucedido.

## GA4 / Google Tag / GTM

Foi aberta a propriedade GA4 e observada atividade no stream.

O projeto possui integracao em:

`includes/analytics-tracking.php`

Recursos existentes incluem:

- GA4 browser-side;
- GTM;
- Google Ads ID quando configurado;
- eventos page_view;
- view_item;
- add_to_cart;
- purchase;
- search;
- Measurement Protocol server-side quando `GA4_SECRET` existe.

Na auditoria de producao foram identificados pontos pendentes:

- `GA4_SECRET` ausente no runtime auditado para Measurement Protocol/purchase server-side;
- Google Ads conversion ID/label ainda nao confirmado/publicado no runtime observado.

Portanto, o tracking browser-side existe, mas a conversao server-side/Ads ainda precisa ser confirmada no ambiente produtivo.

## Tags Google

A arquitetura atualmente suporta:

- `GA4_ID` / `GOOGLE_ANALYTICS_ID`;
- `GOOGLE_TAG_MANAGER_ID` / `GTM_ID`;
- `GOOGLE_ADS_ID` / `GOOGLE_ADS_CONVERSION_ID`;
- `GOOGLE_ADS_CONVERSION_LABEL`;
- `GOOGLE_SITE_VERIFICATION`;
- `GA4_SECRET`.

O codigo normaliza IDs numericos de Ads para `AW-...` quando necessario.

## Proximos passos ao autenticar novamente no Opera

1. abrir Google Ads cliente `528-309-1103`;
2. revisar campanha existente e asset strength real;
3. comparar search terms reais com a lista de negativas;
4. conferir conversoes principais/secundarias;
5. confirmar tag Google Ads ativa no site;
6. conferir Merchant diagnostics por item;
7. revisar Search Console Coverage/Pages por motivos prioritarios;
8. confirmar GA4 purchase em DebugView/Realtime/teste real;
9. confirmar GTM publicado e sem workspace pendente;
10. ajustar somente o que tiver evidencia de necessidade.

## Estado final desta sessao

- configuracao de Ads melhorada e protegida por lint/readiness;
- grupos de intencao separados;
- Merchant e feed auditados;
- sitemap e producao auditados;
- Search Console revisado;
- GA4/GTM/tracking revisados no codigo e na conta;
- pendencias de login/credencial explicitamente documentadas;
- nenhuma credencial ou token foi gravado no repositorio.
