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

### 2026-07-18 — Pedidos sem Vendedor vinculado não aparecem na busca do Tiny (mas existem via API)
**Sistema/arquivo:** `includes/tiny-order-push.php`, busca de pedidos na UI do Tiny (`erp.tiny.com.br/vendas#list`)
**O que descobri:** confirmado de novo (mesmo sintoma já visto em sessão anterior com outro pedido) que um pedido pushado pelo site (id 369458858, número 3022, cliente MARINA FALEIRO) existe de fato -- `GET /pedidos/369458858` retorna HTTP 200 com dados completos, e o link direto `erp.tiny.com.br/vendas#edit/369458858` abre normal -- mas a busca por nome/CPF do cliente na tela "Pedidos de venda" retorna "Sua pesquisa não retornou resultados". Abrindo o pedido, o campo **Vendedor está vazio**. A conta não tem nenhum vendedor cadastrado (`GET /vendedores` retorna lista vazia, confirmado em sessão anterior), então nosso push nunca consegue preencher esse campo.
**Por quê importa:** parece fortemente correlacionado -- pedidos sem vendedor vinculado ficam fora do índice de busca full-text da Tiny, mesmo existindo e sendo editáveis via link direto. Isso é uma limitação/comportamento da própria Tiny (não um bug no nosso código) -- não há workaround do nosso lado sem cadastrar um vendedor genérico na conta (decisão de negócio, não técnica). Se for investigar de novo: não perder tempo comparando payload de push, o pedido já está correto; o gargalo é a ausência de vendedor.
**Ver também:** —

### 2026-07-18 — MERCADOPAGO_WEBHOOK_SECRET REGREDIU pro placeholder antigo (2a vez!)
**Sistema/arquivo:** `.env` do servidor (VM Oracle), `api/webhook-mercadopago.php`
**O que descobri:** o mesmo bug já documentado como "corrigido" em 2026-07-17 (`MERCADOPAGO_WEBHOOK_SECRET=webhookkey123`, placeholder nunca trocado) **voltou a acontecer** em 2026-07-18. Confirmado nos logs reais (`sudo tail /var/log/apache2/shopvivaliz_error.log`) que 8+ tentativas reais do Mercado Pago (`referer: mercadopago.com.ar`) foram rejeitadas com `invalid_signature` antes da correção. O usuário confirmou que o valor no painel MP é o MESMO de antes (`d8f63591fc4fd85348baa468c613df6442bdb524e7e8e0db61f564fbbc018e39`) -- ou seja, **não foi rotação no painel**, foi o `.env` do servidor sendo revertido/sobrescrito por algum processo (auto-sync, restore, outro agente).
**Por quê importa:** enquanto esse secret está errado, TODO pagamento aprovado fica invisível (pedido nunca vira `payment_approved`, nunca vai pro Tiny, cliente nunca recebe email de confirmação) -- silenciosamente, sem erro visível pro usuário final. Antes de investigar "por que o pagamento não confirmou", sempre conferir primeiro se `MERCADOPAGO_WEBHOOK_SECRET` no `.env` do servidor bate com o painel (peça o valor atual ao usuário, não assuma que é rotação). Debug útil: reenviar um webhook real manualmente calculando a assinatura HMAC (`svmp_validate_webhook_signature()` em `includes/mercadopago-gateway.php`) com o secret do `.env` -- se bater e ainda assim os webhooks reais falharem, o secret do `.env` diverge do painel.
**Ver também:** —

### 2026-07-18 — `includes/products-cache.php` fabricava 188 produtos ficticios quando o BD "falhava" (e o check nunca funcionava)
**Sistema/arquivo:** `includes/products-cache.php`, `catalogo-v2.php` (unico caller, orfao -- nenhuma pagina linka pra ele)
**O que descobri:** `obter_produtos()`/`contar_produtos()` tinham um fallback que gerava 188 produtos totalmente inventados (nomes tipo "Premium Camisetas Azul - Confortável", precos aleatorios, estoque `rand(10,200)`, imagem via `via.placeholder.com`) sempre que "o BD nao estava disponivel". Só que o check era `function_exists('Database')` -- `Database` é uma **classe**, não funcao, entao esse check é sempre `false` e o fallback fake **rodava sempre**, nunca tentava o banco real. Corrigido pra `class_exists('Database')` e removido o fallback fabricado por completo -- agora retorna array vazio se o BD falhar (a pagina já trata `empty($produtos)` corretamente).
**Por quê importa:** apesar de `catalogo-v2.php` ser orfao (nenhuma pagina do site linka pra ele hoje), é acessivel por URL direta e mostraria produtos que nao existem de verdade, com precos inventados -- risco real se alguem cair nele. Alem disso o bug `function_exists` num nome de classe é um padrao fácil de repetir em outro lugar do codebase; vale grep por `function_exists\(['"]\w+['"]\)` perto de `new \w+\(\)` se for investigar algo parecido.
**Ver também:** —

### 2026-07-18 — Etiqueta Melhor Envio agora so e comprada depois da NF emitida (nao mais na aprovacao do pagamento)
**Sistema/arquivo:** `api/webhook-mercadopago.php`, `api/webhooks/order-status-update.php`, `api/melhorenvio/generate-label-background.php`
**O que descobri:** o fluxo antigo comprava a etiqueta assim que o Mercado Pago aprovava o pagamento -- antes de qualquer NF existir no ERP, invertendo a ordem real do processo (deveria ser: pago -> NF emitida -> etiqueta comprada). Trigger removido de `webhook-mercadopago.php`. Ao investigar onde plugar o gatilho certo, descobri que **ja existia** um endpoint cadastrado no painel Tiny pra receber o evento de nota fiscal -- `api/webhooks/order-status-update.php` (ver painel: Integrações → API do ERP → gerenciar → aba Notificações → URLs de notificações → campo "URL para envio da nota fiscal"). So faltava agir sobre o evento: adicionado disparo de `generate-label-background.php` quando `$normalized_status === 'nota_fiscal_enviada'`.
**Por quê importa:** eu ia criar um endpoint novo do zero (`api/webhooks/tiny-nota-fiscal.php`) achando que nada existia, o que teria exigido reconfigurar a URL manualmente no painel Tiny à toa -- **sempre conferir a aba Notificações da integração "API do ERP" no painel antes de assumir que um webhook precisa ser criado do zero**. O endpoint certo ja tinha auth (`OLIST_WEBHOOK_TOKEN` via `?token=`) e mapeamento de status prontos, só faltava a ação. Zero config manual pendente -- token e URL já estavam em produção.
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-17 — Shopee/TikTok: scripts de upload fingiam sucesso sem chamar a API
**Sistema/arquivo:** `scripts/execute_marketplace_upload.py`, `scripts/integrations/ftp_uploader.py`
**O que descobri:** `upload_to_shopee()`/`upload_to_tiktok()` liam o CSV de imagens e imprimiam "Upload simulado com sucesso" mesmo com credenciais reais presentes -- nunca chamavam a API de verdade. Existem clientes reais e funcionais já no repo (`scripts/utils/shopee_client.py`, `scripts/utils/tiktok_client.py`) que ninguém tinha ligado a esses scripts. Reescrito pra usar os clientes reais: mapeia SKU → item_id/product_id na loja e sobe as imagens de fato.
**Por quê importa:** o negócio podia achar que produtos estavam sendo atualizados nos marketplaces quando nada acontecia. Testado ao vivo com sucesso (Shopee) apos completar OAuth real. `[RESOLVIDO em 2026-07-17, PR #401]`
**Ver também:** `docs/SHOPEE-OPEN-API-V2.md`

### 2026-07-17 — Shopee OAuth: refresh_token muda a cada renovação, precisa persistir sempre
**Sistema/arquivo:** qualquer integração com `partner.shopeemobile.com`
**O que descobri:** ao chamar `RefreshAccessToken`, a Shopee retorna um `refresh_token` NOVO que substitui o antigo -- o antigo fica inutilizável. Diferente do padrão OAuth de alguns provedores onde o refresh_token e fixo. Access token válido só 4h, refresh token válido 30 dias. Criado `daemon-shopee-token-renewer.py` (roda a cada 3h, mesmo padrão do renovador do Tiny/Olist) pra nunca deixar expirar.
**Por quê importa:** se você guardar só o access_token e reusar um refresh_token antigo, a próxima renovação falha silenciosamente e a integração para de funcionar em ~4h.
**Ver também:** `docs/SHOPEE-OPEN-API-V2.md`

### 2026-07-17 — Codigo/script fora do git some quando o processo reinicia
**Sistema/arquivo:** `scripts/autonomous-orchestrator-loop.sh`, qualquer script chamado por systemd
**O que descobri:** um script real rodando como `shopvivaliz-orchestrator.service` há 2+ dias nunca esteve versionado no git -- só existia no disco da VM, e já tinha sido deletado de lá (só sobrevivia porque o processo ainda tinha o arquivo aberto via file descriptor, recuperável via `/proc/<pid>/fd`). O loop chamava dois arquivos PHP que nunca existiram (`api/autonomous/project-director.php`, `productivity-tracker.php`), rodando havia dias sem fazer nenhum trabalho real (erro engolido por `|| true`).
**Por quê importa:** scripts systemd na VM que não estão no repo são invisíveis pra qualquer auditoria baseada em código, e se perdem pra sempre se o processo reiniciar antes de alguém notar. Sempre conferir `systemctl cat <service>` e `git ls-files <script>` juntos ao investigar um serviço.
**Ver também:** —

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
