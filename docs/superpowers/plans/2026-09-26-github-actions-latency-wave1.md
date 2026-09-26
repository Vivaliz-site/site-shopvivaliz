# GitHub Actions Latency Wave 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduzir espera ociosa e downloads repetidos nos GitHub Actions sem enfraquecer gates obrigatorios nem consumir o runner Oracle enquanto a producao ainda esta sendo publicada.

**Architecture:** Separar o gatilho de codigo/PR do gatilho de auditoria live. Os audits de producao selecionados passam a reagir ao `workflow_run` concluido do `Master Production Pipeline 24/7`, validando a evidencia de deploy uma unica vez antes de reservar o A1. O reparo de PR continua manual e gratuito, mas restaura o cache do modelo Ollama antes do `ollama pull`.

**Tech Stack:** GitHub Actions YAML, Bash, Python unittest/contract tests, Ollama cache via `actions/cache@v4`.

**Spec:** `docs/knowledge/task-continuity.md` e os contratos existentes em `tests/test_ci_latency_contract.py`, `tests/test_ecommerce_excellence_deploy_wait_retry.py` e `tests/unit/test_runtime_deploy_reconciliation.py`.

## Global Constraints

- Nenhum check obrigatorio de PR pode deixar de ser criado por causa de path filtering novo.
- Jobs live de producao continuam no runner `shopvivaliz-a1-deploy`.
- Espera por deploy nao pode ocupar runner hospedado em loop de `sleep` nos dois workflows migrados.
- Evento de deploy so libera auditoria live quando a evidencia imutavel corresponde ao SHA do `workflow_run` e todos os jobs de deploy estao `success`.
- `pull_request` do Ecommerce Excellence continua produzindo o gate estatico esperado pelo PR Completion Enforcer.
- Nao remover `production-release-await.yml`: outros fluxos ainda dependem de `allow_descendant`.
- Ollama continua sendo IA local/gratuita; cache nao autoriza API paga nem altera caminhos protegidos.

## Review Focus

- Master pipeline termina `success` sem deploy porque `should_deploy=false`: audit live deve pular, nao falhar nem rodar em release antiga.
- Um deploy subsequente ja atualizou `deployment/latest.json`: o evento antigo deve pular se a evidencia nao for exatamente seu SHA.
- `workflow_run` do master com conclusao diferente de `success`: nenhum audit live pode reservar A1.
- PR Ecommerce: apenas gate estatico; nenhum job live/polling deve rodar.
- Cache Ollama ausente ou stale: `ollama pull` continua obrigatorio para validar/atualizar o modelo.

---

### Task 1: Ecommerce Excellence post-deploy event

**Files:**
- Modify: `.github/workflows/ecommerce-excellence-audit.yml`
- Modify: `tests/test_ecommerce_excellence_deploy_wait_retry.py`
- Modify: `tests/test_ci_latency_contract.py`

**Interfaces:**
- Consumes: `Master Production Pipeline 24/7` workflow_run with `head_sha` and `conclusion`.
- Produces: hosted evidence gate outputs `should_run` and `audit_sha`; live audit consumes `audit_sha`.

- [ ] **Step 1: Write failing event-driven contract tests**
- [ ] **Step 2: Verify tests fail on current polling implementation**
- [ ] **Step 3: Add `workflow_run` trigger and single-read immutable-evidence gate; remove inline 36x20s polling**
- [ ] **Step 4: Ensure static PR/push gate skips only the post-deploy workflow_run**
- [ ] **Step 5: Run focused tests and actionlint-compatible validation**
- [ ] **Step 6: Commit**

### Task 2: Runtime Token Security post-deploy event

**Files:**
- Modify: `.github/workflows/runtime-token-security.yml`
- Modify: `tests/unit/test_runtime_deploy_reconciliation.py`
- Modify: `tests/nondeploy-release-wait-cost-guard-test.php`

**Interfaces:**
- Consumes: completed `Master Production Pipeline 24/7` events for main plus schedule/manual triggers.
- Produces: exact evidence gate; audit job reserves A1 only after one-read validation.

- [ ] **Step 1: Write failing contract proving no reusable polling wait for Runtime Token Security**
- [ ] **Step 2: Verify RED**
- [ ] **Step 3: Replace push+await path with workflow_run exact-evidence gate; keep schedule/manual direct audits**
- [ ] **Step 4: Verify failed/non-deploy master runs do not reserve A1**
- [ ] **Step 5: Run focused regression suite**
- [ ] **Step 6: Commit**

### Task 3: Ollama model cache for stale PR repair

**Files:**
- Modify: `.github/workflows/ai-stale-pr-repair.yml`
- Modify: `tests/test_ci_latency_contract.py`

**Interfaces:**
- Consumes: `OLLAMA_MODEL=qwen2.5-coder:1.5b`.
- Produces: restored `~/.ollama/models` cache before install/pull; `ollama pull` remains source-of-truth freshness check.

- [ ] **Step 1: Write failing cache contract**
- [ ] **Step 2: Verify RED**
- [ ] **Step 3: Add conditional `actions/cache@v4` for `~/.ollama/models` keyed by OS/arch/model**
- [ ] **Step 4: Keep `ollama pull` after cache restore**
- [ ] **Step 5: Run contract tests**
- [ ] **Step 6: Commit**

### Task 4: Validation, review and rollout

**Files:**
- Modify only if review finds a defect.

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: mergeable PR with green mandatory gates and post-merge evidence.

- [ ] **Step 1: Run `python3 -m unittest tests.test_ci_latency_contract tests.unit.test_runtime_deploy_reconciliation -v` and ecommerce contract**
- [ ] **Step 2: Run repository governance validator and YAML/actionlint gate through CI**
- [ ] **Step 3: Review diff for required-check semantics and runner labels**
- [ ] **Step 4: Open PR, wait for mandatory gates, repair any failure**
- [ ] **Step 5: Merge and verify main workflows**
