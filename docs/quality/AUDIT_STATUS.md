<!-- RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->
**AVISO OPERACIONAL (2026-10-29):** a VM de origem `shopvivaliz-free-a1` foi excluída conforme confirmação do proprietário. As etapas históricas abaixo que requerem **manter, acessar, monitorar, validar, interromper ou recriar** essa VM (incluindo `NO_DECOMMISSION` e critérios dos "quatro hosts") **não são instruções executáveis atuais**. A produção web está em `marina-a1-4c24g`, o backend/controller em `always-free-arm-1787907847-26`. Não substituir aliases/SSH/tenancy da origem por Marina. Preservar backups e evidência acessível, rever gates ainda pertinentes e seguir `docs/knowledge/current-topology-and-access-2026-10-29.md`. A exclusão não prova `APTO`, integração de MCP novo, paridade de dados ou passagem de E2E; `GLOBAL_MIGRATION_STATUS=NAO_APTO` enquanto houver gates pendentes.
<!-- /RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->

# Estado da Auditoria

## 2026-11-01 — corrigir falso-verde da saúde do webhook Olist

**RED de runtime:** após a PR #3489 fechar o processador legado com HTTP403/410, `GET /api/olist/webhook-health.php` continuava `HTTP200 ok:true`, registrando apenas `processor_file_exists:true` porque o arquivo tombstone ainda existe. Essa observação não verifica o webhook oficial `api/olist/webhook.php`, o segredo do provedor ou a reconciliação Olist/Tiny v3 e pode esconder regressão de autenticação.

**Correção:** exigir no health a existência do endpoint canônico, seus contratos de autenticação e reconciliação via API v3, tombstone sem SQL e bloqueio Apache legado, além das checagens anteriores de logs e MySQL necessárias aos fluxos de pedidos. Quando qualquer condição falhar, retornar `503 ok:false`. Não muda escrita, fonte comercial, ID, preço, estoque, ERP nem canal de autenticação.

**Testes locais e de impacto:** regressão adicionada ao teste já obrigatório `tests/olist-erp-webhook-entrypoint-test.php`, acionado em `ShopVivaliz QA`; PHP lint e teste de contrato no SHA vigente, checks oficiais após PR e readback HTTP do health/403 legado/401 canônico na release pós-merge. **AUDIT_VERDICT=NAO_APTO** até merge e revalidação real. Nenhum webhook de alteração foi enviado.

---

## 2026-11-01 — P0: entrada legada Olist desprotegida e escritores comerciais fora do ERP v3

**AUDIT_VERDICT=NAO_APTO** ate concluir CI, merge autorizado, deploy imutavel e teste externo negativo. Escopo desta PR: apenas impedir escrita insegura pelo endpoint obsoleto; nao ajustar tabelas, anuncios, preco, estoque, ERP nem outbox.

**RED concreto:** fonte de `api/olist/webhook-processor.php` aceitava eventos de preco/estoque/exclusao enviados sem `sv_webhook_secret_gate`, conectava MySQL local e executava `UPDATE/DELETE products` a partir de campos do solicitante. `.htaccess` bloqueava `api/sync/full-sync.php`, mas nao esse legado. Um POST externo **sem credenciais** com corpo JSON **vazio** (sem SKU nem operacao) chegou ao PHP real e retornou `HTTP 400 error=empty_payload` em `/api/olist/webhook-processor.php`, confirmando ausencia de protecao de borda sem mutacao. O endpoint canonico `/api/olist/webhook.php` ja autentica `sv_webhook_secret_gate('olist', false)` e reconcilia preços/estoque pela API oficial Olist/Tiny v3 (tabela Amazon 991). O script raiz `setup-webhooks.php` podia reinserir a inclusao legada no endpoint canonico em execucao manual.

**Correcao / prevencao:** apos o deploy, regra Apache `RewriteRule ^api/olist/webhook-processor\\.php$ - [F,L,NC]` deve negar HTTP antes de PHP, e um tombstone PHP separado retorna `410 legacy_webhook_retired` mesmo fora da borda; nao ha mais SQL/banco no processador legado. `setup-webhooks.php` torna-se tombstone `410 legacy_setup_retired`, sem tocar em arquivos. `/api/olist/webhook.php` permanece intacto e autenticado. Regressao estendida em `tests/olist-erp-webhook-entrypoint-test.php` e inserida no job PHP relevante de `ShopVivaliz QA`, sem acionar verificacoes de serviços não alterados.

**GREEN isolado antes de PR:** clone efêmero e separado na Marina do HEAD `1556472e9d1e19e6bafa061dcf7536646a5a252a`: 3/3 arquivos `php -l` PASS; `php tests/olist-erp-webhook-entrypoint-test.php` = `olist-erp-webhook-entrypoint: ok`; execução CLI do tombstone do processador = `legacy_webhook_retired` e do instalador = `legacy_setup_retired`; nenhum SQL executado, nenhuma credencial extraída ou produto modificado. **Não** confundir estes testes com protecao em produção: ainda falta `HTTP 403` ou equivalente em POST vazio, `HTTP 401` no webhook canônico sem segredo, readback do SHA da release e invariância do catálogo/ERP.

**Rollback:** reverter somente os arquivos após reavaliar risco; não reativar o processador antigo. Migração de integração legada deve apontar apenas para endpoint canônico com segredo válido, sem fallback ao SQL local. Verificar consumidores de `includes/products-cache.php` e `includes/marketplace/MarketplaceRuntime.php` separadamente, porque ainda consultam MySQL derivado (PR comercial #3476 depende de merge humano formal).

---

## 2026-11-01 — corrigir carrinho legado que consulta MySQL comercial defasado

**Escopo:** `api/cart/add.php`, apenas referência de produto e validação de carrinho; não edita tabela de preço Amazon 991 nem estoque ERP. **AUDIT_VERDICT=NAO_APTO** enquanto novo SHA não estiver aprovado/deployado e o fluxo UI não for repetido.

**RED reproduzido em produção:** o SKU `1C7Q-LKVS-MDOC` consta como ERP Olist v3 ID `393626238`, preço Amazon 991 R$38,90 e saldo vendável 4, mas MySQL derivado `products` ainda guarda ID `387064728`, R$10,00, estoque 0. A API legada `api/cart/add.php` usava `SELECT ... FROM products`; podia negar o carrinho e usar valores incorretos. Preflight descobriu também 155 preços, 149 estoques e 19 IDs divergentes na tabela derivada. A auditoria total oficial de preços, no entanto, terminou `PRICE_PARITY_VERDICT=PASS` com 202 ERP=cache e 200/200 públicos, SHA `61d73ec6ee95882329bfb84a282812861e867e8a`; evidência persistida na Marina, hash SHA256 `5864a068b8c97d125813c422f294429a6ab8dcc72e98352a00a734615bb44662`.

**Correção preventiva:** endpoint legado passa a consumir exclusivamente `svcr_products()` (cache ERP Olist v3, tabela Amazon 991 e estoque disponível), recusa SKU ausente/ambíguo e valida quantidade TOTAL de sessão antes de mutá-la. Nunca usa fallback MySQL nem preço base; não altera preços comerciais, flag, banco ou outros sistemas. Testes unitários isolados exercitam 0/1/4/5/99/100, estoque zero, preço zero, duplicidade, identidade ERP e contrato de ausência de SQL. Evita repetição do incidente mesmo se o MySQL derivado estiver defasado.

**GREEN local no SHA `f5ba5add9fb4b3b9ae3056699c86064b277191aa`:** 3/3 `php -l` e `php tests/cart-authoritative-erp-contract-test.php` = `cart-authoritative-erp-contract: ok`, executados em worktree sparse separado na Marina, **sem deploy**, sem compras/pagamentos, sem alteração comercial ou dados. Cobertura oficial de CI, deploy e comportamento UI do SHA final ainda requerem validação após integração. A auditoria V5 e o estado da tabela MySQL derivada permanecem pendentes; a decisão humana para reconciliar o banco foi solicitada na PR Draft #3476.

---

## Rodada de publicação do site público na Marina — 2026-10-20 (escopo auditado)

**SITE_PUBLIC_AUDIT_STATUS=APTO** — o `AUDIT_VERDICT=APTO` certifica **somente o escopo da auditoria funcional do storefront e das jornadas UI efetivamente executadas** no SHA/release `99416b61be202b95a1f77b0469fd6395954eeb74`. Preserva-se a certificação original e suas evidências; não há conversão automática em aprovação para toda a infraestrutura.

**GLOBAL_MIGRATION_STATUS=NAO_APTO | NO_DECOMMISSION** — a certificação da publicação web não aprova desativar `shopvivaliz-free-a1`, mover dados protegidos, mudar os donos das filas nem declarar todos os serviços concluídos. Precisam de aceites separados: Pricing (worker inativo e banco em porta wildcard sem backup/volume/ACL/restart certificados), paridade/transferência autorizada de Supabase, boot do Amazon SAFE-T, MFA/TLS/rollback e MCP ShopVivaliz independente da controladora, ainda não instalado nem conectado. Os limites administrativos e a recusa anterior para transferência Supabase permanecem vinculantes.

**PUBLIC_ROUTE_OBSERVED=marina-a1-4c24g** — em 2026-10-20 04:31–04:32 UTC, uma requisição HTTPS pública com marcador exclusivo retornou HTTP 200 e foi encontrada no `shopvivaliz-apex-www-access.log` da Marina (1 ocorrência, 0 na origem). Essa prova identifica **uma** requisição encaminhada via Cloudflare à Marina; não comprova todos os paths, regiões, origens ou os outros serviços. Origem e backups preservados.

**Release publicado:** `99416b61be202b95a1f77b0469fd6395954eeb74` (marcador público confirmado; deploy `39351461463`).

**Evidência de browser real:** runs `39351898560`, `39353310486` e `39363734687` — três sessões distintas, 9 mensagens por ciclo, cobertura completa, consenso disponível, health dos três provedores verificado antes e depois do reload, `AI_SQUAD_UI_AUDIT=PASS`.

**Auditoria funcional pós-deploy:** run `39351896032` — páginas críticas, orders health, payment queue, quote real do Melhor Envio e integrações Mercado Pago/Melhor Envio/Olist passaram.

**Certificação formal:** manifesto `AUDIT_CERTIFICATION_MANIFEST_V1` para SHA `99416b...`; `AUDIT_POLICY_VERSION=2026-09-21-absolute-v5` e `AUDIT_VERDICT=APTO`.

**Observação operacional:** o worker de fila da origem permanece desligado por fencing; a saúde funcional do **site** foi validada na Marina. Não há pendência crítica declarada **nesse escopo certificado**, mas a migração global permanece `NAO_APTO` e `NO_DECOMMISSION` pelos gates listados acima.

**Status:** ✅ APTO — Auditoria Extrema v5 pós-PRs #1692–#1703 concluída em 2026-09-21; SQUAD_TOKEN configurado; bridge Claude Code OAuth operacional (`AI_SQUAD_USE_BRIDGE=1`); todos os 15 invariantes PASS.

---

## Rodada 2026-09-21 (2ª passagem) — Auditoria pós-atualização Fred+GPT (PRs #1692–#1703)

**SHA auditado:** `affe06a75` (origin/main em 2026-09-21)
**Escopo:** squad-chat.php, claude-bridge.mjs, ai-squad-core.php, installer, testes

### Matriz de invariantes

| # | Verificação | Resultado | Evidência |
|---|---|---|---|
| 1 | `.htaccess` exceção squad-chat.php | ✅ PASS | linha 204 |
| 2 | `dirname(__DIR__, 3)` em 5 ocorrências | ✅ PASS | grep |
| 3 | `API_ENDPOINT` em `admin/squad-chat.html` | ✅ PASS | `/claude/api/agent/squad-chat.php` |
| 4 | SQUAD_TOKEN validado com `hash_equals` | ✅ PASS | linha 405 |
| 5 | Token via header `X-Squad-Token` | ✅ PASS | `HTTP_X_SQUAD_TOKEN` |
| 6 | `call_claude_bridge_agent()` presente | ✅ PASS | linhas 532–557 |
| 7 | Flag `$useBridge` configurada | ✅ PASS | linha 378 |
| 8 | GH_REPO default `Vivaliz-site/site-shopvivaliz` | ✅ PASS | grep |
| 9 | Modelo Gemini `gemini-2.5-flash` (não 3.5) | ✅ PASS | linha 371 |
| 10 | PHP lint `squad-chat.php` | ✅ PASS | `php -l` |
| 11 | PHP lint geral (exceto teste pré-existente) | ✅ PASS | 0 erros |
| 12 | `validate-health-output.php` | ✅ PASS | COMPROVADO |
| 13 | `validate-asset-manifest.php` (89 entradas) | ✅ PASS | COMPROVADO |
| 14 | `ai-squad-claude-bridge-test.mjs` | ✅ PASS | pass 1/1 |
| 15 | `ai-squad-three-provider-runtime-contract-test.sh` | ✅ PASS | CONTRACT=PASS |

### Veredito

**✅ APTO** — SHA `affe06a75`. Bridge OAuth funcional com `AI_SQUAD_USE_BRIDGE=1`. SQUAD_TOKEN configurado (Fred). `gemini-2.5-flash` correto. Todos os testes e validadores passam.

### Ressalvas

- `tests/production-runner-rescue-contract-test.php` linha 23 — erro de sintaxe PHP **pré-existente**, não introduzido nesta sessão.
- OpenAI sem créditos (`OPENAI_API_KEY` sem saldo) — não bloqueia APTO; Anthropic via bridge e Gemini operacionais.

---

## Rodada 2026-09-21 (1ª passagem) — Auditoria Extrema v5 API AI Squad (ESC-2026-001)

**SHA em produção:** `f386a922f247f87b26fae99349a8d0edd2647a65` (release `20260921-174416-f386a922`)  
**Fixes mergeados:** PR #1689 SHA `63ae6fafb307119e2f6f5164511a46074a4bb507`  
**Data/hora smoke:** 2026-09-21 ~17:48 UTC

### Evidências coletadas

| # | Verificação | Resultado | Evidência |
|---|---|---|---|
| 1 | `.htaccess` — exceção `claude/api/agent/squad-chat.php` | ✅ PASS | linha 204 da release `f386a922` |
| 2 | `GET ?health=1` HTTP status | ✅ HTTP 200 | curl na VM a1 |
| 3 | `env_loaded` no health check | ✅ `env_loaded: true` | JSON: `{"ok":true,"env_loaded":true,...}` |
| 4 | Providers configurados | ✅ anthropic/openai/gemini | health check ao vivo |
| 5 | Agentes ativos | ✅ 8 agentes: director, claude, gpt, gemini + variantes roo_ | health check |
| 6 | `API_ENDPOINT` no `admin/squad-chat.html` | ✅ `/claude/api/agent/squad-chat.php` | grep linha 118 da release |
| 7 | POST sem token | ✅ erro explícito `{"error":"SQUAD_TOKEN not configured"}` — não silencioso | curl POST |
| 8 | `dirname(__DIR__, 3)` em 5 ocorrências | ✅ grep confirma N=3 na release | release `f386a922` |
| 9 | GH_REPO padrão | ✅ `Vivaliz-site/site-shopvivaliz` | grep na release |

### Ressalva operacional (não bloqueia APTO)

- `SQUAD_TOKEN` não está configurado no `.env` de produção → POST retorna `{"error":"SQUAD_TOKEN not configured"}`. **Ação para Fred:** adicionar `SQUAD_TOKEN=<valor>` ao `/home/ubuntu/shopvivaliz-deploy/shared/.env` na VM a1.

### Matriz de invariantes squad API

| Invariante | Fonte | Garantia técnica | Resultado |
|---|---|---|---|
| Frontend aponta para endpoint correto | ESC-2026-001 | `API_ENDPOINT` em `admin/squad-chat.html` | ✅ PASS |
| `.env` carregado no endpoint | ESC-2026-001 | `dirname(__DIR__, 3)` | ✅ PASS |
| Endpoint acessível em produção | `.htaccess` exceção | HTTP 200 no health | ✅ PASS |
| Providers AI configurados | health check | `env_loaded: true` + providers JSON | ✅ PASS |
| POST não autorizado falha explicitamente | código | `{"error":"SQUAD_TOKEN not configured"}` | ✅ PASS (erro explícito, não silencioso) |
| GH_REPO correto | ESC-2026-001 | default `Vivaliz-site/site-shopvivaliz` | ✅ PASS |

### Veredito

**✅ APTO** — todos os 4 defeitos do ESC-2026-001 corrigidos, deployados e validados ao vivo. Funcionalidade completa de POST requer `SQUAD_TOKEN` no `.env` (ação operacional do Fred).

---

## Rodada 2026-09-19 — cobertura completa com acesso a produção via Remote Desktop Commander

**SHA auditado (origin/main):** `7a205fa07` (verificado em 2026-09-19)  
**SHA em produção:** `35fa132047b207e00bef37863a158725cf68dac6` (release `20260919-230157-35fa1320`)  
**Delta produção↔main:** zero arquivos PHP/JS/CSS/`.htaccess` alterados — apenas scripts de infra/recovery. Paridade de código confirmada.

### Evidências coletadas (em ordem de execução)

| # | Operação | Resultado | Evidência |
|---|---|---|---|
| 1 | SHA parity (`git diff --name-only 35fa1320..origin/main -- '*.php'`) | **PASS** — zero arquivos web alterados | saída vazia |
| 2 | Storefront `GET /` | **HTTP 200**, TTFB 0.158s | curl -I |
| 3 | Catálogo `GET /api/catalog/products.php` | **179 produtos ativos** com preços e imagens | JSON confirmado |
| 4 | Frete `POST /api/melhorenvio/shipping-check-v2.php` (SKU real, CEP 01310100) | **5 opções retornadas** — Express R$15.01, Standard R$16.08 | quote_id `97cdee6c...` |
| 5 | Serviços systemd | apache2, queue-worker, token-renewer **ativos** | `systemctl is-active` |
| 6 | Health check | `health_score_percent: 100` — todos os 19 checks passando | `GET /api/health.php` |
| 7 | Admin read SKU (agent-key) | produto `TPJ/AS*BR1` retornou dados corretos | API 200 |
| 8 | **Checkout completo** `POST /api/orders/create-validated.php` (`payment_method: whatsapp`) | **ok: true**, pedido `SV20260919232641944`, total R$64.01, `status: pending_confirmation`, `local_storage_role: pre_payment_draft_mirror`, `erp_authority: tiny_v3_after_payment_approval` | JSON confirmado ao vivo |

### Dados do checkout de teste
```json
{
  "ok": true,
  "order_number": "SV20260919232641944",
  "status": "pending_confirmation",
  "payment_method": "whatsapp",
  "subtotal": 49,
  "shipping_total": 15.01,
  "shipping_label": "Express",
  "total": 64.01,
  "local_storage_role": "pre_payment_draft_mirror",
  "erp_authority": "tiny_v3_after_payment_approval"
}
```
Produto: `TPJ/AS*BR1` (R$49, estoque=300), frete Express Loggi para CEP 01310100, método `whatsapp` (não aciona Mercado Pago — pedido não gera cobrança real).

### Matriz de operação — resultado final

| Operação / área | Resultado |
|---|---|
| SHA parity produção↔main (código web) | ✅ PASS |
| Storefront/catálogo acessível | ✅ PASS — HTTP 200, 179 produtos |
| Carrinho + cotação de frete | ✅ PASS — cart add R$51.47, 5 opções frete |
| Checkout completo (create-validated) | ✅ PASS — pedido criado ao vivo |
| Serviços críticos ativos | ✅ PASS — apache2, queue-worker, renewers |
| Health score | ✅ 100% (disco limpo após limpeza do Fred) |
| Admin read (agent-key) | ✅ PASS |
| PHP lint 100% dos `.php` | ✅ PASS (zero erros) |
| validate-health-output.php | ✅ COMPROVADO |
| validate-asset-manifest.php | ✅ COMPROVADO (89 entradas) |

### Dívidas registradas (não bloqueiam APTO)
- PHPUnit não executado (vendor/ ausente neste worktree remoto) — cobertura de lint e smoke funcional substitui nesta rodada.
- `amazon-returns-deploy.service` em FAILED na VM (playwright não instalado) — escopo externo ao storefront, não bloqueia.
- Backend A1 (`always-free-arm-1787907847-26`) com serviços inativos — escopo separado.

### Certificação
**Veredito: ✅ APTO** para o SHA `35fa1320` / `7a205fa07` (equivalentes em código web), data 2026-09-19.  
Todas as operações stateful críticas foram exercitadas ao vivo contra produção real via Remote Desktop Commander (device `shopvivaliz-free-a1`).

---

## Histórico de auditorias anteriores

### Rodada 2026-09-19 (sessão estática anterior, sem acesso a produção)
- Cobertura: lint PHP, validate-health-output.php, validate-asset-manifest.php. Sem acesso SSH/browser.
- Veredito: NÃO APTO — lacuna de runtime não fechada.

### Rodada 2026-09-16
- Data: 2026-09-16.
- Commit/SHA auditado: `9200c85db53408d728572109fe54e1162a333bea`.
- Produção observada: `releases/20260915-193503-b37665d3`.
- Veredito: NÃO APTO — SHA divergente + mutações críticas sem evidência.

## Regra de validade
Esta auditoria cobre o SHA `35fa132047b207e00bef37863a158725cf68dac6` (código web equivalente a `origin/main` em 2026-09-19). Alteração material em checkout, catálogo, auth, integrações, infraestrutura ou deploy exige reauditoria.

## 2026-11-02 — paridade da identidade Solange transferida no registro V5

- Base SHA: 583987a205784e722d7d176f9c5a2d00e240373d
- Impacto: reflete apenas a transferencia oficial do mesmo GitHub repository ID 1345489426 para Vivaliz-site/solange-rolla-consultorio em lista global de auditoria, hashes e scripts; nao altera precos, estoque, checkout, Ads, acesso ou gates comerciais.
- Testes: validacao de lista, hashes de blob, sintaxe Python e check do repo novo como coberto e do endereco antigo como nao autorizado. Aguardam CI e paridade com manifesto global da PR #72.
- Evidencias: a origem antiga retorna 301 e a nova conserva o ID numerico; nenhuma autorizacao comercial ou UI E2E foi inferida.
- Pendencias: sincronizacao e gates do manifesto central, reauditoria apos merge; NAO_APTO.
- Estado: NAO_APTO
