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

### 2026-07-18 — Migração completa: todos os usos restantes de API v2 convertidos pra v3 ou removidos [RESOLVIDO, ver PR seguinte a #431]
**Sistema/arquivo:** `olist/complete-oauth-flow.php`, `olist/direct-sync.php`, `olist/sync-agora.php`, `olist/fetch-estoque-v2.php` (renomeado `fetch-estoque-v3.php`), `olist/webhook-receiver.php`, `shop-catalog-export.php`, `scripts/sync-tiny-api.py` (removido), `scripts/export-olist-images-csv.py`
**O que descobri/fiz:** depois da entrada anterior (remoção de `sync-direct-tiny.php`), o usuário pediu pra migrar TUDO que ainda usava v2. Ações:
- `olist/complete-oauth-flow.php`, `olist/direct-sync.php`, `olist/sync-agora.php`: mantida a troca/renovação de token OAuth (não mexi nisso, está correto), mas o PASSO de "buscar produtos" (que chamava `api/v2/produtos.json` e gravava em `logs/olist-products-cache.json`, arquivo morto) foi substituído por uma chamada a `olist/sync-products.php` via `shell_exec()` — agora o fluxo de OAuth de fato popula o catálogo real (`api/catalog/fallback-products.json`) em vez de escrever num arquivo que ninguém lê.
- `olist/fetch-estoque-v2.php` → recriado como `olist/fetch-estoque-v3.php`, usando `GET /estoque/{id}` (v3, `disponivel` calculado certo até pra kit) com Bearer OAuth lido de `storage/private/tokens.json`, em vez do token estático `OLIST_INTEGRADOR_TOKEN` da v2. `olist/webhook-receiver.php` atualizado pra chamar o arquivo novo.
- `shop-catalog-export.php` (proxy usado por `master-production-pipeline.yml` e pelo modo `proxy` de `export-olist-images-csv.py`): trocado `api/v2/produtos.json?token=...` por `public-api/v3/produtos?limit&offset` com `Authorization: Bearer`, lendo o access_token de `storage/private/tokens.json` em vez do placeholder `__OLIST_TOKEN__` embutido no deploy.
- `scripts/sync-tiny-api.py`: removido — era script exploratório de debug (testava token como client_id direto / basic auth contra v2), não referenciado por nada.
- `scripts/export-olist-images-csv.py`: já priorizava v3 corretamente; removido só o fallback `query_token` (v2) que o próprio arquivo já dizia estar "provavelmente bloqueado pela Tiny via Cloudflare".
- `scripts/publish-to-marketplace.py`, `config/secrets.py`, `scripts/setup_secrets.py`, `scripts/verify_secrets.py`, workflows do Shopee: confirmados como falso-positivo (só citam `TOKEN_API_OLIST` como nome alternativo de env var, não fazem chamada v2) — não precisaram de mudança.
**Por quê importa:** depois desta sessão, a única forma de buscar produtos da Tiny no repo é via API v3 OAuth (`olist/sync-products.php` ou os wrappers que agora chamam ele). Se aparecer `api.tiny.com.br/api/v2` ou `api2/` de novo em qualquer grep, é reintrodução por outro agente — trate como bug, não como funcionalidade legítima.
**Ver também:** entrada abaixo (`sync-direct-tiny.php`), `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — `olist/sync-direct-tiny.php` (API v2 legada) REMOVIDO: era o script que vinha sobrescrevendo o catálogo com dados sem imagem
**Sistema/arquivo:** `olist/sync-direct-tiny.php` (deletado), `api/catalog/fallback-products.json`, `olist/sync-products.php` (fonte v3 correta)
**O que descobri:** o catálogo (`fallback-products.json`) ficou revertendo repetidamente pra um estado quebrado — 203 produtos, `sync_source: "tiny_v2"`, `images: []`, sem dimensões/preços/gtin — mesmo depois de eu já ter removido toda a lógica v2 de `olist/sync-products.php`, `includes/tiny-order-push.php` e `scripts/sync-olist-images.py` (ver entradas de PR #427 no changelog/histórico). Rastreado ao vivo com um watcher de auditoria (`scripts/catalog-audit-watcher.py`, polling de mtime) que pegou a mudança em flagrante às 23:31:05 UTC. A causa era um script COMPLETAMENTE SEPARADO, `olist/sync-direct-tiny.php`, que ninguém tinha auditado antes: chamava `https://api.tiny.com.br/api/v2/produtos` direto (sem OAuth, token estático), e gravava por cima do `fallback-products.json` com um formato normalizado diferente (sem `images[]`, sem `sync_source`, sem dimensões/preços — só `id/sku/name/price/stock/image_url` singular). Não havia nenhum hit nos logs do Apache pra esse endpoint (não foi acionado via HTTP), mas os logins SSH do IP `201.17.148.115` tiveram uma rajada de ~4 conexões em 30s exatamente no momento da escrita — evidência forte de outro agente autônomo (Claude/GPT/Gemini, sessão isolada) rodando `php olist/sync-direct-tiny.php` manualmente via SSH, provavelmente reproduzindo um passo antigo de sync sem saber que `sync-products.php` (v3, correto) já existia e era o caminho certo. **Arquivo removido do repo nesta sessão** (PR de remoção) — se outro agente tentar reintroduzi-lo ou algo parecido, desconfie: a única fonte de sync de catálogo válida é `olist/sync-products.php` (OAuth v3).
**Por quê importa:** qualquer agente que "ajude" rodando um script de sync que pareça familiar sem checar primeiro se já existe um caminho v3 oficial pode destruir silenciosamente imagens/dimensões/preços de todo o catálogo em produção. Antes de rodar QUALQUER script de sync de produto/Tiny/Olist, procure primeiro por `olist/sync-products.php` e use esse — não crie nem rode um script paralelo.
**Ver também:** `docs/CATALOG-AUDIT-WATCHER.md`, `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — Varredura completa de scripts com API v2 da Tiny — mapa do que é morto, vivo-mas-inofensivo, e o que falta limpar
**Sistema/arquivo:** vários em `olist/`, `api/catalog/products-erp.php`, `shop-catalog-export.php`, `scripts/sync-tiny-api.py`, `scripts/export-olist-images-csv.py`
**O que descobri:** depois de achar `sync-direct-tiny.php` (entrada acima), o usuário pediu auditoria de TODO uso restante de API v2. Resultado do grep (`api.tiny.com.br/api/v2`, `api2/`, `tiny_v2`, `OLIST_INTEGRADOR_TOKEN`, `TOKEN_API_OLIST`) em todo o repo:
- **Removidos nesta sessão** (não referenciados por nada, escreviam só em `logs/olist-products-cache.json` — arquivo morto que `catalog-runtime.php` nunca lê): `olist/quick-sync.php`, `olist/sync-all-in-one.php`, `olist/get-or-sync-products.php`, `olist/import-with-images.php`, `olist/auto-sync-hourly.php`, `api/catalog/products-erp.php`.
- **NÃO removidos — fazem parte do fluxo de OAuth ao vivo, cuidado antes de mexer:** `olist/complete-oauth-flow.php` (chamado por `olist/handle-callback.php`, `olist/login-form.php`, `olist/process-code.php`), `olist/direct-sync.php` (chamado por `olist/download-images.php`, `olist/import-with-images.php`, `olist/setup-oauth.php`), `olist/sync-agora.php` (chamado por `olist/oauth-callback-simple.php`). Todos os três também gravam só em `logs/olist-products-cache.json` (morto), então não corrompem o catálogo real — mas fazem chamada v2 desnecessária dentro do fluxo de login/OAuth. Se for limpar, remover só a parte de fetch v2 pós-OAuth, sem tocar na troca de token em si.
- **Vivo e ligado por `exec()`, mas inerte no momento:** `olist/fetch-estoque-v2.php` é disparado em background por `olist/webhook-receiver.php` a cada webhook de estoque da Tiny (`exec('php .../fetch-estoque-v2.php > /dev/null 2>&1 &')`). Só grava em `storage/products-cache-ativos.json` (não no `fallback-products.json`), e depende de `OLIST_INTEGRADOR_TOKEN`, que já foi removido do `.env` de produção numa sessão anterior — então hoje só loga "Token integrador não configurado" e retorna sem fazer nada. **Se algum agente reintroduzir esse env var (ex: durante reprocessamento de token OAuth), esse script volta a ficar ativo silenciosamente.**
- **Vivo, protegido por token, fora do caminho do catálogo:** `shop-catalog-export.php` (usado por `.github/workflows/master-production-pipeline.yml`, exige header `X-Squad`) — usa v2 mas só serve o pipeline de imagens IA, não escreve no catálogo real.
- **Fora de escopo desta varredura** (nomes de variável parecidos mas é outro subsistema — pipeline Shopee/marketplace, não catálogo): `config/secrets.py`, `scripts/setup_secrets.py`, `scripts/verify_secrets.py`, `.github/workflows/fetch-shopee-listings.yml`, `.github/workflows/optimize-shopee-listings.yml`, `.github/workflows/sync-olist-6h.yml`, `scripts/export-olist-images-csv.py`, `scripts/publish-to-marketplace.py` — todos só referenciam `TOKEN_API_OLIST` como nome de secret ou fazem export/publish avulso, não escrevem no catálogo real.
**Por quê importa:** o padrão se repete: cada sessão de agente parece ter criado seu próprio script de sync "rápido"/"direto" ao invés de usar `olist/sync-products.php`. Nenhum dos scripts "vivos mas inofensivos" listados acima corrompe o catálogo hoje, mas todos são candidatos a reativar o mesmo bug se alguém reintroduzir a credencial certa ou copiar o padrão. **A única fonte válida pra sincronizar o catálogo público é `olist/sync-products.php` — não crie outra.**
**Ver também:** entrada acima (`sync-direct-tiny.php`), `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — Ciclo autônomo de otimização Shopee (6h) não pôde rodar nesta sessão: sem credenciais, e o bug de 0 otimizações continua ativo há dias
**Sistema/arquivo:** `.github/workflows/optimize-shopee-listings.yml`, `agents/v9.2.85/app/ShopeeListingsOptimizationAgent.php`
**O que descobri:** disparado o ciclo de 6h (prompt "Agente de Otimização Inteligente Shopee") nesta sessão, mas o ambiente não tinha nenhum secret (`TINY_*`, `SHOPEE_*`, `ANTHROPIC_API_KEY` etc. ausentes do `env`) — não dá pra buscar CTR/conversão reais nem aplicar updates aqui, só existe de verdade dentro do runner do GitHub Actions. Conferido também via `actions_list` que o workflow roda "success" todo dia desde pelo menos 07-10, mas os relatórios reais (`optimization-report-20260714/15/16`: erro/0 produtos; `optimization-report-20260718-090909`: 100 produtos buscados, **0 otimizados, 100 skipped** com motivo genérico) mostram que o bug documentado na entrada abaixo (`callAnthropic()`/`optimizeWithAI()` retornando null) continua sem correção — ou seja, o "status:success" verde no relatório do dia é enganoso há pelo menos 5 dias seguidos, não é um caso isolado.
**Por quê importa:** um agente sem credenciais que tentar "cumprir" esse prompt de qualquer jeito correria o risco de inventar dados de CTR/vendas pra parecer que analisou algo real — não faça isso. Se for consertar o bug de verdade (aumentar `max_tokens`, logar a resposta bruta da IA), teste primeiro contra 1-2 produtos com log ativado antes de deixar rodar contra os 100 produtos reais sem supervisão, como já alertado na entrada abaixo.
**Ver também:** entrada abaixo (`optimize-shopee-listings.yml`), `docs/SHOPEE-OPEN-API-V2.md`

### 2026-07-18 — `optimize-shopee-listings.yml`: pipeline roda "com sucesso" mas nunca otimiza nada de verdade
**Sistema/arquivo:** `.github/workflows/optimize-shopee-listings.yml`, `agents/v9.2.85/app/ShopeeListingsOptimizationAgent.php` (`callAnthropic()`, `optimizeWithAI()`)
**O que descobri:** os dois relatórios já gerados por esse workflow (`listings/optimization-report-20260716-102442.json`, `listings/optimization-report-20260718-090909.json`) mostram: dia 16 falhou 100% (`"status":"error"`, token Tiny OAuth2 inválido, 0 produtos); dia 18, com o token corrigido, buscou 100 produtos reais mas **otimizou 0 e pulou os 100**, todos com o mesmo motivo genérico `"Otimização não gerou alterações"`. Isso não é "IA decidiu que os 100 títulos já estavam ótimos" — é `optimizeWithAI()` retornando `null` pra todo mundo. Causa mais provável: `callAnthropic()` chama a Anthropic com `max_tokens => 1024` (linha ~336), mas o prompt (`buildOptimizationPrompt()`) exige título + descrição estruturada de **mínimo 300 chars** + lista completa de atributos + palavras-chave em JSON — plausível que a resposta trunque antes do `}` final, o regex `/\{[\s\S]*\}/u` capture um JSON incompleto, `json_decode` falhe silenciosamente, e o produto vire "skipped" sem nenhum erro visível (o `catch`/fallback não loga a resposta bruta da IA em lugar nenhum, então não dá pra confirmar 100% sem re-rodar com log ativado).
**Por quê importa:** o workflow reporta `"status":"success"` e commita um relatório verde-parecido todo dia, então parece que a otimização está funcionando quando na prática nunca aplicou uma única mudança real em produção desde que foi criado. Quem olhar só o `status` do relatório vai achar que está tudo certo. Antes de "consertar" aumentando `max_tokens` e deixar rodar sem supervisão, vale lembrar que esse script aplica updates reais no Tiny (`applyUpdate()`) pra até 100 produtos por execução — um agente que só bater `max_tokens` pra cima sem revisar o resultado real de uma execução de teste pode começar a reescrever título/descrição de produtos reais em massa sem checagem humana.
**Ver também:** `docs/SHOPEE-OPEN-API-V2.md`

### 2026-07-18 — Webhooks oficiais: Mercado Pago, Melhor Envio e Tiny nao usam o mesmo contrato
**Sistema/arquivo:** `api/webhook-mercadopago.php`, `api/melhorenvio/webhook.php`, `api/webhooks/order-status-update.php`, `api/webhooks/tiny-nota-fiscal.php`
**O que descobri:** Mercado Pago valida `X-Signature` + `X-Request-Id` sobre `data.id` e pode enviar `action`; Melhor Envio assina o corpo bruto com `X-ME-Signature` (HMAC-SHA256 em base64 com o secret do app) e envia eventos `order.*`; a Tiny 2.0 usa `tipo` + `dados.idPedidoEcommerce`/`dados.idVendaTiny`/`dados.idNotaFiscalTiny` e nao os campos legados mais antigos como fonte principal.
**Por quê importa:** se outro agente assumir payload legado, a integracao autentica errado ou ignora o evento oficial, e o fluxo de pedido/frete/NF quebra sem aviso obvio.
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — Tiny: despacho, nota fiscal e categorias agora tem consumo real no backend
**Sistema/arquivo:** `includes/melhorenvio-label.php`, `api/webhooks/order-status-update.php`, `includes/tiny-order-push.php`, `scripts/fetch-tiny-invoice.php`, `scripts/sync-tiny-categories.php`
**O que descobri:** o PUT `/pedidos/{idPedido}/despacho`, o GET `/notas/{idNota}` e o GET `/categorias/todas` já estavam documentados na Olist/Tiny, mas ainda nao eram consumidos de forma operacional. Corrigido: o fluxo de etiqueta agora tenta atualizar o despacho no Tiny depois da geracao da etiqueta; o webhook de status da NF agora extrai `idNota`, consulta a nota e persiste `nf_id/nf_numero/nf_serie/nf_chave_acesso/nf_data_emissao`; e as categorias podem ser sincronizadas para cache local e usadas pelo catalogo.
**Por quê importa:** sem isso, o ERP continuava com o pedido "faturado" mas sem despacho real vinculado, a NF nao ficava persistida para reaproveitamento e o site nao tinha uma fonte real para categorias da Tiny.
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — Tiny/Olist: contato precisa de `codigo`/`fantasia` e pedido precisa de referência de ecommerce para busca ficar completa
**Sistema/arquivo:** `includes/tiny-order-push.php`
**O que descobri:** além de `data` e `vendedor`, o cadastro do contato estava sendo criado sem `codigo` e `fantasia`, e o pedido do site não levava vínculo explícito de e-commerce. Para o fluxo atual do site, isso deixava pedidos recém-importados com dados menos indexáveis na busca do ERP. Corrigido: contato agora recebe `codigo` estável baseado no número do pedido e `fantasia` com o nome do cliente; pedido agora envia `ecommerce.id = 0` e `ecommerce.numeroPedidoEcommerce` com o número do pedido do site.
**Por quê importa:** sem esses campos, o pedido pode entrar no ERP com visibilidade ruim na busca/listagem e parecer "incompleto" mesmo já tendo sido importado.
**Ver também:** `docs/TINY-ERP-API-V3.md`

### 2026-07-18 — RESOLVIDO: pedidos sumindo da lista/busca do Tiny — faltavam `data` e `vendedor`
**Sistema/arquivo:** `includes/tiny-order-push.php`
**O que descobri:** confirmado (mesmo sintoma já visto antes) que pedidos pushados pelo site existiam via API (`GET /pedidos/{id}` retornava 200) mas sumiam da tela "Pedidos de venda" -- nem na busca por nome/CPF, nem na listagem geral "todos". Causa raiz identificada e corrigida: o payload de criação **não enviava o campo `data`** (data da venda -- a Tiny usa essa data pro filtro/ordenação padrão da lista, não a data de cadastro) **nem `vendedor`** (a conta não tinha nenhum vendedor cadastrado, `GET /vendedores` vazio). Corrigido: 1) cadastrado vendedor genérico "Loja Online" (id `369463749`) via UI (não existe endpoint de criação na API v3); 2) `tiny-order-push.php` agora envia `data` (data de criação do pedido local) e `vendedor: {id: 369463749}`. **Validado ao vivo**: pedido de teste criado com esses campos apareceu na listagem geral imediatamente, sem precisar nem buscar.
**Por quê importa:** sem esses dois campos, TODO pedido pushado pelo site ficava praticamente invisível na operação do dia a dia (só achável sabendo o ID exato e usando link direto de edição) mesmo existindo perfeitamente via API. Se pedidos sumirem de novo, conferir primeiro se `data`/`vendedor` continuam sendo enviados antes de suspeitar de outra causa.
**Bônus (revisão completa do payload vs doc oficial)**: também corrigidos ao mesmo tempo -- `obs` → `observacoes` (nome de campo errado, não existe no schema oficial, era ignorado silenciosamente pela Tiny há sabe-se lá quanto tempo); adicionado `enderecoEntrega` (antes não era enviado, pedido ficava sem endereço de entrega definido); adicionado `consumidorFinal: {cpfCnpj, clienteConsumidorFinal: true}` (afeta cálculo de tributação da NF -- **atenção**: quando essa flag está ativa, a Tiny troca o nome exibido na coluna "Cliente" da listagem por "Consumidor Final" em vez do nome real; é comportamento normal da UI pra esse tipo de venda, não indica perda de dado -- o nome real continua no cadastro do contato).
**Ver também:** `docs/TINY-ERP-API-V3.md`, schema oficial em `api-docs.erp.olist.com/api-reference/pedidos/criar-pedido`

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
