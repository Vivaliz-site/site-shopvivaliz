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

### 2026-07-18 — Etiqueta Melhor Envio agora so e comprada depois da NF emitida (nao mais na aprovacao do pagamento)
**Sistema/arquivo:** `api/webhook-mercadopago.php`, `api/webhooks/tiny-nota-fiscal.php` (novo), `api/melhorenvio/generate-label-background.php`
**O que descobri:** o fluxo antigo comprava a etiqueta assim que o Mercado Pago aprovava o pagamento -- antes de qualquer NF existir no ERP, o que inverte a ordem real do processo (deveria ser: pago -> NF emitida -> etiqueta comprada). Criado `api/webhooks/tiny-nota-fiscal.php` pra receber o evento "notas fiscais autorizadas" da Tiny e so ai disparar a geracao de etiqueta (localiza o pedido local por `tiny_order_id` em `storage/orders/*.json`). O trigger antigo foi removido de `webhook-mercadopago.php`.
**Por quê importa:** falta config manual no painel Tiny (o app "Webhooks" so pode ser ligado pela UI, sem endpoint de API) e falta `TINY_WEBHOOK_SECRET` no `.env` -- ate isso ser feito, NENHUMA etiqueta e gerada automaticamente (nem no evento antigo nem no novo). Nao reverter o trigger antigo sem configurar o novo webhook primeiro, ou etiquetas param de ser compradas. O payload exato do evento Tiny tambem nao foi confirmado ao vivo ainda -- se o parsing falhar, o `error_log` grava o payload cru pra ajuste.
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
