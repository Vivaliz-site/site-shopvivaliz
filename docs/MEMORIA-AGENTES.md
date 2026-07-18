# Memória compartilhada dos agentes

> **Todo agente (Claude, GPT, Gemini, ou qualquer outro) que trabalhar neste repo deve
> ler este arquivo ANTES de começar, e adicionar uma entrada aqui ao final da sessão se
> tiver aprendido algo não-óbvio.**
>
> Isso existe porque múltiplos agentes autônomos diferentes trabalham neste repo em
> sessões isoladas, sem memória compartilhada entre si. Sem um lugar único combinado,
> o mesmo bug/API/comportamento contra-intuitivo é redescoberto do zero repetidas
> vezes. O objetivo deste arquivo é quebrar esse ciclo: cada sessão deve sair daqui
> mais capaz do que entrou, e a próxima sessão (de qualquer agente) deve começar já
> sabendo o que a anterior descobriu.

## Como usar

- **Antes de investigar um bug ou integrar com um sistema externo**, procure aqui por
  uma entrada relacionada (Ctrl+F pelo nome do sistema/arquivo/sintoma).
- **Ao final de uma sessão**, se você descobriu algo que não era óbvio pelo código
  (um campo de API com nome diferente do esperado, um enum invertido, uma race
  condition, um comportamento assíncrono, uma decisão de arquitetura e o porquê),
  adicione uma entrada nova no topo da lista abaixo, seguindo o formato:

```
### AAAA-MM-DD — Título curto do que foi aprendido
**Sistema/arquivo:** onde isso se aplica
**O que descobri:** o fato em si, direto ao ponto
**Por quê importa:** o que dá errado se você não souber disso
**Ver também:** link pra doc mais funda, se existir (ex: docs/TINY-ERP-API-V3.md)
```

- Entradas específicas e extensas (ex: schema completo de uma API) vão em um arquivo
  dedicado dentro de `docs/` (ex: `docs/TINY-ERP-API-V3.md`) — aqui só o resumo com
  link. Não duplique o conteúdo inteiro aqui, isso deixaria o arquivo ilegível.
- Não remova entradas antigas a não ser que estejam **confirmadas** obsoletas (ex: o
  bug foi corrigido de vez e não pode mais acontecer). Se um problema foi corrigido
  mas pode voltar a acontecer (ex: alguém reverter o fix sem saber), mantenha a
  entrada e marque `[RESOLVIDO em <data>, ver commit/PR]`.

---

## Entradas

### 2026-07-17 — API do Tiny ERP: campos e enums que a maioria assume errado
**Sistema/arquivo:** `includes/tiny-order-push.php`, `daemon-sync-products.py`, qualquer código que chama `api.tiny.com.br/public-api/v3`
**O que descobri:** `situacao` no `POST /pedidos` é um enum inteiro onde `1` significa **"Faturada"** (nota fiscal já emitida), não "Aberta" como o código antigo assumia (comentário dizia `// Aberto`). Também: não existe `idDeposito` solto (é `deposito: {id}`), não existe `formaPagamento` dentro de `pagamento` (só `formaRecebimento`/`meioPagamento`), e `numeroPedido` não aceita string customizada (a Tiny ignora e atribui seu próprio sequencial — use `numeroOrdemCompra` pra referência externa).
**Por quê importa:** todo pedido criado pelo push do site nascia marcado como já faturado sem nunca ter emitido NF de fato — isso é incompatível com qualquer fluxo que dependa do estado real da nota fiscal (ex: só gerar etiqueta de transporte depois da NF emitida).
**Ver também:** `docs/TINY-ERP-API-V3.md` (schema completo, IDs de cadastro desta conta, tabela do enum)

### 2026-07-17 — Produtos "Amazon Onsite" no Tiny NÃO são pedidos do site
**Sistema/arquivo:** relatórios/investigação de pedidos no ERP (`erp.olist.com/vendas`)
**O que descobri:** o canal `ecommerce.nome: "Amazon Onsite"` (a maioria dos pedidos da conta, ~427 de 741 em 30 dias) é venda real dentro do marketplace da Amazon (programa FBA Onsite) — não é o checkout próprio do site shopvivaliz.com.br, apesar do nome sugerir isso. Pedidos genuinamente do site chegam com `ecommerce.id: 0` e `ecommerce.nome: ""` (canal vazio), pois a conta não tem um canal "loja própria" registrado.
**Por quê importa:** é fácil (eu mesmo errei nisso primeiro) concluir errado que "Amazon Onsite = site" e reportar números de vendas errados pro usuário. Pra achar pedidos reais do site, cruzar com `storage/orders/SV*.json` local (número de pedido no formato `SV<17 dígitos>`) em vez de confiar em qualquer filtro de canal do ERP.
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-17 — Pedido só deve ir pro Tiny ERP quando pagamento aprovado
**Sistema/arquivo:** `api/orders/create-v2.php`, `api/orders/process-validated.php`, `api/webhook-mercadopago.php`
**O que descobri:** `create-v2.php` e `process-validated.php` (chamado por todo pedido criado no checkout, pago ou não) empurravam o pedido pro Tiny incondicionalmente, na criação — mesmo pedidos nunca pagos (Pix expirado, boleto não compensado) apareciam no ERP. Removido dos dois; o único lugar que deve empurrar pro Tiny é `api/webhook-mercadopago.php`, gated por `$localStatus === 'payment_approved'`.
**Por quê importa:** poluía o ERP com pedidos fantasmas, e conflita com o princípio "só o que foi pago de fato deve entrar no fluxo fiscal/logístico". `[RESOLVIDO em 2026-07-17, PR #395]`
**Ver também:** —

### 2026-07-17 — Produtos tipo "kit" na Tiny: não confiar em `estoque.quantidade`
**Sistema/arquivo:** `daemon-sync-products.py`
**O que descobri:** produtos com `tipo: "K"` (kit) retornam `estoque.quantidade` do próprio kit via `GET /produtos/{id}`, mas esse valor não reflete a composição real (fica zerado/desatualizado). A fonte correta é `GET /estoque/{id}` → campo `disponivel`, que a própria Tiny já calcula considerando a composição e reserva.
**Por quê importa:** catálogo mostrava kits como "esgotado" quando na verdade tinham estoque calculável pela composição (ou vice-versa). `[RESOLVIDO em 2026-07-17, PR #389/#390 — daemon agora usa /estoque/{id} pra todos os produtos]`
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-17 — Auto-sync do repo cria commits concorrentes que revertem edições em andamento
**Sistema/arquivo:** processo de deploy geral (múltiplos agentes autônomos + hook de auto-commit local)
**O que descobri:** existe um hook/processo que faz commit automático (`auto: sincronizar ...`) muito rápido após qualquer `Edit`/`Write` em arquivo do repo. Se você faz `git checkout -b nova-branch origin/main` e edita um arquivo que outro agente concorrente também tocou, o checkout pode reverter sua edição em andamento silenciosamente (o arquivo volta pro estado do commit mais recente antes de você terminar de editar). Sintoma: você edita um arquivo, confirma que a mudança está lá, mas ao dar `git diff origin/main` ela sumiu.
**Por quê importa:** fácil perder trabalho ou achar que uma mudança foi aplicada quando não foi. Mitigação usada nesta sessão: sempre reconferir com `grep`/`git diff origin/main -- <arquivo>` logo antes de `git push`, e reaplicar a edição imediatamente se tiver sido revertida.
**Ver também:** —

### 2026-07-17 — mbstring pode não estar instalado no PHP de produção
**Sistema/arquivo:** qualquer PHP que rode em `dev.shopvivaliz.com.br` (VM Oracle)
**O que descobri:** chamar `mb_strtolower()`/`mb_substr()`/etc diretamente sem checar `function_exists()` primeiro derruba a página inteira com HTTP 500 em produção — a extensão mbstring não está instalada nesse PHP. O padrão correto já usado em vários lugares do código (`sv_lower()` em `produto.php`) é `function_exists('mb_strtolower') ? mb_strtolower(...) : strtolower(...)`.
**Por quê importa:** causou uma queda real do site (/catalogo e /produto fora do ar por ~15min) nesta sessão. `[RESOLVIDO em 2026-07-17, PR #386]`
**Ver também:** —
