# CHANGELOG — Bugs resolvidos e decisões (leia antes de mexer)

> Antes de investigar um bug ou reescrever algo, procure aqui primeiro. Isso existe para evitar
> que agentes diferentes (incluindo instâncias futuras) percam tempo re-descobrindo o mesmo
> problema, ou pior, reintroduzam um bug já corrigido.
>
> Formato de cada entrada: **Sintoma** → **Causa real** → **Correção** → **Onde**.

---

## 2026-07-12 — php-lint quebrado no CI por sintaxe JSON em array PHP

- **Sintoma:** job `php-lint` falhando em todos os PRs com `Parse error: unexpected token ":"` .
- **Causa real:** `includes/analytics-tracking.php` linha 196 — um agente escreveu array PHP com
  sintaxe JSON (`'chave': valor`) em vez de `'chave' => valor` no payload do TikTok Pixel.
- **Correção:** trocado `:` por `=>` nas 9 chaves do array `$payload` (linhas 196–207).
  As linhas 271–272 com `:` são JavaScript dentro de heredoc — válidas, não mexer.
- **Onde:** `includes/analytics-tracking.php`, PR #274.
- **Lição:** rodar `php -l` localmente em qualquer PHP gerado por IA antes de commitar.

## 2026-07-12 — Suite Playwright E2E falhando (5 testes) e bloqueando promoção de código

- **Sintoma:** job "Playwright E2E" falhando em todo PR com 5 testes quebrados; os testes rodam
  contra a PRODUÇÃO (`https://shopvivaliz.com.br`), não contra o código do PR.
- **Causas reais (uma por teste):**
  1. *Homepage*: `locator('header')` pegava o único `<header>` da página — o cabeçalho interno
     do painel FECHADO da Liz (altura 0 = "hidden"). A navbar real era `<nav>`, não `<header>`.
  2. *Navegação de categorias*: asserção `not.toContain(BASE_URL + '/')` é impossível de passar —
     toda URL do site contém esse prefixo.
  3. *Carrinho*: clicar no link do carrinho abre o mini-cart drawer (sem mudar URL) → falso negativo.
  4. *Liz mascote*: seletor `[class*="liz"], [id*="liz"]...` casava 2+ elementos → strict mode violation.
  5. *Preços válidos*: varria a página toda com `text=/R\$ .../` e casava o subtotal "R$ 0,00" do
     mini-cart vazio.
- **Correções:** navbar agora é `<header class="navbar sv-navbar"><nav class="container nav-inner">`
  (semântica correta, sticky preservado — classes CSS inalteradas); `<header class="sv-head">` da Liz
  virou `<div>`; testes reescritos com seletores determinísticos (`.sv-navbar`, `#sv-liz-launcher`,
  `.product-price`) e asserções possíveis. Suite completa: 16/16 passando localmente contra produção.
- **Onde:** `includes/navbar.php`, `public/assets/liz-assistant/liz-assistant.js`,
  `tests/e2e-journey.spec.js`, `tests/precos-catalogo.spec.ts`, PR #274.
- **Lição:** testes E2E que rodam contra produção precisam de seletores específicos (id/classe do
  projeto), nunca genéricos (`header`, `[class*=...]`) — o DOM de produção muda a cada deploy de
  outro agente.

## 2026-07-12 — Camada visual global "dazzle-v1" (melhoria, não bug)

- **O que:** polish visual site-wide via `css/dazzle-v1.css` + `js/dazzle-v1.js`, carregados em
  `includes/navbar.php` (todas as páginas principais), `includes/header.php` (políticas legais),
  `meus-pedidos.php` e `auth/login.php` / `auth/register.php`.
- **Design seguro:** sem seletores `[class*=...]` (ver entrada 2026-07-11), só polish aditivo
  (hover/animação/sombra), sem resets de layout, `prefers-reduced-motion` respeitado, reveal ao
  scroll com failsafe de 4s (nada fica invisível se o IntersectionObserver falhar) e itens de
  carrossel horizontal excluídos do reveal.
- **Onde:** `css/dazzle-v1.css`, `js/dazzle-v1.js`, PR #274. Para evoluir o visual, edite esses
  dois arquivos e faça bump do query string `?v=` nos três pontos de include.

---

## 2026-07-11 — Wildcards CSS reintroduzidos 10x, skeleton/hero quebrado recorrentemente

- **Sintoma:** Hero section, categorias, e cards renderizando com layout destruído — acontecia múltiplas vezes por dia.
- **Causa real:** Wildcard CSS `[class*="..."]` estava sendo reintroduzido continuamente em `css/visual-enhancements.css`.
  O problema havia sido corrigido em 2026-07-09 (PR #226), mas agentes ou processos automáticos reabriam o arquivo
  e reintroduziam o padrão perigoso.
- **Correção PERMANENTE:** 
  1. Instalado **pre-commit hook** (`.git/hooks/pre-commit`) que BLOQUEIA commits contendo `[class*=`, `[id*=`, etc.
  2. Criado documento `CSS-WILDCARD-PREVENTION.md` com guia completo e checklist
  3. Adicionada regra no Git Guardian para detecção secundária
- **Onde:** `.git/hooks/pre-commit`, `CSS-WILDCARD-PREVENTION.md`, `CHANGELOG.md`
- **Lição:** Wildcard CSS é uma **armadilha recorrente**. O hook agora torna impossível commitar wildcards,
  mesmo que um agente/automação tente. Se precisar usar atributo-wildcard (raro), use `:is()` ou `:where()`
  com seletores exatos, nunca `[class*=...]` direto.

---

## 2026-07-09 — Home page com faixas laranja/azul empilhadas, carousel encolhido

- **Sintoma:** hero e carousel da home renderizando com múltiplas faixas de gradiente laranja/azul
  sobrepostas, cards do carousel estreitos e centralizados em vez do layout normal.
- **Causa real:** `css/visual-enhancements.css` continha a regra
  `.hero, .banner, [class*="hero"], [class*="banner"] { background: gradient; padding; text-align:center; }`.
  O selector `[class*="hero"]` casa com **qualquer** classe contendo a substring "hero" —
  `.hero-carousel-section`, `.hero-carousel`, `.hero-slide`, `.hero-content`, `.hero-trust`, etc —
  aplicando o estilo em cascata sobre elementos aninhados.
- **Correção:** removida a regra inteira (`.hero`/`.banner` já tinham estilo completo e correto em
  `css/style.css`; o bloco era redundante e daninho). PR #217.
- **Onde:** `css/visual-enhancements.css`.
- **Lição para o futuro:** NUNCA usar `[class*="..."]` em CSS deste projeto — o projeto usa nomes de
  classe compostos (`hero-carousel`, `hero-slide` etc) que colidem com wildcards. Sempre usar
  seletores exatos.

---

## 2026-07-09 — `includes/footer.php` com dados falsos (conflito de merge)

- **Sintoma:** durante merge de PR de footer compartilhado, conflito add/add em `includes/footer.php`.
  A versão do outro agente tinha: WhatsApp fake (`5511999999999`), redes sociais fake
  (facebook.com/shopvivaliz, instagram etc — não existem), e-mail não verificado, e um
  `<!DOCTYPE html><html><body>` completo dentro de um arquivo pensado para `include` (geraria
  HTML/HEAD/BODY duplicados na página real).
- **Causa real:** agente autônomo gerou conteúdo plausível-porém-inventado em vez de usar dados reais
  do projeto (o WhatsApp real já configurado é `5537999374112`, ver `LOJA_WHATSAPP` no `.env`).
- **Correção:** mantida a versão real (sem redes sociais inexistentes, sem duplicar doctype/html/body).
  PR #188.
- **Lição para o futuro:** qualquer dado de contato, link social, ou métrica em conteúdo gerado deve
  ser verificado contra fonte real (`.env`, banco, ou pergunta direta ao usuário) antes de commitar.
  Nunca inventar números de telefone, e-mails ou URLs de redes sociais "prováveis".

---

## (sessões anteriores, resumo — ver git log para detalhes completos)

- **`.htaccess` quebrado (500/404 em produção):** um commit de debug (`e197ee0`) substituiu o
  `.htaccess` inteiro por um stub de 3 linhas sem rewrite rules, e nunca foi revertido. Restaurado de
  `git show e197ee0^:.htaccess`. Lição: qualquer commit "debug"/"test" que mexe em `.htaccess` deve
  ser revertido explicitamente após o diagnóstico, nunca deixado em produção.
- **Login/cadastro com erro genérico de banco:** causas reais distintas — `last_login` (coluna
  inexistente, deveria ser `updated_at`) e `cpf = ''` colidindo com constraint UNIQUE após o primeiro
  usuário (corrigido para `null`). Lição: erros genéricos de "erro ao conectar ao banco" quase sempre
  escondem uma exception específica — sempre adicionar `error_log()` no catch antes de assumir que é
  problema de conexão.
- **Checkout: sessão de login não "grudava":** `session_start()` chamado tarde demais (depois de HTML
  já ter sido impresso). Lição: `session_start()` sempre no topo absoluto do arquivo, antes de
  qualquer output, especialmente em páginas que fazem `include` de navbar no meio do `<head>`.
- **Checkout: formulário e "carrinho vazio" aparecendo juntos:** `[hidden]` do HTML não vence regra de
  CSS com `display: grid` explícito (especificidade de author stylesheet > UA stylesheet). Lição:
  sempre que uma classe usa `display` explícito, adicionar par `.classe[hidden] { display: none; }`.
- **Estoque zerado/desatualizado no site:** `svp_enrich_products()` e `sv_product_merge_db()`
  sobrescreviam o estoque real (vindo do catálogo/Tiny) com o valor da tabela local `products`, que
  nunca era sincronizada (sempre 0). Lição: ao mesclar duas fontes de dados, só sobrescrever quando o
  valor novo for válido/maior que zero — nunca sobrescrever incondicionalmente.

---

## Como adicionar uma entrada

Ao resolver um bug real (não cosmético, algo que poderia se repetir), adicione uma entrada aqui:
sintoma, causa raiz, correção, arquivo, e uma lição objetiva. Mantenha curto — isso é um índice de
busca, não um relatório.
