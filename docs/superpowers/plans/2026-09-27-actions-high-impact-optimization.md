# GitHub Actions High-Impact Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce GitHub Actions runner-minutes and production-runner contention without weakening required checks, post-deploy validation, or task-continuity guarantees.

**Architecture:** Keep PR/static validation event-local, but move production-live audits to a post-deploy event gate driven by `Master Production Pipeline 24/7` completion instead of sleep/poll loops. Cache only the expensive Ollama model payload for manual stale-PR repair while still verifying the model is usable before resolution. Do not mass-apply path filters or concurrency changes until their required-check semantics are proven.

**Tech Stack:** GitHub Actions YAML, bash, Python unittest/contract tests, GitHub API via `gh`.

**Spec:** User-approved performance review consolidated from live Actions data and current repository state.

## Global Constraints

- Required PR checks must continue to emit a terminal status; no broad `paths-ignore` on mandatory workflows.
- No production/self-hosted runner may be occupied solely waiting for deploy evidence.
- Background/recurring automation must not introduce paid-AI fallback.
- Post-deploy live audits run only after a real successful deploy, or by explicit schedule/manual trigger.
- `main`/production releases are never edited in place; changes flow through PR/gates/merge/deploy.
- Existing `production-release-await.yml` remains for unrelated callers until each is migrated with its own acceptance proof.
- Item 17 is treated as already implemented on current `main`: workflow-run targets one PR, schedule budgets one PR, full sweep is manual-only.

## Review Focus

- A successful pipeline with `deploy` skipped must not trigger a live production audit.
- A successful real deploy must trigger each migrated live audit exactly after evidence is published, with no polling loop.
- Schedule/manual audits must still run against current production without requiring a deploy event.
- A stale/mismatched deployment-evidence SHA must fail closed rather than audit the wrong release.
- Ollama cache restore must never be treated as proof that the model is valid; runtime model verification remains mandatory.

---

### Task 1: Event-driven production audit gate

**Files:**
- Create: `.github/workflows/production-deploy-event-gate.yml`
- Create: `tests/test_production_deploy_event_gate.py`
- Modify: `.github/workflows/ecommerce-excellence-audit.yml`
- Modify: `.github/workflows/runtime-token-security.yml`
- Modify: `tests/test_ecommerce_excellence_deploy_wait_retry.py`
- Modify: `tests/unit/test_runtime_deploy_reconciliation.py`
- Modify: `tests/nondeploy-release-wait-cost-guard-test.php`

**Interfaces:**
- Consumes: completed `Master Production Pipeline 24/7` run id, conclusion, and head SHA.
- Produces: reusable workflow outputs `should_run` and `production_sha`.

- [ ] **Step 1: Write failing event-gate tests**
  - Assert both migrated workflows contain `workflow_run` on `Master Production Pipeline 24/7`.
  - Assert neither migrated workflow contains `sleep 20`, `await-production-evidence`, or a call to `production-release-await.yml`.
  - Execute the reusable gate script against fake `gh` responses for: deploy success+matching evidence, deploy skipped, and mismatched evidence.

- [ ] **Step 2: Run tests and observe RED**
  - Run: `python3 tests/test_production_deploy_event_gate.py && python3 tests/test_ecommerce_excellence_deploy_wait_retry.py && python3 -m unittest tests.unit.test_runtime_deploy_reconciliation -v && php tests/nondeploy-release-wait-cost-guard-test.php`
  - Expected: failure because the event gate/workflow_run contract does not yet exist.

- [ ] **Step 3: Implement the reusable deploy-event gate**
  - One hosted job, no sleep loop.
  - For `workflow_run`: query source run jobs once; `deploy=skipped` => `should_run=false`; `deploy=success` => verify exact immutable deployment evidence and output the SHA; any inconsistent state fails closed.
  - For `schedule`/`workflow_dispatch`: `should_run=true` with current production semantics.
  - For `push`: `should_run=false`; the later deploy event owns the live audit.

- [ ] **Step 4: Migrate Ecommerce Excellence**
  - Static audit remains on PR/push/schedule/manual, but skips `workflow_run`.
  - Delete the inline 36x20s evidence polling job and production-impact wait path.
  - Live audit depends on the reusable gate and checks out the exact deployed SHA when event-driven.
  - Separate concurrency phase so a post-deploy event cannot cancel the push static audit.

- [ ] **Step 5: Migrate Runtime Token Security**
  - Replace push/poll reconciliation with schedule/manual + post-deploy `workflow_run`.
  - Audit depends on the reusable event gate; no hosted polling loop remains.

- [ ] **Step 6: Run task verification**
  - Same command as Step 2.
  - Expected: all PASS; grep of both migrated workflows shows no `sleep 20` or old await job.

### Task 2: Cache Ollama model for manual stale-PR repair

**Files:**
- Modify: `.github/workflows/ai-stale-pr-repair.yml`
- Create: `tests/test_ai_stale_pr_repair_cache.py`

**Interfaces:**
- Consumes: `OLLAMA_MODEL=qwen2.5-coder:1.5b`.
- Produces: restored `~/.ollama/models` cache, with runtime `ollama show` verification before deciding whether to pull.

- [ ] **Step 1: Write failing cache contract**
  - Require `actions/cache@v4` scoped to the real-conflict path.
  - Require cache path `~/.ollama/models`.
  - Require a model-specific cache key.
  - Require `ollama show "$OLLAMA_MODEL"` before conditional `ollama pull "$OLLAMA_MODEL"`; cache-hit alone is not sufficient.

- [ ] **Step 2: Run RED**
  - Run: `python3 tests/test_ai_stale_pr_repair_cache.py`
  - Expected: FAIL because no cache/model-verification contract exists.

- [ ] **Step 3: Implement minimal model cache**
  - Restore/save only model payload, not system binaries.
  - Keep current Ollama install/start path.
  - Pull only when `ollama show` fails after server readiness.

- [ ] **Step 4: Run GREEN**
  - Run: `python3 tests/test_ai_stale_pr_repair_cache.py && python3 -m unittest tests.test_ai_conflict_resolver -v`
  - Expected: PASS.

### Task 3: Whole-branch performance regression guard

**Files:**
- Create: `tests/test_actions_high_impact_performance_contract.py`
- Modify: `.github/workflows/repository-governance.yml` or its canonical validation script only if needed to execute this contract.

**Interfaces:**
- Consumes: workflows changed in Tasks 1-2.
- Produces: static fail-closed contract preventing reintroduction of O(n) scheduled PR sweep, deploy polling in migrated workflows, and uncached Ollama model pulls.

- [ ] **Step 1: Write failing aggregate guard**
  - Assert scheduled PR enforcer budget remains one PR and full sweep is workflow_dispatch-only.
  - Assert migrated audits have no deployment `sleep` loops.
  - Assert Ollama model cache contract remains present.

- [ ] **Step 2: Run RED/GREEN appropriately**
  - If Tasks 1-2 already satisfy all assertions, add one deliberate negative fixture/check to prove the guard can fail before accepting it.
  - Final: `python3 tests/test_actions_high_impact_performance_contract.py` PASS.

- [ ] **Step 3: Run repository gates**
  - `python3 scripts/validate-task-continuity-enforcement.py`
  - `bash scripts/repository-governance-validate.sh`
  - `python3 -m unittest tests.unit.test_runtime_deploy_reconciliation -v`
  - targeted Python/PHP tests above.
  - Expected: all PASS.

## Explicitly deferred from this plan

- Broad `paths-ignore` rollout: unsafe for required checks until skip-status semantics are designed.
- Mass addition of `concurrency`: do only after identifying workflows where cancellation is semantically safe.
- Cron spacing changes: require business freshness requirements per workflow.
- Repository-wide composite-action refactor and 236-workflow redundancy cleanup: larger architectural project.
- Repository Governance “empty report” fix: current live runs pass `Verify evidence files`; do not patch a historical symptom without a current reproducible failure.
