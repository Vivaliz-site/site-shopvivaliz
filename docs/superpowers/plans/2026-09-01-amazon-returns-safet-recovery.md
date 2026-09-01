# Amazon Returns & SAFE-T Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar um subsistema auditável e resiliente que acompanha devoluções Amazon, detecta reembolsos/ressarcimentos, calcula elegibilidade SAFE-T, executa reivindicações/recursos com idempotência e encerra casos somente após conciliação financeira real.

**Architecture:** O módulo será integrado ao `site-shopvivaliz`, mas isolado sob `includes/amazon-recovery/`, reaproveitando autenticação/admin/infra existentes. SP-API e Gmail alimentam um event store append-only; Policy Engine + Financial Ledger calculam estado e ações; uma outbox durável entrega ações a um executor Seller Central separado, que sempre faz verificação pós-escrita.

**Tech Stack:** PHP 8.1+, PDO/MySQL, Amazon SP-API (Orders v2026-01-01, Finances v2024-06-19, Notifications, Reports), Gmail API/conector, workers CLI PHP, admin PHP existente, automação Seller Central em host remoto autorizado, GitHub Actions/CI existente.

**Spec:** `docs/superpowers/specs/2026-09-01-amazon-returns-safet-recovery-design.md`

## Global Constraints

- API oficial Amazon primeiro; Seller Central somente para operações sem endpoint público aplicável.
- D+45 é checkpoint obrigatório; submissão respeita Policy Engine e exceções vigentes como 60 dias quando aplicável.
- Reembolso/ressarcimento Amazon deve ser reconciliado antes de qualquer SAFE-T.
- Controle por pedido + item + quantidade + evento de reembolso + motivo.
- Eventos são append-only e ações externas são idempotentes.
- `FAILED`, `TIMEOUT`, `UNKNOWN`, `SESSION_EXPIRED` e `CAPTCHA` nunca são estados terminais de negócio.
- IA nunca pode afirmar fato sem evidência ligada ao dossiê.
- Escritas externas usam outbox transacional, verificação pós-ação, feature flags e kill switch.
- Nenhuma mudança direta em `main`; branch → testes reais → commit → PR → merge.

---

## File Structure

### Criar

- `includes/marketplace/AmazonClient.php` — cliente SP-API comum e reutilizável.
- `includes/amazon-recovery/schema.php` — schema/migrations idempotentes do domínio.
- `includes/amazon-recovery/types.php` — enums/constantes e validadores do domínio.
- `includes/amazon-recovery/events.php` — event store append-only e deduplicação.
- `includes/amazon-recovery/cases.php` — criação/redução de estado do caso.
- `includes/amazon-recovery/policies.php` — Policy Engine versionado.
- `includes/amazon-recovery/ledger.php` — ledger financeiro e cálculo esperado/aprovado/recebido.
- `includes/amazon-recovery/jobs.php` — fila durável, leases, retry, DLQ e prioridade.
- `includes/amazon-recovery/outbox.php` — persistência/claim/confirm de ações externas.
- `includes/amazon-recovery/amazon-orders.php` — adapter Orders v2026-01-01.
- `includes/amazon-recovery/amazon-finances.php` — adapter Finances v2024-06-19.
- `includes/amazon-recovery/amazon-reports.php` — Reports de devolução/reembolso.
- `includes/amazon-recovery/amazon-notifications.php` — normalização de notificações.
- `includes/amazon-recovery/gmail-parser.php` — parser determinístico de e-mails Amazon.
- `includes/amazon-recovery/reconciler.php` — reconciliação de fontes e cálculo do estado.
- `includes/amazon-recovery/decision-engine.php` — WAIT/OPEN_SAFE_T/APPEAL/etc.
- `includes/amazon-recovery/dossier.php` — montagem do dossiê/evidências.
- `includes/amazon-recovery/appeal-generator.php` — geração fundamentada e fact-check.
- `includes/amazon-recovery/feature-flags.php` — flags/kill switch.
- `includes/amazon-recovery/health.php` — métricas/gates de saúde.
- `api/admin/amazon-recovery/index.php` — dados do dashboard.
- `api/admin/amazon-recovery/receive-return.php` — recebimento físico.
- `api/admin/amazon-recovery/case.php` — detalhes/timeline do caso.
- `admin/amazon-recovery.php` — dashboard operacional.
- `scripts/amazon-recovery-worker.php` — worker principal.
- `scripts/amazon-recovery-reconcile.php` — reconciliação periódica.
- `scripts/amazon-recovery-backfill.php` — backfill histórico.
- `scripts/amazon-recovery-policy-watch.php` — candidatos de mudança de política.
- `scripts/amazon-recovery-seller-central.php` — launcher/bridge do executor remoto autorizado.
- `tests/amazon-recovery-schema-test.php`
- `tests/amazon-recovery-events-test.php`
- `tests/amazon-recovery-policy-test.php`
- `tests/amazon-recovery-ledger-test.php`
- `tests/amazon-recovery-jobs-test.php`
- `tests/amazon-recovery-idempotency-test.php`
- `tests/amazon-recovery-gmail-parser-test.php`
- `tests/amazon-recovery-reconcile-test.php`
- `tests/amazon-recovery-decision-test.php`
- `tests/amazon-recovery-dossier-test.php`
- `tests/amazon-recovery-api-contract-test.php`
- `tests/amazon-recovery-receiving-test.php`
- `tests/amazon-recovery-worker-recovery-test.php`
- `tests/amazon-recovery-e2e-shadow-test.php`

### Modificar

- `includes/marketplace/AmazonPublisher.php` — consumir `AmazonClient.php` sem redefinir cliente.
- `includes/marketplace/MarketplaceRuntime.php` — somente helpers Amazon genéricos estritamente necessários.
- `.env.example` — flags/configuração não secreta e nomes de secrets.
- `includes/admin-guard.php` ou bootstrap admin somente se necessário para nova rota, sem alterar sem necessidade.
- `docs/AGENTS.md` — contratos operacionais, política de validação e proibição de bypass.
- CI/workflows existentes apenas para adicionar testes do novo módulo, seguindo padrão atual.

---

### Task 1: Extrair o cliente SP-API comum sem regressão de catálogo

**Files:**
- Create: `includes/marketplace/AmazonClient.php`
- Modify: `includes/marketplace/AmazonPublisher.php`
- Test: `tests/amazon-recovery-api-contract-test.php`

**Interfaces:**
- Produces: `final class SvAmazonClient`, `request(string $method, string $path, array $query = [], ?array $body = null): array`, `sellerId(): string`, `marketplaceId(): string`.
- Consumes: `sv_market_env()`, `sv_market_http_json()`, `SvMarketplaceException`.

- [ ] **Step 1: Escrever teste de contrato que falha**

Criar teste que exige `AmazonClient.php`, valida existência de `SvAmazonClient`, assegura que `AmazonPublisher.php` apenas importa o cliente e não contém segunda declaração da classe, e usa reflection para checar métodos públicos.

- [ ] **Step 2: Rodar o teste e confirmar falha**

Run: `php tests/amazon-recovery-api-contract-test.php`
Expected: FAIL porque `AmazonClient.php` ainda não existe.

- [ ] **Step 3: Mover a classe sem alterar comportamento**

Copiar integralmente a implementação atual de `SvAmazonClient` para `AmazonClient.php`; em `AmazonPublisher.php`, adicionar `require_once __DIR__ . '/AmazonClient.php';` e remover a definição duplicada.

- [ ] **Step 4: Rodar contrato + testes Amazon/catálogo existentes relevantes**

Run: `php tests/amazon-recovery-api-contract-test.php`
Expected: PASS.

Run: `php scripts/maintenance/catalog_real_execution_audit.py` somente se o script aceitar PHP via ambiente; caso contrário executar a suíte existente de integridade de marketplace indicada pelo workflow atual.
Expected: nenhuma regressão no publisher Amazon.

- [ ] **Step 5: Commit**

`git add includes/marketplace/AmazonClient.php includes/marketplace/AmazonPublisher.php tests/amazon-recovery-api-contract-test.php && git commit -m "refactor: extract shared Amazon SP-API client"`

---

### Task 2: Schema do domínio e invariantes

**Files:**
- Create: `includes/amazon-recovery/schema.php`
- Create: `includes/amazon-recovery/types.php`
- Test: `tests/amazon-recovery-schema-test.php`

**Interfaces:**
- Produces: `sv_ar_ensure_schema(PDO $db): void`, `sv_ar_case_key(array $input): string`, constantes de estados/fontes/ações.

- [ ] **Step 1: Escrever teste de schema em banco descartável**

O teste deve criar conexão SQLite in-memory apenas para validar DDL compatível onde possível ou usar DSN de teste MySQL quando disponível; deve executar `sv_ar_ensure_schema()` duas vezes e validar tabelas/índices únicos essenciais.

- [ ] **Step 2: Rodar e confirmar falha**

Run: `php tests/amazon-recovery-schema-test.php`
Expected: FAIL com função ausente.

- [ ] **Step 3: Implementar tabelas**

Criar: `amazon_recovery_cases`, `amazon_recovery_events`, `amazon_recovery_ledger`, `amazon_recovery_policies`, `amazon_recovery_jobs`, `amazon_recovery_outbox`, `amazon_recovery_dlq`, `amazon_recovery_evidence`, `amazon_recovery_source_cursors`.

Garantir chaves únicas para case key, source event e idempotency key.

- [ ] **Step 4: Testar idempotência e constraints**

Run: `php tests/amazon-recovery-schema-test.php`
Expected: PASS e tentativa de duplicar case/event/idempotency key deve falhar ou virar no-op conforme contrato.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/schema.php includes/amazon-recovery/types.php tests/amazon-recovery-schema-test.php && git commit -m "feat: add Amazon recovery domain schema"`

---

### Task 3: Event store append-only e redução de estado

**Files:**
- Create: `includes/amazon-recovery/events.php`
- Create: `includes/amazon-recovery/cases.php`
- Test: `tests/amazon-recovery-events-test.php`

**Interfaces:**
- Produces: `sv_ar_append_event(PDO $db, array $event): int`, `sv_ar_get_events(PDO $db, int $caseId): array`, `sv_ar_reduce_case(PDO $db, int $caseId): array`, `sv_ar_find_or_create_case(PDO $db, array $identity): int`.

- [ ] **Step 1: Testar dedupe e append-only**

Criar dois eventos com mesmo `source/source_id/event_type`; o segundo deve retornar o existente sem alterar payload anterior. Criar evento posterior deve alterar estado reduzido, nunca a linha anterior.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-events-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar event store e reducer puro**

Reducer deve reconhecer pelo menos `RETURN_AUTHORIZED`, `REFUND_DETECTED`, `PHYSICAL_RETURN_RECEIVED`, `AMAZON_REIMBURSEMENT_DETECTED`, `SAFE_T_OPENED`, `SAFE_T_DENIED`, `SAFE_T_APPROVED`, `APPEAL_OPENED`, `CREDIT_REVERSED`.

- [ ] **Step 4: Rodar teste**

Run: `php tests/amazon-recovery-events-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/events.php includes/amazon-recovery/cases.php tests/amazon-recovery-events-test.php && git commit -m "feat: add append-only Amazon recovery event store"`

---

### Task 4: Policy Engine versionado e regras D+45/D+60

**Files:**
- Create: `includes/amazon-recovery/policies.php`
- Test: `tests/amazon-recovery-policy-test.php`

**Interfaces:**
- Produces: `sv_ar_select_policy(PDO $db, array $case, DateTimeImmutable $at): array`, `sv_ar_calculate_eligibility(array $policy, array $case): DateTimeImmutable`, `sv_ar_seed_policies(PDO $db): void`, `sv_ar_simulate_policy(PDO $db, int $policyId): array`.

- [ ] **Step 1: Escrever golden cases**

Casos mínimos: fluxo padrão D+45; FBA Onsite/Delivery by Amazon pós-21/04/2026 D+60; política anterior D+45; regra desconhecida retorna `requires_human_review=true` em vez de assumir prazo.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-policy-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar seleção por marketplace/programa/fulfillment/motivo/vigência**

Seed inicial deve registrar fonte textual/referência e vigência; nenhuma constante `45` ou `60` fora do seed/policy data.

- [ ] **Step 4: Rodar golden cases**

Run: `php tests/amazon-recovery-policy-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/policies.php tests/amazon-recovery-policy-test.php && git commit -m "feat: add versioned SAFE-T policy engine"`

---

### Task 5: Ledger financeiro e reconciliação de créditos/reversões

**Files:**
- Create: `includes/amazon-recovery/ledger.php`
- Test: `tests/amazon-recovery-ledger-test.php`

**Interfaces:**
- Produces: `sv_ar_record_ledger_entry(PDO $db, array $entry): int`, `sv_ar_financial_summary(PDO $db, int $caseId): array`, `sv_ar_is_reimbursed(PDO $db, int $caseId): bool`.

- [ ] **Step 1: Testar cenários financeiros**

Cobrir: refund + seller debit; proactive reimbursement integral; crédito SAFE-T parcial; reversão posterior; duplicação da mesma transaction ID.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-ledger-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar ledger normalizado**

Summary deve retornar `risk_amount`, `expected_recovery`, `approved_recovery`, `received_recovery`, `outstanding_recovery` e `has_reversal`.

- [ ] **Step 4: Rodar teste**

Run: `php tests/amazon-recovery-ledger-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/ledger.php tests/amazon-recovery-ledger-test.php && git commit -m "feat: add Amazon recovery financial ledger"`

---

### Task 6: Fila financeira durável, leases, retry e DLQ

**Files:**
- Create: `includes/amazon-recovery/jobs.php`
- Test: `tests/amazon-recovery-jobs-test.php`
- Test: `tests/amazon-recovery-worker-recovery-test.php`

**Interfaces:**
- Produces: `sv_ar_enqueue_job()`, `sv_ar_claim_jobs()`, `sv_ar_complete_job()`, `sv_ar_retry_job()`, `sv_ar_reap_stale_jobs()`, `sv_ar_move_to_dlq()`.

- [ ] **Step 1: Testar concorrência e recovery**

Dois workers não podem receber o mesmo lease. Job stale deve retornar à fila com backoff; após limite configurado deve ir à DLQ preservando payload/contexto.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-jobs-test.php && php tests/amazon-recovery-worker-recovery-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar sobre banco principal**

Não utilizar fallback JSON da fila genérica para este domínio. Usar `SELECT ... FOR UPDATE`/estratégia compatível com MySQL e lease timestamp.

- [ ] **Step 4: Rodar testes**

Expected: PASS, inclusive crash/reap.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/jobs.php tests/amazon-recovery-jobs-test.php tests/amazon-recovery-worker-recovery-test.php && git commit -m "feat: add durable Amazon recovery job queue"`

---

### Task 7: Outbox transacional e idempotência de ações externas

**Files:**
- Create: `includes/amazon-recovery/outbox.php`
- Test: `tests/amazon-recovery-idempotency-test.php`

**Interfaces:**
- Produces: `sv_ar_schedule_action(PDO $db, int $caseId, string $actionType, array $payload): int`, `sv_ar_claim_outbox()`, `sv_ar_mark_action_uncertain()`, `sv_ar_confirm_action()`.

- [ ] **Step 1: Testar rollback e duplicidade**

Se a transação do caso fizer rollback, outbox não pode existir. Mesma idempotency key deve retornar a ação anterior. `SUBMISSION_UNCERTAIN` bloqueia ressubmissão.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-idempotency-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar outbox**

Persistir ação na mesma conexão/transação usada pela decisão. Não executar rede dentro da transação.

- [ ] **Step 4: Rodar teste**

Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/outbox.php tests/amazon-recovery-idempotency-test.php && git commit -m "feat: add transactional Amazon recovery outbox"`

---

### Task 8: Adapters SP-API de Orders, Finances, Reports e Notifications

**Files:**
- Create: `includes/amazon-recovery/amazon-orders.php`
- Create: `includes/amazon-recovery/amazon-finances.php`
- Create: `includes/amazon-recovery/amazon-reports.php`
- Create: `includes/amazon-recovery/amazon-notifications.php`
- Test: `tests/amazon-recovery-api-contract-test.php`

**Interfaces:**
- Produces: funções/adapters que retornam payload normalizado e preservam `request_id` e transaction/report IDs.

- [ ] **Step 1: Adicionar fixtures de contratos oficiais**

Fixtures devem cobrir pedido com programa/fulfillment/item, transaction refund/reimbursement/reversal, report row e notification transaction update.

- [ ] **Step 2: Confirmar falha dos novos asserts**

Run: `php tests/amazon-recovery-api-contract-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar adapters usando `SvAmazonClient`**

Não embutir regras SAFE-T nos adapters. Eles somente fazem transporte, paginação e normalização.

- [ ] **Step 4: Testar fixtures e, quando credenciais estiverem disponíveis no host autorizado, executar smoke read-only real**

Expected: contratos PASS; smoke retorna marketplace BR e ao menos uma chamada read-only válida sem escrever dados Amazon.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/amazon-*.php tests/amazon-recovery-api-contract-test.php && git commit -m "feat: add Amazon recovery SP-API adapters"`

---

### Task 9: Parser Gmail determinístico e cursores de ingestão

**Files:**
- Create: `includes/amazon-recovery/gmail-parser.php`
- Test: `tests/amazon-recovery-gmail-parser-test.php`

**Interfaces:**
- Produces: `sv_ar_parse_amazon_email(array $message): array`, eventos como `REFUND_DETECTED`, `RETURN_AUTHORIZED`, `SAFE_T_OPENED`, `SAFE_T_UPDATED`, `POLICY_CHANGE_CANDIDATE`.

- [ ] **Step 1: Criar fixtures sanitizadas baseadas nos formatos reais observados**

Cobrir subject de reembolso com BRL/order ID, autorização de devolução com SKU/ASIN/quantidade, SAFE-T registrada/atualizada e mudança de política.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-gmail-parser-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar parsing por sender + subject + communicationName + regex estrita**

Mensagens ambíguas retornam `UNKNOWN_AMAZON_MESSAGE` e entram para revisão, nunca são descartadas.

- [ ] **Step 4: Rodar teste**

Expected: PASS e nenhuma fixture depende de IA.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/gmail-parser.php tests/amazon-recovery-gmail-parser-test.php && git commit -m "feat: parse Amazon recovery Gmail events"`

---

### Task 10: Reconciler e Decision Engine

**Files:**
- Create: `includes/amazon-recovery/reconciler.php`
- Create: `includes/amazon-recovery/decision-engine.php`
- Test: `tests/amazon-recovery-reconcile-test.php`
- Test: `tests/amazon-recovery-decision-test.php`

**Interfaces:**
- Produces: `sv_ar_reconcile_case(PDO $db, int $caseId): array`, `sv_ar_decide(PDO $db, int $caseId, DateTimeImmutable $now): array`.

- [ ] **Step 1: Escrever matriz de decisões**

Cobrir: recebeu fisicamente → não abrir “não recebido”; Amazon já ressarciu → fechar/conciliação; D+45 padrão → OPEN_SAFE_T; D+45 DBA com política D+60 → WAIT; SAFE-T existente → não duplicar; negativa → APPEAL_SAFE_T; pedido info → RESPOND_INFO_REQUEST; regra desconhecida → REVIEW_REQUIRED.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-reconcile-test.php && php tests/amazon-recovery-decision-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar reconciliação e decisão pura**

Decision Engine não pode executar rede/navegador. Apenas grava decisão/outbox.

- [ ] **Step 4: Rodar matriz**

Expected: PASS.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/reconciler.php includes/amazon-recovery/decision-engine.php tests/amazon-recovery-reconcile-test.php tests/amazon-recovery-decision-test.php && git commit -m "feat: add Amazon recovery reconciler and decision engine"`

---

### Task 11: Recebimento físico e evidências

**Files:**
- Create: `api/admin/amazon-recovery/receive-return.php`
- Create: `includes/amazon-recovery/dossier.php`
- Test: `tests/amazon-recovery-receiving-test.php`
- Test: `tests/amazon-recovery-dossier-test.php`

**Interfaces:**
- Produces: endpoint autenticado que registra `PHYSICAL_RETURN_RECEIVED`; dossier retorna fatos + evidence refs + hashes.

- [ ] **Step 1: Testar autenticação, quantidade parcial e condição**

Pedido 5 unidades/retorno 3 deve registrar quantidade 3 e manter exposição 2; `carrier_delivered` sem endpoint físico não pode alterar `quantity_received`.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-receiving-test.php && php tests/amazon-recovery-dossier-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar endpoint e evidências com hash SHA-256**

Persistir operador/timestamp e nunca substituir evidência anterior.

- [ ] **Step 4: Rodar testes**

Expected: PASS.

- [ ] **Step 5: Commit**

`git add api/admin/amazon-recovery/receive-return.php includes/amazon-recovery/dossier.php tests/amazon-recovery-receiving-test.php tests/amazon-recovery-dossier-test.php && git commit -m "feat: record physical Amazon returns and evidence"`

---

### Task 12: Dashboard read-only, gates e feature flags

**Files:**
- Create: `includes/amazon-recovery/feature-flags.php`
- Create: `includes/amazon-recovery/health.php`
- Create: `api/admin/amazon-recovery/index.php`
- Create: `api/admin/amazon-recovery/case.php`
- Create: `admin/amazon-recovery.php`
- Modify: `.env.example`

**Interfaces:**
- Produces: dashboard com risco, elegíveis, recurso, crédito pendente, gates; flags `AMAZON_RECOVERY_AUTO_CREATE_SAFE_T`, `AMAZON_RECOVERY_AUTO_REPLY_INFO`, `AMAZON_RECOVERY_AUTO_APPEAL`, `AMAZON_RECOVERY_KILL_SWITCH`.

- [ ] **Step 1: Criar teste de contratos de health/flags no teste de decisão**

Kill switch deve forçar `REVIEW_REQUIRED/WAIT` para qualquer ação externa sem interromper reconciliação.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-decision-test.php`
Expected: FAIL nos novos asserts.

- [ ] **Step 3: Implementar flags, health e dashboard sem escrita Amazon**

Dashboard usa `admin-guard.php` e PDO existente.

- [ ] **Step 4: Smoke local/admin autenticado onde possível**

Expected: HTTP 200 autenticado, nenhum secret no HTML/JSON.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/feature-flags.php includes/amazon-recovery/health.php api/admin/amazon-recovery admin/amazon-recovery.php .env.example tests/amazon-recovery-decision-test.php && git commit -m "feat: add Amazon recovery dashboard and safety flags"`

---

### Task 13: Worker, reconciliação periódica e backfill em shadow mode

**Files:**
- Create: `scripts/amazon-recovery-worker.php`
- Create: `scripts/amazon-recovery-reconcile.php`
- Create: `scripts/amazon-recovery-backfill.php`
- Test: `tests/amazon-recovery-e2e-shadow-test.php`

**Interfaces:**
- Produces: CLI workers idempotentes, cursores persistidos e modo `--shadow` obrigatório por default no backfill.

- [ ] **Step 1: Testar replay/backfill repetido**

Executar mesma fixture histórica duas vezes deve produzir mesmos casos/eventos/ledger sem duplicar.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-e2e-shadow-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar workers com limites e shutdown seguro**

Persistir cursor somente após commit dos eventos. Backfill nunca habilita auto-submit.

- [ ] **Step 4: Rodar teste e smoke read-only real em host autorizado**

Expected: PASS; backfill cria somente estado shadow.

- [ ] **Step 5: Commit**

`git add scripts/amazon-recovery-worker.php scripts/amazon-recovery-reconcile.php scripts/amazon-recovery-backfill.php tests/amazon-recovery-e2e-shadow-test.php && git commit -m "feat: add Amazon recovery workers and shadow backfill"`

---

### Task 14: Executor Seller Central isolado e verificação pós-ação

**Files:**
- Create: `scripts/amazon-recovery-seller-central.php`
- Create: `docs/amazon-recovery/SELLER-CENTRAL-EXECUTOR.md`
- Extend: `tests/amazon-recovery-idempotency-test.php`

**Interfaces:**
- Consumes: outbox `OPEN_SAFE_T`, `RESPOND_INFO_REQUEST`, `APPEAL_SAFE_T`.
- Produces: `SAFE_T_OPENED`, `SAFE_T_INFO_RESPONDED`, `APPEAL_OPENED`, ou `SUBMISSION_UNCERTAIN` com evidence snapshot.

- [ ] **Step 1: Testar executor fake**

Criar modo `--driver=fake` que simula success, timeout-after-submit, not-eligible, session-expired e duplicate-existing. Timeout-after-submit deve ficar incerto e nunca reenviar sem verify.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-idempotency-test.php`
Expected: FAIL nos cenários novos.

- [ ] **Step 3: Implementar bridge com interface de driver e verifier separado**

O código do domínio não depende de seletor CSS. Seletores/automação ficam no driver remoto documentado.

- [ ] **Step 4: Validar fake e depois canary somente leitura no Seller Central remoto autorizado**

Primeiro confirmar localização de página/caso e leitura de elegibilidade sem submissão. Não habilitar escrita até shadow validar casos reais.

- [ ] **Step 5: Commit**

`git add scripts/amazon-recovery-seller-central.php docs/amazon-recovery/SELLER-CENTRAL-EXECUTOR.md tests/amazon-recovery-idempotency-test.php && git commit -m "feat: add isolated Seller Central SAFE-T executor"`

---

### Task 15: Geração fundamentada de reivindicação/recurso e fact-check

**Files:**
- Create: `includes/amazon-recovery/appeal-generator.php`
- Test: `tests/amazon-recovery-dossier-test.php`

**Interfaces:**
- Produces: `sv_ar_generate_claim_text(array $dossier, array $policy): array` com `text`, `facts`, `evidence_refs`, `confidence`, `missing_evidence`.

- [ ] **Step 1: Testar proibição de fato não sustentado**

Fixture sem evidência de recebimento não pode dizer que item chegou; fixture sem transaction source não pode afirmar que reembolso foi automático Amazon.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-dossier-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar gerador com camada determinística de fact extraction e interface opcional para LLM**

Mesmo com LLM desabilitado deve produzir template fundamentado. LLM só melhora redação e não recebe permissão para criar fatos fora do conjunto validado.

- [ ] **Step 4: Rodar golden cases históricos sanitizados**

Expected: textos contêm somente fatos presentes no dossier.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/appeal-generator.php tests/amazon-recovery-dossier-test.php && git commit -m "feat: generate evidence-grounded SAFE-T claims and appeals"`

---

### Task 16: Policy Watcher, simulação e aprovação de candidatos

**Files:**
- Create: `scripts/amazon-recovery-policy-watch.php`
- Extend: `includes/amazon-recovery/policies.php`
- Extend: `tests/amazon-recovery-policy-test.php`

**Interfaces:**
- Produces: candidatos `candidate`; relatório de simulação antes de `active`.

- [ ] **Step 1: Testar mudança 45→60 como candidate**

Inserir candidato não pode alterar decisão ativa. `sv_ar_simulate_policy()` deve listar quais case IDs mudariam.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-policy-test.php`
Expected: FAIL nos novos asserts.

- [ ] **Step 3: Implementar watcher e promoção explícita**

Watcher registra fonte/hash/data, nunca promove automaticamente.

- [ ] **Step 4: Rodar teste**

Expected: PASS.

- [ ] **Step 5: Commit**

`git add scripts/amazon-recovery-policy-watch.php includes/amazon-recovery/policies.php tests/amazon-recovery-policy-test.php && git commit -m "feat: monitor and simulate Amazon recovery policy changes"`

---

### Task 17: Observabilidade, incidentes, backup/restore e gates operacionais

**Files:**
- Extend: `includes/amazon-recovery/health.php`
- Create: `docs/amazon-recovery/RUNBOOK.md`
- Create: `docs/amazon-recovery/DISASTER-RECOVERY.md`
- Extend: `tests/amazon-recovery-worker-recovery-test.php`

**Interfaces:**
- Produces: health JSON, runbook de sessão/MFA/DLQ/rollback, restore validation.

- [ ] **Step 1: Testar health degradado**

DLQ>0, deadline vencido, action uncertain ou policy unknown devem tornar health `degraded`/`critical` com métrica específica.

- [ ] **Step 2: Confirmar falha**

Run: `php tests/amazon-recovery-worker-recovery-test.php`
Expected: FAIL.

- [ ] **Step 3: Implementar health e documentação operacional executável**

Runbook inclui comandos reais do projeto para pausar auto-write, reconciliar, reaplicar jobs e validar restore.

- [ ] **Step 4: Executar teste de recuperação**

Simular worker parado e cursor antigo; reinício deve processar backlog sem duplicar eventos. Em ambiente autorizado, validar backup/restore de banco de teste/staging, nunca destruir produção.

- [ ] **Step 5: Commit**

`git add includes/amazon-recovery/health.php docs/amazon-recovery tests/amazon-recovery-worker-recovery-test.php && git commit -m "ops: add Amazon recovery health and disaster runbooks"`

---

### Task 18: CI, documentação de agentes e validação final do módulo

**Files:**
- Modify: workflow de CI aplicável em `.github/workflows/`
- Modify: `docs/AGENTS.md`
- All tests listed above.

**Interfaces:**
- Produces: gate CI obrigatório para o módulo antes de merge.

- [ ] **Step 1: Adicionar comando agregador da suíte**

Executar sequencialmente todos os `tests/amazon-recovery-*.php` e falhar no primeiro erro.

- [ ] **Step 2: Rodar suíte completa localmente**

Run: `for f in tests/amazon-recovery-*.php; do php "$f" || exit 1; done`
Expected: todos PASS.

- [ ] **Step 3: Rodar lint PHP dos arquivos alterados**

Run: `git diff --name-only --diff-filter=ACMR origin/main...HEAD | grep -E '\.php$' | xargs -r -n1 php -l`
Expected: nenhuma falha de sintaxe.

- [ ] **Step 4: Rodar testes existentes impactados de marketplace/admin/queue e smoke read-only real**

Verificar Amazon publisher, admin guard, PDO e integração de marketplace. O smoke Amazon deve ser apenas leitura até as flags de escrita serem promovidas.

- [ ] **Step 5: Atualizar `docs/AGENTS.md`**

Registrar: source-of-truth, proibição de usar Gmail como autoridade financeira, D+45 checkpoint, Policy Engine, idempotência, outbox e requisito de validação real antes de merge.

- [ ] **Step 6: Commit**

`git add .github/workflows docs/AGENTS.md tests && git commit -m "test: gate Amazon recovery workflow in CI"`

---

### Task 19: Shadow backfill real e golden cases

**Files:**
- Runtime data only; fixtures sanitizadas podem ser adicionadas em `tests/fixtures/amazon-recovery/` sem PII.

**Interfaces:**
- Produces: baseline de casos reais, divergências e métricas de precisão.

- [ ] **Step 1: Executar backfill read-only com Gmail + SP-API**

Importar janela inicial configurável (recomendado 180 dias ou máximo disponível) sem auto-submit.

- [ ] **Step 2: Selecionar golden cases reais**

Cobrir: não retornado, reembolsado pela Amazon, ressarcido proativamente, retorno parcial, retorno físico, negativa SAFE-T, aprovação, recurso, crédito/reversão.

- [ ] **Step 3: Sanitizar e adicionar fixtures**

Remover nomes/e-mails/endereço e preservar IDs sintéticos, timestamps relativos, valores e sequência de eventos necessários ao teste.

- [ ] **Step 4: Rodar novamente toda suíte**

Expected: PASS e replay do backfill idempotente.

- [ ] **Step 5: Commit**

`git add tests/fixtures/amazon-recovery tests && git commit -m "test: add real-world Amazon recovery golden cases"`

---

### Task 20: PR, CI, merge e rollout controlado

**Files:**
- No new code unless CI/review finds defects.

- [ ] **Step 1: Verificar árvore limpa**

Run: `git status --short`
Expected: vazio.

- [ ] **Step 2: Push branch e abrir PR**

PR deve listar arquitetura, políticas, testes, segurança, limites de automação e evidências do shadow mode.

- [ ] **Step 3: Aguardar/inspecionar CI e corrigir falhas**

Nenhum merge enquanto teste/lint/smoke aplicável falhar.

- [ ] **Step 4: Revisão final de idempotência e segurança**

Confirmar que secrets não aparecem em diff/logs e que flags auto-write estão desligadas por default.

- [ ] **Step 5: Merge apenas após gates verdes**

Usar merge permitido pelo repositório e confirmar `main` atualizado.

- [ ] **Step 6: Verificação pós-merge**

Executar smoke read-only em produção, health do módulo e confirmar gates sem ações incertas/DLQ criada pelo deploy.

- [ ] **Step 7: Rollout**

Manter `OBSERVE/SHADOW` até precisão validada. Depois promover canary de auto-submit em casos de alta confiança; `AUTO_APPEAL` permanece desligado até corpus histórico suficiente.

---

## Self-Review

### Cobertura da especificação

- SP-API/Gmail/Reports: Tasks 8–9.
- Event sourcing/cases: Tasks 2–3.
- D+45/D+60/políticas: Tasks 4 e 16.
- Ledger/reembolso automático/conciliação/reversão: Task 5 e 10.
- Fila/outbox/DLQ/idempotência: Tasks 6–7 e 14.
- Recebimento físico/evidência: Task 11.
- Decision Engine: Task 10.
- Seller Central: Task 14.
- IA fundamentada/recurso: Task 15.
- Dashboard/feature flags/gates: Task 12 e 17.
- Backfill/golden cases: Tasks 13 e 19.
- Segurança/DR/CI/merge: Tasks 17–20.

### Sem placeholders

O plano não contém etapas `TBD/TODO/implementar depois`. Escritas Amazon reais permanecem deliberadamente bloqueadas por rollout até shadow/canary, conforme requisito de segurança da especificação, e não por falta de implementação.

### Consistência de interfaces

O fluxo é: adapters → events/ledger → reconciler → decision engine → outbox → executor → events → reconciler. Policy Engine e dossier são serviços consultados, não executores de efeitos externos.
