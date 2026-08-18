# 🤖 Agentes Autônomos — Memória Consolidada

> **🔴 LEIA ISTO ANTES DE COMEÇAR**
>
> Múltiplos agentes diferentes (Claude, GPT, Gemini, etc) trabalham aqui em sessões isoladas **sem memória compartilhada**. Sem um lugar único, o mesmo bug é redescoberto do zero repetidas vezes.
>
> **Este arquivo é o repositório centralizado** de tudo que qualquer agente deve saber antes de mexer no repo.

---

## 📋 Como Usar Este Arquivo

### Antes de começar uma sessão
1. **Procure por seu problema** (Ctrl+F): API, nome de arquivo, sintoma
2. **Leia a entrada relevante** se encontrar algo parecido
3. **Você economiza horas** (não redescobre o mesmo bug)

### Ao terminar uma sessão
1. **Descobriu algo não-óbvio?** Adicione uma entrada no topo da seção correspondente
2. **Formato**:
```
### AAAA-MM-DD — Título curto do que foi aprendido
**Sistema/arquivo:** onde aplica
**O que descobri:** fato direto, nada vago
**Por quê importa:** o que dá errado sem isso
**Ver também:** link pra doc dedicada, se houver
```

3. **Não remova entradas** antigas a não ser que estejam **confirmadas obsoletas**

---

## 🚫 Regras Obrigatórias para Agentes

### Diagnóstico
- ✓ Identificar o erro antes de sugerir solução
- ✓ Registrar HTTP method, URL, status, response body, etapa do fluxo
- ✓ Não tratar 404, 405, 500, CORS, DNS como o mesmo problema
- ✗ **Nunca** declarar que produção/deploy/banco/preço/imagem estão certos **sem teste verificável**

### Segurança
- ✗ **Nunca** hardcodar, registrar ou exibir senhas, tokens, chaves de API
- ✗ **Nunca** contornar CORS, autenticação, controles de acesso
- ✗ **Nunca** deletar em FTP ou banco sem backup + autorização explícita
- ✓ Usar sempre variáveis de ambiente ou GitHub Secrets

### Dados
- ✗ **Nunca** inventar preço, estoque, frete, imagem, disponibilidade
- ✗ **Nunca** alterar campos comerciais sem evidência da fonte oficial
- ✓ Vincular imagens por SKU ou ID da origem (nunca by-name)
- ✓ Distinguir falha de interface de falha de sincronização

### Atualizações
- ✓ Produza atualizações cumulativas (permitir pular versões)
- ✓ Inclua automaticamente SQLs, migrations, reparos de vínculo
- ✓ Torne migrations idempotentes (rodar 2x = rodar 1x)
- ✗ **Nunca** exija clique manual pra concluir instalação

### Finalização Obrigatória
- ⚠️ **Ao finalizar alterações, faça sempre Commit, PR e Merge (Toda alteração deve ser validada de forma visual e funcional pelo navegador, sem scripts, e seguir este fluxo)** (Obrigatório)

---

## ✅ Autorização operacional vigente — 2026-08-01

O proprietário autoriza agentes com capacidade técnica e acesso válido a validar entregas no navegador real, deixar evidências prontas para revisão, aprovar PRs, fazer merge e acionar ou executar deploy sem nova aprovação explícita, quando os checks e as proteções do repositório permitirem.

Fluxo padrão: após validar e preparar o PR, o agente conclui o merge autorizado e acompanha o Quality Gate, o deploy automático e o smoke test até registrar o resultado final, sem pedir confirmação intermediária.

Essa autorização não permite force-push, bypass de branch protection, exposição de secrets, cobranças reais ou exclusões destrutivas fora do escopo. O agente ainda deve confirmar evidências independentes, SHA/checks do PR e, após publicação, release ativa, logs e smoke test. Se a plataforma bloquear uma etapa, não contornar: registrar como **INCONCLUSIVO**. A fonte normativa completa é `REGRAS-AGENTES-CENTRALIZADAS.md`.

---

### 2026-08-11 — Catálogo público (`/catalogo`) renderizava vazio: estoque de TODOS os produtos ativos zerado por dois bugs em cadeia
**Sistema/arquivo:** `olist/sync-on-webhook.php`, `olist/fetch-estoque-v3.php`, `api/catalog/products.php`, `scripts/products-active-sync-loop.sh`
**O que descobri:**
- `api/catalog/products.php` aplica `available_only=true` (só `stock>0`) automaticamente sempre que o `Referer` é `/catalogo` (linha `$isStorefrontCatalog`) — isso é intencional (não mostrar produto sem estoque na vitrine principal), mas significa que **qualquer bug que zere `stock` derruba a vitrine inteira silenciosamente**, mesmo com `total` de produtos ativos correto (o header "N produtos." não usa esse filtro, só o grid). Sintoma enganoso: contagem certa no topo, grid vazio, sem erro de console, API retornando 200 com JSON válido — só falta o teste com `available_only`/`Referer` igual ao do navegador pra reproduzir (`curl` puro sem Referer não reproduz).
- **Bug 1:** `olist/sync-on-webhook.php` (a listagem em lote `GET /produtos`) preenchia `estoque_disponivel` direto do campo `estoque.quantidade` da resposta — mas esse campo **não é confiável na listagem em lote**, vem sempre zerado (confirmado ao vivo: 187/187 itens com `estoque.quantidade` zerado, mesmos produtos com estoque real >0 via `GET /estoque/{id}`). Doc já avisava disso pra kits (`docs/TINY-ERP-API-V3.md`, seção "GET /produtos/{id} vs GET /estoque/{id}"), mas na prática vale pra TODOS os produtos na listagem em lote, não só kits.
- O fix correto pra estoque real já existia — `olist/fetch-estoque-v3.php`, que chama `GET /estoque/{id}` (campo `disponivel`, calculado certo pela própria Tiny) — mas só roda quando `!isset($item['estoque_disponivel'])`. Como o Bug 1 sempre preenchia essa chave (mesmo que com 0), o enriquecimento **nunca disparava**. Corrigido parando de preencher `estoque_disponivel` em `sync-on-webhook.php`, deixando a chave ausente pra `fetch-estoque-v3.php` sempre rodar.
- **Bug 2 (mascarava o Bug 1 mesmo depois de corrigido):** `fetch-estoque-v3.php` também usava `file_get_contents()`/`stream_context_create()` (mesma classe de bug já documentada abaixo pra `sync-on-webhook.php`) — mas o sintoma aqui não foi 401 direto, foi pior: a função `fev3_env()` carrega token do `.env` OK, mas depois carrega `storage/private/tokens.json` **sem checar `getenv() === false`** (diferente do loop do `.env`, que tem essa guarda) — sobrescrevendo incondicionalmente o token válido do `.env` com o token de `tokens.json`, que estava **expirado há 5h** (`expires_at` no passado). Resultado: 401 em 100% das 187 chamadas, sempre no token errado, mesmo com `.env` perfeito. Só foi visível adicionando `error_log` no ponto de falha (antes disso, falha silenciosa retornava `null` sem nenhum log). Corrigido com a mesma guarda `getenv($k) === false` no loop de `tokens.json`.
- `scripts/products-active-sync-loop.sh` nunca chamava `fetch-estoque-v3.php` (só `sync-on-webhook.php` + `sync-daemon-to-db.php`) — mesmo depois de consertar os dois bugs acima, o loop de produção não ia enriquecer nada sem essa chamada adicionada explicitamente. `olist/webhook-receiver.php` (webhook real da Tiny) já disparava os dois scripts corretamente em paralelo — só o polling manual (`products-active-sync-loop.sh`) estava incompleto.
- Resultado final confirmado ao vivo: 187/187 itens enriquecidos, 160 produtos `active=1` com `stock>0` no banco (era 1 antes), grid de `/catalogo` renderizando produtos reais com imagem/preço/estoque no navegador.
**Por quê importa:** os três bugs se mascaravam em cadeia — corrigir só um dos dois primeiros ainda dava "Enriquecidos 0" sem pista nenhuma do motivo real (parecia rate-limit pela demora de ~1s/item, mas era retry silencioso em request 401). Se `/catalogo` ou qualquer página com `available_only` voltar a mostrar grid vazio com contagem de produtos correta, comece checando `estoque_disponivel` no cache (`storage/products-cache-ativos.json`) antes de suspeitar do frontend.
**Ver também:** entrada abaixo "Sincronizacao de produtos ativos/inativos..." (mesma família de scripts, bug diferente — aquele é sobre `active`, este é sobre `stock`), `docs/TINY-ERP-API-V3.md`

### 2026-08-11 — Webhook de pedido/rastreio/nota fiscal (`api/webhooks/order-status-update.php`) rejeitava 100% das chamadas por falta de token
**Sistema/arquivo:** `api/webhooks/order-status-update.php`, `.env` (`shopvivaliz-deploy/shared/.env`), painel `erp.olist.com/integracoes#/ecommerce/edit/31816` (aba Notificações)
**O que descobri:** `OLIST_WEBHOOK_TOKEN`/`ERP_WEBHOOK_TOKEN` nao existiam no `.env` de producao — nenhuma das duas. O codigo faz `if (empty($webhook_token) || ...) { http_response_code(401); }`, entao TODA chamada era rejeitada, sempre, independente do token que a Tiny mandasse na query string (`?token=...` — e' assim que o token e' passado, porque o painel de webhooks da Tiny so aceita URL por evento, sem campo de header customizado). `logs/webhook.log` confirmou: eventos `estoque`/`preco`/`atualizacao_pedido` chegavam normalmente ate 2026-07-14/20, sumiram depois — bate com `docs/TINY-WEBHOOKS-SETUP.md` avisando que um token antigo "deve ser considerado comprometido e revogado no provedor" (alguem removeu o token comprometido do `.env` mas nunca colocou um novo). Corrigido gerando um token novo (`openssl rand -hex 32`), adicionando `OLIST_WEBHOOK_TOKEN=...` ao `.env`, e atualizando as 3 URLs correspondentes no painel Olist (`urls_webhook_terceiros.situacao_pedido`, `.rastreio`, `.nota_fiscal`) com `?token=<novo>` no final. Testado com curl real: `HTTP 404 "Order not found"` para um pedido fake confirma que a autenticacao passou (compare com 401 antes da correcao).
**Por quê importa:** rastreio, nota fiscal e status de pedido pararam de atualizar automaticamente por ~3 semanas sem nenhum erro visivel no admin — o pedido so ficava "parado" no status antigo. Se um agente futuro achar pedidos com status desatualizado, confira primeiro se `OLIST_WEBHOOK_TOKEN` existe no `.env` antes de investigar qualquer outra coisa.
**Ver também:** `docs/TINY-WEBHOOKS-SETUP.md`

### 2026-08-11 — Sincronizacao de produtos ativos/inativos com o Tiny/Olist estava completamente ausente
**Sistema/arquivo:** `sync-daemon-to-db.php`, `olist/sync-on-webhook.php`, `olist/webhook-receiver.php`, `api/webhooks/order-status-update.php`, `scripts/products-active-sync-loop.sh`, `daemon-token-renewer.py`
**O que descobri:**
- **Nenhuma versao da API do Tiny/Olist tem evento de webhook para produto ativado/desativado.** Confirmado nas docs oficiais: https://tiny.com.br/api-docs/api2-webhooks (v2: so vendas, pedidos enviados, rastreio, nota fiscal, precos, estoque, cotacao de frete) e https://olist.mintlify.app/documentacao/webhooks/webhooks (v3: so vendas, pedidos enviados, estoque, nota fiscal). Sincronizar `products.active` com o ERP so pode ser feito por polling periodico, nunca por webhook — nao perca tempo tentando achar esse evento de novo.
- **`sync-daemon-to-db.php` tinha nomes de coluna errados** (`olist_product_id` em vez de `olist_id`, `category`/`is_published` que nem existem na tabela `products`) e **nunca desativava produtos** que saiam da lista de ativos do Tiny — so fazia UPSERT dos que vinham no cache, nunca um DELETE/UPDATE dos que sumiram. Resultado real encontrado em 2026-08-11: 247 produtos com `active=1` no banco, sendo 197 sem nenhum `olist_id` (sem rastro de origem no ERP) e, dos 50 vinculados, 47 ja tinham saido da lista de ativos do Tiny havia tempo. Corrigido com um `UPDATE ... WHERE olist_id NOT IN (<lista atual de ativos>) AND active=1` apos o upsert.
- **`storage/products-cache-ativos.json` fica em `shared/storage/` (symlink), mas o arquivo especifico e' criado com owner `www-data:www-data` e permissao `644`** — um processo rodando como usuario `ubuntu` (ex: um systemd service com `Group=www-data`) NAO consegue escrever nele mesmo com o grupo certo, porque falta bit de escrita pro grupo. `chmod 664` resolve. Sintoma: `sync-on-webhook.php` sempre retorna exit code 0 mesmo falhando internamente (bug de design — nunca confie no exit code desse script, sempre confira o conteudo do cache).
- **CAUSA RAIZ real do 401 em `GET /produtos` (NAO e' permissao nem token):** `olist/sync-on-webhook.php` usava `file_get_contents()` com `stream_context_create()` para chamar a API v3 do Tiny, e essa combinacao especifica leva 401 da Cloudflare do Tiny **mesmo com token 100% valido e permissoes corretas** (confirmado: `GET /public-api/v3/info` e `GET /public-api/v3/produtos` com o MESMO token, via cURL, retornam 200 imediatamente). Antes de chegar nessa conclusao, verificamos e descartamos: token expirado (nao estava — JWT decodificado mostrava `roles.tiny-api` com `produtos-leitura`), permissao do app OAuth (`erp.olist.com/aplicativos_api#/edit/351`, todas as permissoes de "Produtos" ja estavam marcadas: Leitura/Incluir e editar/Excluir). **Corrigido trocando `file_get_contents`+`stream_context_create` por cURL** em `olist/sync-on-webhook.php`. Se outro endpoint do Tiny/Olist comecar a dar 401 sem motivo aparente, teste primeiro com cURL antes de suspeitar de token/permissao — o Tiny parece bloquear especificamente clientes HTTP que nao mandam os headers que um cURL/navegador manda por padrao (provavelmente `User-Agent` ou algo no handshake).
- **Bug secundario em `sync-daemon-to-db.php`:** o `ON DUPLICATE KEY UPDATE` nao incluia `olist_id=VALUES(olist_id)` — quando um produto ja existia no banco por SKU mas sem `olist_id` (os "orfaos" sem rastro do ERP), o UPSERT batia no UNIQUE KEY do `sku`, caia no branch UPDATE, e o `olist_id` continuava NULL para sempre, mesmo apos "sincronizar com sucesso". Corrigido adicionando `olist_id=VALUES(olist_id)` no UPDATE clause.
- Resultado final aplicado em 2026-08-11: 187 produtos ativos no Tiny, todos agora com `olist_id` vinculado e `active=1` no banco (bate com a contagem esperada de ~185-188). Restam 20 produtos "orfaos" genuinos (sem SKU correspondente no Tiny) e 48 inativos.
- Servico `shopvivaliz-products-active-sync.service` (`scripts/products-active-sync-loop.sh`, loop com `sleep 10800` = 3h) esta **ativo e rodando** — confirmado via `journalctl` completando um ciclo real com "Sincronizados: 187" e "Ciclo concluido; dormindo 10800s".
**Por quê importa:** o site nunca refletiu corretamente quais produtos estao ativos no ERP desde que o daemon foi escrito (nomes de coluna nunca bateram com o schema real, e depois de corrigir isso, um bug de rede diferente — `file_get_contents` vs cURL — continuava mascarando tudo como falha de credencial). Nao e' uma regressao recente, e' um recurso que nunca funcionou de ponta a ponta. Qualquer agente que "descobrir" produtos inativos aparecendo como ativos, ou um 401 aparentemente de token/permissao numa chamada `file_get_contents` para a API do Tiny, deve ler esta entrada antes de reinvestigar do zero.
**Ver também:** `docs/TINY-WEBHOOKS-SETUP.md`, `scripts/products-active-sync-loop.sh`

### 2026-08-11 — AI Image Studio + Catalog Optimization: bugs reais vs. bloqueios de conta (validados com chamadas diretas às APIs, fora do código)
**Sistema/arquivo:** `admin/ai-image-studio/*`, `admin/catalog-optimization/*`, `scripts/ai-routines-production-smoke.php`, `.env` (`shopvivaliz-deploy/shared/.env`)
**O que descobri:**
- **OpenRouter para imagem estava com bug real de código**: `admin_validate.php` (regenerar individual) e `scripts/ai-routines-production-smoke.php` chamavam `AiStudioOpenAiCompatibleClient` no endpoint `{base}/images/edits` — esse endpoint **não existe** no OpenRouter (sempre 404). O caminho correto, já implementado corretamente em `process_item.php` (fluxo principal do dashboard), é a classe dedicada `AiStudioOpenRouterImageClient` (`admin/ai-image-studio/src/OpenRouterImageClient.php`), que usa `POST {base}/images` com corpo JSON `input_references: [{type:"image_url", image_url:{url:"data:...;base64,..."}}]` — não é multipart, não é `/images/edits`. Resposta em `data[0].b64_json`, igual ao formato OpenAI. Doc oficial: https://openrouter.ai/docs/guides/overview/multimodal/image-generation
- **Groq nunca teve endpoint de edição/geração de imagem** — é inference de texto/áudio (Whisper) apenas. `ai_studio_image_provider_candidates()` em `process_item.php` já trata isso certo (Groq só otimiza prompt via chat/completions, o pixel final cai pra outro provider do fallback). O código antigo em `admin_validate.php` tentava chamar Groq como editor de imagem e sempre dava 404 — corrigido para lançar erro claro em vez disso.
- **Chave Gemini (`GEMINI_API_KEY`/`GOOGLE_IMAGEN_API_KEY`/`GOOGLE_GEMINI_API_KEY`) estava revogada**, não é bug de formato nem de código. Sintoma: `401 Request had invalid authentication credentials. Expected OAuth 2...` mesmo em `GET /v1beta/models` (endpoint que não custa nada, então não é rate-limit). Confirmado batendo a MESMA chave usada pela Liz (`api/liz-general.php`, `api/liz-intelligent.php`) — ela também falhava com o mesmo erro, provando que não é isolado do Image Studio. Chaves do Google AI Studio podem ter dois formatos válidos: `AIzaSy...` (clássico) e `AQ.Ab8...` (mais novo) — **os dois são válidos**, não presuma que `AQ.Ab8...` é token/OAuth errado só pelo formato; teste antes de descartar.
- **Tier gratuito do Gemini tem cotas SEPARADAS para texto (`generateContent` sem imagem) e imagem (`generateContent` com `responseModalities:["IMAGE"]`)** — texto se recupera rápido, imagem é bem mais restrita. Rodar a matriz de 55 combinações (`scripts/ai-routines-production-smoke.php`) mais de uma vez seguida estoura a cota de imagem por um tempo (`429 Your prepayment credits are depleted`) mesmo com chave válida — não é bug, é literalmente o limite do tier grátis. Não rode o smoke test em sequência sem espaçar.
- **Assinatura Claude Pro/Claude Code ≠ crédito de API standalone.** A chave `ANTHROPIC_API_KEY` usada pelo site é do console.anthropic.com (billing pré-pago separado), mesmo sendo a mesma conta/organização de quem tem Claude Pro. Confirmar com `GET /v1/models` (grátis, só autentica) vs `POST /v1/messages` (gasta crédito) para separar "chave errada" de "conta sem saldo".
- **Nomes de modelo "econômico" mudam — sempre confirme na API real antes de hardcodar.** `gemini-2.5-flash-lite` (que existia) foi descontinuado (`404 This model ... is no longer ...`); o alias `gemini-flash-lite-latest` é a forma estável de sempre pegar a versão lite atual sem quebrar de novo. Modelos "nano"/"mini"/"lite" dentro da MESMA geração são mais baratos, mas nem sempre a geração mais nova é mais barata que a anterior (ex.: `gpt-5-nano` $0.05/$0.40 por 1M tokens é mais barato que `gpt-5.4-nano` $0.20/$1.25) — confira preço real, não assuma pela versão.
- Auto-sync local (`git-auto-sync-master.py` ou equivalente neste ambiente) commita e faz push de edições em andamento a cada ~15-20s em commits `auto: sincronizar ...` separados — útil saber que `git status`/`git diff` local pode aparecer vazio mesmo logo após um `Edit` porque já foi commitado e enviado por outro processo, não que a edição sumiu.
**Por quê importa:** antes desta sessão, os 5 "provedores" de imagem do AI Image Studio nunca tinham sido validados com chamada real; a suposição era "conta sem crédito" para tudo, mas dois eram bugs de código genuínos (OpenRouter, Groq) e um era credencial morta (Google) — só OpenAI e OpenRouter (para imagem) são de fato bloqueio de billing puro hoje.
**Ver também:** `scripts/ai-routines-production-smoke.php` (matriz de validação real, não publica nada, roda com `AI_PRODUCTION_SMOKE_CONFIRM=RUN_ALL_PROVIDER_CHANNEL_SMOKES`)

### 2026-07-30 — Bug recorrente: endpoint le getenv() sem carregar .env primeiro
**Sistema/arquivo:** `api/blog/publish-scheduled.php`, `api/melhorenvio/webhook.php`,
`.github/workflows/autonomous-safe-operations.yml` (job `health-watch`)
**O que descobri:** três lugares diferentes liam `getenv('ALGUM_TOKEN')` (ou chamavam `gh issue`)
sem nunca ter carregado o `.env`/`checkout` antes — resultado e' falha silenciosa 100% das vezes,
sem nenhum sinal de que o problema e' configuração ausente e não bug de lógica. Confirmado ao
vivo: blog falhou em 15/15 execuções nas últimas 24h+ (token sempre lido como vazio);
`health-watch` detecta 403 real em produção mas nunca conseguiu abrir issue de alerta (`gh issue
list` retornava zero issues, apesar de falhas reais e repetidas).
**Por quê importa:** o padrão correto e' sempre `require_once __DIR__ . '/../../config/bootstrap-env.php';`
antes de qualquer `getenv()` que valide token/secret (ver `order-status-update.php` como exemplo
correto já existente). Ao criar um novo endpoint com auth via `getenv()`, checar isso primeiro —
sem o require, o endpoint "funciona" nos testes locais (onde a env já está no processo) mas falha
sempre em produção via Apache/PHP-FPM puro.
**Ver também:** PR #587, seção `### 🏗️ Arquitetura Real de Deploy` no `CLAUDE.md`

---

### 2026-08-05 — Regressão real no painel Conexões (Tiny/Olist) + chaves de IA já existentes com nome errado
**Sistema/arquivo:** `includes/integration-health.php` (`svih_olist_candidates`), `admin/ai-image-studio/config.php`, `admin/catalog-optimization/config_optimization.php`
**O que descobri:**
1. O commit `17a6c5ef7` (PR #747, "corrigir renovação OAuth de Olist e Melhor Envio", 2026-08-04) fragmentou a busca de `client_id`/`client_secret` do Tiny/Olist em 3 famílias isoladas (`olist`/`tiny`/`legacy`), exigindo que a MESMA família tivesse também o `refresh_token` persistido. Na conta real, o `client_id`/`client_secret` estavam sob os nomes legados (`CLIENT_ID_API_OLIST`/`CLIENT_SECRET_OLIST`) e o `refresh_token` persistido tinha `credential_family` diferente — nenhuma família batia com as 4 peças completas, e o painel `/admin/connections.php` reportava **"Credenciais OAuth incompletas ou placeholders"** mesmo com tudo configurado. **Isso é diferente e mais recente que o bloqueio de 3+ semanas do pipeline Shopee/GitHub Actions documentado abaixo — não confundir os dois.** Corrigido com um fallback único entre os 3 nomes pra `client_id`/`client_secret` (mantendo a separação por família só pra `refresh_token`/`access_token`). Validado ao vivo: painel voltou a "Conectado — API de produtos respondeu." em 2026-08-05.
2. `GEMINI_API_KEY` já existe e funciona em produção (é a chave real usada por `api/liz-intelligent.php`) — mas `admin/ai-image-studio/config.php` e `admin/catalog-optimization/config_optimization.php` (módulos novos deste mesmo dia) esperavam `GOOGLE_IMAGEN_API_KEY`/`GOOGLE_GEMINI_API_KEY`/`CLAUDE_API_KEY`, nomes que nunca foram configurados. Adicionado fallback pra `GEMINI_API_KEY`/`ANTHROPIC_API_KEY` nos dois módulos. **Confirmado ao vivo que `OPENAI_API_KEY` e `ANTHROPIC_API_KEY` genuinamente NÃO estão configuradas em produção** (erro explícito "não configurada" mesmo após o fallback, e o mesmo nome de variável já é usado em ~8 outros arquivos do projeto sem sucesso) — não é bug de nome, falta a chave real mesmo.
3. Descoberto também ao vivo: `gemini-2.5-pro` e `gemini-1.5-flash` retornam HTTP 404 (modelo descontinuado/não disponível) com a `GEMINI_API_KEY` real deste projeto — `gemini-2.5-flash` é o que funciona agora (mesmo default de `scripts/validate-gemini-credentials.php`). `admin/catalog-optimization/config_optimization.php::CATALOG_AI_GEMINI_MODEL` ajustado para esse valor.
**Por quê importa:** ao investigar "Tiny/Olist falhou" no painel, checar primeiro se é regressão recente de código (`git log` em `includes/integration-health.php`) antes de assumir que é o bloqueio antigo de 3+ semanas do Shopee — são sintomas parecidos, causas diferentes. E antes de pedir chave de API nova pro Fred, sempre grep primeiro por `getenv('` no resto do projeto — quase sempre já existe uma chave real sob outro nome.
**Ver também:** commits `46000f1`, `01428a6` (branch `main`); seção Shopee abaixo (bloqueio diferente, ainda não resolvido).

---

## 🔴 Crítico: Problemas Não Resolvidos

### Shopee/Tiny OAuth2 — PARADO HÁ 3+ SEMANAS + workflows removidos (2026-07-27)
**Status:** Requer ação manual (regenerar client OAuth2 na Tiny) **e** recriar workflows
**Arquivos:** `.github/workflows/{fetch,optimize}-shopee-listings.yml` — **não existem mais no repo**, aparentemente removidos como colateral da consolidação "99→10 workflows" de 2026-07-26 (`CLAUDE.md`); nenhum workflow ativo restante referencia Shopee. Os agentes (`agents/v9.2.85/app/ShopeeListings*Agent.php`) continuam no repo, só falta o workflow que os chama.
**O problema:** (1) Credencial `TINY_*` do GitHub Secrets expirou/revogada — todo ciclo retornava `"Falha OAuth2: Invalid client or Invalid client credentials"`. (2) Desde ~2026-07-26, os dois workflows nem existem mais, então nem o erro acima roda: nenhum artefato novo em `listings/` desde `20260726-080756`/`20260726-060921`.
**O que precisa:** (1) Usuário vai a `accounts.tiny.com.br` → regenera client OAuth2 → atualiza `TINY_CLIENT_ID`/`TINY_CLIENT_SECRET`/`TINY_REFRESH_TOKEN` em GitHub Secrets. (2) Recriar `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` com trigger `schedule` (ver `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.10 para detalhes e formato esperado dos relatórios).
**Enquanto isso não for feito:** o pipeline de otimização Shopee não roda de forma alguma, nem para gerar erro — não há mais workflow que dispare.

---

## 🟢 Resolvido na Rodada 2 (2026-08-18) — catálogo sem imagem, deploy-webhook, rate limit de login

**Catálogo público sem imagem de produto (o item de maior impacto do backlog):** causa raiz era
uma única linha em `catalogo.php:208` (`sv_catalog_products()`), que preenchia `image_url` com o
logotipo da loja *antes* dos enriquecedores (`svp_enrich_products()`, `svcie_enrich_images()`)
rodarem. Como esses dois enriquecedores só preenchem campos que estão **vazios**, o pré-preenchimento
com o logo os neutralizava silenciosamente — o card renderizava o logo primeiro e as fotos reais
(que ficavam em `images`, não em `image_url`) depois. Corrigido removendo o default nessa linha; o
fallback correto para o logo já existe no momento do render (linha ~613), depois do enriquecimento.
Isso também destravou `sitemap.xml` (que descartava os 176 produtos porque exigia `image_url` não
vazio) e o JSON-LD do catálogo (lia o mesmo campo envenenado). **Padrão a vigiar em outros lugares:**
qualquer função que aplica um default "de apresentação" antes de um passo de enriquecimento que só
preenche campos vazios vai quebrar esse enriquecimento — o default precisa vir depois, não antes.

**`deploy-webhook.php`:** estava inerte em produção (falhava fechado com 503 por falta de
`DEPLOY_SECRET` no `.env`, confirmado ao vivo antes da remoção), mas o risco latente era sério — se
alguém configurasse `DEPLOY_SECRET` um dia, o script faria `git pull` via `exec()` com
`GITHUB_TOKEN` embutido na URL do remote (vazando pro `git config` e pro log), ou copiaria arquivo
por arquivo por cima do webroot dentro do release imutável, quebrando o modelo de releases do
`deploy-production.sh`. Removido (neutralizado com 410) — o único caminho de deploy é
`/usr/local/lib/shopvivaliz/deploy-production.sh` (cron 2min na VM Oracle). Não criar webhooks de
deploy no webroot.

**Rate limit de login era burlável:** `includes/rate-limiter.php::RateLimiter::isAllowed()` guardava
o contador em `$_SESSION`, mas a chave normalmente incluía o IP — um cliente que não enviasse o
cookie `PHPSESSID` recebia sessão nova a cada request e o contador reiniciava sempre. Ou seja, não
havia proteção real contra força bruta em `auth/login.php`, `auth/register.php` nem
`api/cart/add.php`. Reescrito como wrapper fino sobre `svorl_allow()`
(`includes/order-rate-limit.php`), que já é baseado em arquivo com `flock`, chaveado por
`hash(IP+User-Agent)` (não depende de cookie) e falha fechado sem armazenamento confiável. Assinatura
pública mantida — nenhum call-site precisou mudar.

**Vazio de implementação de frete grátis (registrado, não corrigido):** cupons do tipo `shipping`
não zeram frete em lugar nenhum do código (`api/orders/process-validated.php` soma `shippingTotal`
sempre), mas `sv_active_coupon_offer_text()` anunciava "FRETE GRÁTIS" na navbar para esse tipo.
`/api/settings/free-shipping.php` também retorna `enabled:false` sempre — não há nenhum código que
aplique o threshold de frete grátis à cotação. Protegido preventivamente (cupom `shipping` agora é
recusado com `coupon_unsupported_type`, e não é mais anunciado), mas a feature de verdade (frete
grátis real) precisa de decisão de negócio do Fred antes de qualquer implementação — mexe no
fingerprint HMAC da cotação de frete.

**Ver também:** relatório completo da Rodada 2 (achados B1–B10, itens estruturais #1–#12, E1–E3) —
gerado nesta sessão, não commitado como arquivo separado no repo; resumo acima cobre o essencial
para futuros agentes.

---

## 📚 Memória Compartilhada por Sistema

### Tiny ERP API v3
**Última atualização:** 2026-07-19

- ✓ **Pedidos pagos** nascem com `situacao=3` (Aprovada), não `0`
- ✓ **Forma de pagamento real** vem de `order.mercadopago.payment_type_id` (Pix/boleto/cartão), NÃO do campo `payment_method` (sempre "mercado_pago")
- ✗ **Nunca enviar** `pagamento.meioPagamento` — Tiny rejeita mesmo com ID válido
- ✓ Campos `listaPreco`, `naturezaOperacao`, `intermediador` só se houver ID real configurado
- ✓ Usar Bearer token OAuth de `storage/private/tokens.json`, não `OLIST_INTEGRADOR_TOKEN` (v2 legada)
- ✓ **API v2 foi removida inteiramente** — qualquer grep de `api/v2` ou `api2/` é bug, não feature

**Ver:** `docs/TINY-ERP-API-V3.md`

### Olist API v3
**Última atualização:** 2026-07-18

- ✓ Token OAuth armazenado em `storage/private/tokens.json` com `expires_at`
- ✓ Auto-renova via refresh_token a cada 3 horas (`refresh-token.php`)
- ✓ **Status do daemon:** verificar arquivo de log ou chamar `/api/daemon-status` (health check)
- ✗ **Nunca** usar `GET /estoque/{id}?token=legacy_v2_token`

**Ver:** `docs/OLIST-API-V3.md`

### Domínio Canônico
**Última atualização:** 2026-07-19

- ✓ **Domínio principal:** `https://shopvivaliz.com.br` (apex, sem www)
- ✗ **Nunca** redirecionar `POST` (quebra webhooks)
- ✓ Legados (`www.*`, `dev.*`) podem redirecionar `GET/HEAD` → apex apenas
- ✓ Todos os callbacks, feeds, OAuth, Mercado Pago devem apontar para apex

**Ver:** `.htaccess`, `scripts/update-production-env.py`

### Produtos e Catálogo
**Última atualização:** 2026-07-26

- ✓ Filtro de exibição: `situacao` = 'A'|'ATIVO'|'ACTIVE' OU `is_published` = true
- ✗ **Nunca** mostrar pré-venda/sob-encomenda (remover do catálogo)
- ⚠️ **Cache não atualiza automaticamente** ao desmarcar produto no admin — requer manual clear ou webhook
- ✓ Fonte de verdade: `storage/products-cache-ativos.json` (Olist/Tiny) → fallback: `api/catalog/fallback-products.json`

### Deploy
**Última atualização:** 2026-07-30 (corrige entrada de 2026-07-26, que estava desatualizada)

- ✗ **Não é** cron `git-auto-sync.py` a cada 30min como versões antigas deste doc diziam
- ✓ **Produção real:** `/usr/local/lib/shopvivaliz/deploy-production.sh` via cron a cada **2min**,
  monta release imutável em `/home/ubuntu/shopvivaliz-deploy/releases/<ts>-<sha>/` e troca o
  symlink `current`. Apache `DocumentRoot` aponta para `current` — confirmar sempre com
  `grep DocumentRoot /etc/apache2/sites-enabled/*` antes de assumir qual diretório serve o site
- ✓ **`.env` real de produção:** `/home/ubuntu/shopvivaliz-deploy/shared/.env` (symlinkado em
  cada release) — **não** `/home/ubuntu/site-shopvivaliz/.env`
- ⚠️ **Dois diretórios ativos, papéis diferentes** — `shopvivaliz-deploy/` serve o site;
  `site-shopvivaliz/` roda daemons/systemd (`shopvivaliz-24x7`, `agent-bridge`, `auto-sync`,
  `orchestrator`, `mcp`, etc.) e crons de sync (Olist, Google Ads, IndexNow). Editar `.env` no
  lugar errado não tem efeito nenhum no que você está tentando corrigir — ver `CLAUDE.md` seção
  `### 🏗️ Arquitetura Real de Deploy` pro diagrama completo
- ✗ **FTP/HostGator desativado** — só via `workflow_dispatch` manual
- ✓ GitHub Actions reduzido de 99 para 10 workflows críticos

### 2026-07-29 — Toolkit local e alvo persistente de deploy
**Sistema/arquivo:** produção Oracle, `scripts/deploy-production.sh`, wrappers locais em `C:\Users\FRED\.local\bin`
**O que descobri:** os atalhos operacionais válidos são `docker-check`, `docker-up`, `docker-down`, `sv-vm-ssh`, `sv-vm-status`, `sv-deploy-head`, `sv-deploy-sha`, `sv-blog-status` e `sv-blog-publish`. A chave funcional da VM nesta máquina é `C:\Users\FRED\Downloads\ssh-key-2026-07-04.key`.
**Por quê importa:** o alias antigo do WSL para `bash` e a chave `shopvivaliz_vm_agent` causavam falso negativo. Sem um alvo persistente (`/home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref`), o cron voltava para `main` e desfazia deploy manual por SHA/branch.
**Ver também:** `AGENTS.md`, `docs/VM-SSH-ACCESS.md`

### 2026-07-29 — Worktree e editores padronizados
**Sistema/arquivo:** worktree local, atalhos Windows, `AGENTS.md`, `.vscode/`
**O que descobri:** o checkout operacional que deve ser usado pelos agentes locais é `C:\site-shopvivaliz-prod-liz`, validado no branch `agent/liz-widget-prod` com SHA `19328ac2` em 2026-07-29. Os atalhos `ShopVivaliz - VS Code`, `ShopVivaliz - Antigravity` e `ShopVivaliz - Antigravity IDE` foram criados no desktop e o Start Menu dos três editores foi ajustado para abrir o mesmo worktree.
**Por quê importa:** havia risco real de abrir o editor em `C:\site-shopvivaliz` ou em um diretório genérico e editar o checkout errado. A padronização reduz drift entre VS Code, Antigravity e Antigravity IDE e facilita validação de branch antes de deploy.
**Ver também:** `AGENTS.md`, `site-shopvivaliz.code-workspace`, `.vscode/settings.json`

### 2026-07-29 — ShopVivaliz Mobile Agent Bridge
**Sistema/arquivo:** VM Oracle, `/home/ubuntu/site-shopvivaliz/agent-bridge/`, `AGENTS.md`
**O que descobri:** a bridge correta para uso do GPT mobile observa `/home/ubuntu/site-shopvivaliz/agent-bridge/inbox/` e expoe apenas `create_issue`, `apply_patch_pr`, `read_file` e `run_readonly_audit`. O service associado e `shopvivaliz-agent-bridge.service`.
**Por quê importa:** isso entrega acesso controlado da VM ao GPT mobile por fila JSON, sem shell irrestrito e sem permitir commit direto em `main` ou merge automatico.
**Ver também:** `AGENTS.md`, `agent-bridge/README.md`, `deploy/systemd/shopvivaliz-agent-bridge.service`

---

## 🤖 Agentes Autônomos Ativos

| Agente | Tipo | Commits | Status |
|--------|------|---------|--------|
| Agente Autonomo ShopVivaliz | Primary | 2,542 | ✅ Ativo |
| fredmourao-ai | Developer | 1,573 | ✅ Ativo |
| Frederico Mourao | Developer | ~1,668 | ✅ Ativo |
| CI Summary Bot | Automation | 158 | ✅ Ativo |
| Claude Autonomo | AI | 212 | ✅ Ativo |
| Codex | AI | 135 | ✅ Ativo |
| CI Autônomo | CI | 142 | ✅ Ativo |

**Total histórico:** 11,600+ commits automáticos
**Frequência:** Auto-sync a cada 20-60 minutos

---

## 📄 Documentação Associada

- `docs/TINY-ERP-API-V3.md` — Schema completo do Tiny
- `docs/OLIST-API-V3.md` — Endpoints Olist v3
- `KNOWN_ISSUES.md` — Bugs conhecidos / em investigação
- `CHANGELOG.md` — Histórico de mudanças e fixes
- `CLAUDE.md` — Instruções gerais do projeto

---

**Última consolidação:** 2026-07-26 (entrada da Rodada 2 de melhoria contínua adicionada em 2026-08-18)
**Consolidado por:** Claude Code
**Próxima revisão:** Quando houver novo achado não-óbvio
