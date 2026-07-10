# CHANGELOG — Bugs resolvidos e decisões (leia antes de mexer)

> Antes de investigar um bug ou reescrever algo, procure aqui primeiro. Isso existe para evitar
> que agentes diferentes (incluindo instâncias futuras) percam tempo re-descobrindo o mesmo
> problema, ou pior, reintroduzam um bug já corrigido.
>
> Formato de cada entrada: **Sintoma** → **Causa real** → **Correção** → **Onde**.

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
