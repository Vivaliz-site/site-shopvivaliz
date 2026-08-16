# Reconciliação dos 30 achados do Claude Code — ShopVivaliz

Data: 2026-08-15
Base auditada: `main` em `6db63ff67749257f3a4199303aaa30099bb1896d`
Origem: `analise_shopvivaliz_melhorias.md`, `resumo_executivo_vivaliz.html` e `guia_tecnico_implementacao.md` enviados pelo usuário.

## Regra de decisão

- **RESOLVIDO/EXISTE**: o repositório atual contém implementação objetiva do item.
- **CONFIRMADO**: o problema descrito continua presente no código atual.
- **PARCIAL**: existe implementação, mas ainda cabe teste/aperfeiçoamento.
- **NÃO VERIFICADO**: os documentos não trazem evidência suficiente e o código não permite concluir sem teste de runtime/UX.
- As projeções de impacto dos documentos são hipóteses; não foram tratadas como previsão financeira.
- Não alterar preços de venda nem elevar orçamento de mídia.

## P0 — correções imediatas

| # | Achado original | Situação atual | Evidência/decisão | Ação |
|---|---|---|---|---|
| 1/20 | Menu mobile/hamburger | RESOLVIDO NO CÓDIGO; testar runtime | `includes/navbar.php` já possui botão acessível, `aria-expanded`, abertura/fechamento, clique fora, Escape e breakpoint mobile | Smoke test real em mobile; não reescrever o componente |
| 2 | Login isolado/roxo | CONFIRMADO | `auth/login.php` ainda usa fundo `#667eea -> #764ba2`, sem navbar/footer da loja | Alinhar visual à identidade ShopVivaliz sem tocar na lógica de autenticação |
| 3 | Carrinho sem CTA claro | PARCIAL | Navbar possui link `Carrinho` com `id=nav-cart-link`; o relatório ignorou essa implementação | Validar visibilidade/contador em mobile e desktop |
| 4 | Modal vazio de cupons | RESOLVIDO POR POLÍTICA/CONDICIONAL | fluxo público de cupons é condicional; cupons legados não devem ser publicizados | manter popup fechado quando endpoint não retorna cupons |
| 6/27 | Sem Analytics | FALSO POSITIVO | existem `includes/analytics-tracking.php`, `includes/head-analytics.php` e `js/shopvivaliz-google-events.js` | auditar eventos em produção, não reinstalar GA4 |
| 18 | Sem LGPD/cookie consent | FALSO POSITIVO | `js/privacy-consent-v1.js` implementa consentimento, `gtag consent update`, somente essenciais e persistência | validar carregamento em todas as superfícies e bloqueio pré-consentimento |
| 25 | Checkout simplificado | PARCIAL/NÃO VERIFICADO | checkout existe e já participa dos smokes de produção; documento diz explicitamente que não o testou | teste funcional de conversão, sem reconstrução baseada no relatório |

## P1 — conversão e experiência

| # | Achado original | Situação atual | Evidência/decisão | Ação |
|---|---|---|---|---|
| 5 | Busca avançada | PARCIAL | catálogo já aceita busca; filtros completos não foram comprovados nesta auditoria | inventariar filtros atuais e adicionar apenas gaps reais |
| 6 | Página de produto incompleta | MAJORITARIAMENTE FALSO POSITIVO | `produto.php` já possui catálogo autoritativo, galeria, relacionados, SEO, JSON-LD, breadcrumbs e tratamento de estoque/preço | auditar UI final: zoom, especificações, quantidade, frete e avaliações |
| 7 | Reviews | PROVÁVEL GAP | busca no repo não mostrou sistema público robusto de reviews verificadas | priorizar reviews nativas ligadas a pedido pago/entregue, com moderação e prepared statements |
| 16 | Imagens | PARCIAL | página de produto já usa preload/fetchpriority; otimização global ainda requer medição | medir LCP/bytes antes de conversão em massa |
| 22 | Wishlist | GAP PROVÁVEL | busca por `wishlist` não mostrou implementação de storefront | implementar depois de produto/checkout/medição estabilizados |
| 23 | Carrinho abandonado | JÁ EXISTE, MAS COM CONFLITO COMERCIAL | `api/checkout/track-abandonment.php` persiste abandono server-side; `scripts/send-abandoned-cart-emails.php` existia com cupom `VOLTEI5` de 5% | remover cupom não autorizado e manter recuperação transacional |
| 28 | Testes A/B | FUTURO | não há baseline de conversão confiável apresentado pelos documentos | só iniciar após volume e analytics validados |

## P2 — crescimento/retorno

| # | Achado original | Situação atual | Decisão |
|---|---|---|---|
| 8 | SEO blog | NÃO VERIFICADO | auditar artigos e metadados separadamente |
| 9 | Rastreamento de pedido na home | NÃO VERIFICADO | útil, mas não bloqueia compra |
| 10 | Liz duplicada | NÃO VERIFICADO | testar interface atual antes de alterar |
| 11 | Breadcrumbs | PARCIALMENTE RESOLVIDO | produto já possui BreadcrumbList; validar catálogo/blog visualmente |
| 12 | Newsletter fraca | NÃO IMPLEMENTAR COPY PROPOSTA | documento sugere `5% OFF no primeiro pedido`, incompatível com política atual |
| 13 | Comparação de produtos | FUTURO | baixo impacto relativo no estágio atual |
| 14 | Footer | NÃO VERIFICADO | apenas ajuste de UX depois do funil principal |
| 15 | Destaque 3% | PARCIAL | promoção de 3% já foi validada em runtime; evitar excesso de banners/urgência artificial |
| 17 | Cache navegador/service worker | NÃO VERIFICADO | medir primeiro; não adicionar service worker sem estratégia de invalidação |
| 19 | Terms newsletter | NÃO VERIFICADO | revisar consentimento específico e evidência de opt-in |
| 21 | Loading mobile | NÃO VERIFICADO | skeleton apenas onde houver espera perceptível |
| 24 | Fidelidade | FUTURO | não criar cashback/pontos antes de unit economics e recompra reais |
| 26 | Google Shopping | FUTURO/PARCIAL | integrações Google já existem; validar Merchant/feed antes de expandir mídia |
| 29 | Referral | FUTURO | depende de política comercial própria |
| 30 | Social/TikTok Shop | FUTURO | não priorizar antes de conversão do site |

## Achados técnicos adicionais derivados dos documentos

### 1. Exemplo de reviews do guia é inseguro
O guia concatena `product_id`, `rating`, `text` e `email` diretamente no SQL. Não copiar. A implementação deve usar prepared statements, autenticação/validação de comprador, rate limit, moderação e anti-spam.

### 2. Exemplo de busca do guia é inseguro
O exemplo monta `priceMin`, `priceMax` e categorias diretamente em SQL. Não copiar. Usar parâmetros preparados e allowlist de campos/ordenação.

### 3. Carrinho abandonado já possui arquitetura correta no servidor
O repositório atual registra abandono em `checkout_abandonments` com upsert e token idempotente. O guia propunha depender do navegador/localStorage para disparar e-mail, abordagem inferior à implementação já existente.

### 4. Conflito comercial encontrado e corrigido nesta branch
O remetente de carrinho abandonado usava `VOLTEI5` e assunto com `5% OFF`. Isso conflita com a política aprovada, em que o 5% pessoal é emitido após a primeira compra paga/aprovada. Nesta branch, o e-mail foi alterado para lembrete transacional sem desconto.

## Ordem prática recomendada

1. Corrigir identidade visual do login sem mudar autenticação.
2. Rodar smoke real do menu mobile e carrinho/checkout.
3. Validar GA4/GAds events no runtime (`view_item`, `add_to_cart`, `begin_checkout`, `purchase`).
4. Auditar PDP visual e completar somente gaps reais.
5. Implementar reviews verificadas.
6. Auditar/expandir filtros do catálogo.
7. Wishlist.
8. Evoluções secundárias (SEO, tracking, fidelidade, referral, social).

## Não fazer

- Não publicar `VIVALIZ10`, `PRIMEIRA10`, `VOLTEI5` ou qualquer claim de 10%/5% de primeira compra que conflite com a política vigente.
- Não alterar preço de venda dos produtos.
- Não elevar orçamento de mídia.
- Não copiar SQL inseguro dos documentos.
- Não usar os percentuais de ROI/conversão dos documentos como forecast sem baseline e experimento.
