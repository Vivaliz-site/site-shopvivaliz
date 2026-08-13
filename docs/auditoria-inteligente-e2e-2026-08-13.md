# Auditoria inteligente E2E — 2026-08-13

## Escopo e método

Auditoria técnica, operacional e visual sobre a `main` ativa do ShopVivaliz, combinando inspeção de código, gates do repositório, Ecommerce Excellence Audit estático/live, Storefront Browser Audit em desktop/mobile e revisão de fluxos críticos. A auditoria não executa cobrança, estorno, pedido real, alteração de preço/estoque ou publicação externa para produzir evidência.

Status usados:

- **VALIDADO** — há evidência automatizada ou inspeção direta do fluxo atual.
- **CORRIGIDO** — defeito encontrado e correção integrada/encaminhada com regressão.
- **PARCIAL** — fluxo coberto, mas depende de evidência externa adicional.
- **BLOQUEIO EXTERNO** — exige credencial, conta, DNS/plataforma ou ação fora do código.
- **DÍVIDA ESTRUTURAL** — não é incidente ativo, mas deve ser reduzida em mudanças pequenas.

## Matriz de cobertura

| Área | Status | Evidência / resultado |
|---|---|---|
| Home, catálogo, produto, carrinho, checkout, blog e páginas institucionais | VALIDADO | Ecommerce Excellence live e Browser Audit com HTTP esperado e renderização desktop/mobile. |
| Carrinho vazio / pré-hidratação | CORRIGIDO | CTA de checkout não fica utilizável antes dos itens; promessa de frete não aparece antes da hidratação. |
| Checkout | CORRIGIDO | Removida urgência visual que dizia haver reserva antes do envio; rádios de pagamento permanecem focáveis e têm foco visível. |
| Criação de pedido | VALIDADO | Entrada canônica usa fluxo validado, recalcula preço/estoque, exige cotação de frete assinada e cria reserva somente no momento correto. |
| SEO de produto | CORRIGIDO | Orçamento do `<title>` considera o sufixo ` | Vivaliz`; Merchant Feed mantém limite próprio. |
| Sitemap / robots / Merchant feed | VALIDADO | Auditor live passou; sitemap e feed permanecem parseáveis. |
| Admin Automação IA Multi-Canal | CORRIGIDO | Removidos KPIs/rotas demonstrativos; atalhos apontam para módulos canônicos e health check consulta endpoint real. |
| AI Image Studio / otimização de catálogo | VALIDADO | QA cobre workflows JS/PHP, identidade, staging/revisão e operação profissional; publicações seguem fail-closed. |
| Olist/Tiny OAuth | VALIDADO | Rotação foi consolidada em store/daemon canônico; monitor foi tornado read-only e atualizado para ler a fonte autoritativa. |
| CI, governança e política | VALIDADO | QA, Quality Gate, Repository Governance, Policy Engine, History Integrity, Autonomy Boundary e auditoria de agentes executados nas correções desta rodada. |
| Storefront mobile | VALIDADO / CORRIGIDO | Browser Audit em viewport mobile; ajustes em alvos de toque, pagamento, widgets globais, consentimento e imagens de categorias. |
| Imagens das categorias da home | CORRIGIDO | Seleção prioriza foto real do catálogo, evita Unsplash/repetição e possui auditor Playwright dedicado. |
| Prova social da home | CORRIGIDO | HTML server-side deixa de entregar depoimentos demonstrativos; `/api/testimonials.php` continua sendo a fonte de avaliações publicadas. |
| `AggregateRating` estático da home | CORRIGIDO | Objeto histórico sem fonte auditável é removido no HTML final da rota `/`. |
| Newsletter da home | CORRIGIDO | Removido falso `alert()` de sucesso sem persistência; enquanto não existir backend comprovado, a home mostra CTAs reais para catálogo/atendimento. |
| Consentimento de cookies | VALIDADO / CORRIGIDO | Escolha continua persistida e Consent Mode é atualizado; layout mobile foi compactado sem reduzir alvos de toque. |

## Achados estruturais que não devem ser “corrigidos” às cegas

1. **Repositório grande e duplicado** — o auditor estático ainda registra centenas de grupos de conteúdo idêntico e dois VSIX rastreados somando aproximadamente 50 MB. A remoção deve ser isolada porque ferramentas locais podem depender desses pacotes; issue #643 acompanha o saneamento.
2. **Referências aparentes a assets ausentes** — parte dos warnings vem de exemplos/vendor ou rotas virtuais, como feed/status. Exigir prova de rota antes de remover ou criar arquivo para silenciar scanner.
3. **Arquivos `test-*`/`debug-*` no document root** — a `.htaccess` já bloqueia padrões públicos com 404. Permanecem dívida de organização, não evidência de exposição ativa.
4. **Monólito `index.php`** — ainda concentra lógica de catálogo, apresentação e fallbacks. A estratégia segura é extrair seções em PRs pequenos e cobertos, não reescrever a home inteira em uma rodada.

## Bloqueios e dependências externas já documentados

- Proteção nativa de `main` / regras de aprovação: issue #657.
- Google Ads OAuth/configuração de produção: issue #770.
- TikTok Shop e Amazon SP-API: issue #756.
- Cobertura/indexação no Search Console: issue #639.
- Investigação de tráfego GA4 internacional/anômalo: issue #640.
- Operação Merchant Center / frete / avaliações: issue #641.
- Pipeline Shopee 100% do catálogo: issue #536.

Esses itens não devem ser marcados como resolvidos por alteração cosmética no repositório; exigem evidência da plataforma correspondente.

## Regressões adicionadas nesta rodada

- `tests/site-ops-visual-integrity-test.php`
- `tests/home-category-image-audit.mjs`
- `tests/home-trust-sanitizer-test.php`

O objetivo é impedir retorno de estados falsos no Admin/home, urgência enganosa no checkout, perda de acessibilidade, títulos SEO excessivos, imagens genéricas quando há foto real e regressões de confiança no HTML server-side.
