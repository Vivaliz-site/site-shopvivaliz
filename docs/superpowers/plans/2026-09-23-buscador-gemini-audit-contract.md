# Buscador Gemini Audit Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the versioned Buscador Gemini defaults, UI auditor, documentation, and regression tests with the production contract `gemini-3.5-flash` while preserving the Claude Code OAuth-only provider path.

**Architecture:** The profile catalog remains the source for deep-research model selection. The UI audit validates the rendered provider contract, and its shell contract test prevents a stale literal from returning. Gemini 3.x continues to serialize medium thinking via `thinkingLevel`; no provider fallback or authentication path changes.

**Tech Stack:** PHP 8, Node.js ESM, Bash contract tests, Playwright UI auditor, GitHub Actions.

**Spec:** User request dated 2026-09-23: OpenAI `gpt-5.6-terra`; Claude `claude-sonnet-5` via Claude Code OAuth; Gemini `gemini-3.5-flash`; deep research; medium reasoning; Fable prohibited; full three-provider consensus is fail-closed.

## Global Constraints

- Do not use or introduce `ANTHROPIC_API_KEY`, direct Anthropic API, OpenRouter, or Fable for the Claude Buscador provider.
- Keep Claude bridge binding local-only at `127.0.0.1:17657`; never log or commit credentials.
- Do not edit `/home/ubuntu/shopvivaliz-deploy/current` or perform a manual deploy.
- Preserve 9/9 provider-phase coverage, mandatory research sources, and consensus fail-closed behavior.
- Commit through a branch, PR, required checks, merge, auto gate, and post-deploy validation.

## Review Focus

- Deep-research default with no Gemini environment override must select `gemini-3.5-flash` and `thinkingLevel=medium`.
- An audit with a stale Gemini literal must fail rather than pass against the wrong UI contract.
- The UI audit must still require all four visible phases, exactly nine messages, source links, verified providers, and consensus metadata.
- Claude OAuth-only routing and the bridge loopback binding must remain unchanged by this focused model-contract correction.
- Deployment must originate only from the merged `main` SHA and the Auto Gate release.

---

### Task 1: Make the Gemini 3.5 contract explicit and regression-tested

**Files:**
- Modify: `tests/ai-squad-core-test.php`
- Modify: `tests/ai-squad-ui-audit-contract-test.sh`
- Modify: `tests/gepeto-buscador-doc-contract-test.php`
- Modify: `includes/ai-squad-core.php`
- Modify: `scripts/ai-squad-ui-audit.mjs`
- Modify: `docs/knowledge/buscador.md`

**Interfaces:**
- Consumes: `svais_profile_catalog(): array` and the `#models` text rendered by `admin/buscador.php`.
- Produces: deep-research Gemini defaults and UI-auditor assertions that consistently require `gemini-3.5-flash`.

- [x] **Step 1: Write failing assertions for the desired default and auditor literal.**

  Change the core test fallback to `gemini-3.5-flash` and `['thinkingLevel' => 'medium']`; change the shell contract to require `gemini-3.5-flash` and reject `gemini-2.5-flash` in the UI auditor.

- [x] **Step 2: Verify RED.**

  Run: `php tests/ai-squad-core-test.php && bash tests/ai-squad-ui-audit-contract-test.sh`

  Expected: fail because the current catalog and UI auditor still contain the 2.5 model contract.

- [x] **Step 3: Implement the minimal source alignment.**

  Set the deep-research and balanced Gemini defaults to `gemini-3.5-flash`; retain `thinking_level` as `MEDIUM`; replace only the stale UI-auditor expected literal; document that Gemini 3.x uses `thinkingLevel`. Replace the stale test commands in the Buscador runbook and assert their contract so it cannot reference missing files again.

- [x] **Step 4: Verify GREEN and nearby contracts.**

  Run: `php tests/ai-squad-core-test.php && bash tests/ai-squad-ui-audit-contract-test.sh && bash tests/ai-squad-three-provider-runtime-contract-test.sh && node tests/ai-squad-claude-bridge-test.mjs`

  Expected: all commands exit zero and retain the OAuth, source, contention, and loopback assertions.

- [x] **Step 5: Commit the focused correction.**

  Run: `git add includes/ai-squad-core.php scripts/ai-squad-ui-audit.mjs tests/ai-squad-core-test.php tests/ai-squad-ui-audit-contract-test.sh docs/knowledge/buscador.md docs/superpowers/plans/2026-09-23-buscador-gemini-audit-contract.md && git commit -m "fix(buscador): align Gemini UI audit with production model"`

### Task 2: Serialize Claude Code work around OAuth refresh

**Files:**
- Modify: `ops/ai-squad/claude-bridge.mjs`
- Modify: `includes/ai-squad-core.php`
- Modify: `tests/ai-squad-claude-bridge-test.mjs`
- Modify: `tests/ai-squad-core-test.php`

**Interfaces:**
- Consumes: bridge requests, health probes, and Claude Code's credential-store OAuth refresh behavior.
- Produces: one serialized Claude work stream, explicit `oauth_refresh_contention`, and one bounded retry without a persistent-failure false green.

- [x] **Step 1: Write failing contention tests.**

  Require the observed OAuth refresh message to classify as `oauth_refresh_contention`, require concurrent bridge work to run one at a time, require the PHP boundary to retain that class, and require one 250 ms retry for a transient contention.

- [x] **Step 2: Verify RED.**

  Run: `php tests/ai-squad-core-test.php` and `node tests/ai-squad-claude-bridge-test.mjs`.

  Expected: the old bridge reports generic `auth` and permits concurrent work.

- [x] **Step 3: Implement the minimal queue and retry.**

  Serialize both health probes and provider requests through one recovered promise chain. Retry only an explicit OAuth refresh contention once with a bounded delay; rethrow every persistent failure.

- [x] **Step 4: Verify GREEN.**

  Run: `php -l includes/ai-squad-core.php && node --check ops/ai-squad/claude-bridge.mjs && php tests/ai-squad-core-test.php && node tests/ai-squad-claude-bridge-test.mjs && bash tests/ai-squad-three-provider-runtime-contract-test.sh`.

### Task 3: Validate the merged production release and real UI journey

**Files:**
- No source changes expected; use the production release, GitHub PR/Actions, and browser-worker evidence.

**Interfaces:**
- Consumes: merged main SHA, immutable release marker, Buscador health, Claude bridge health, and the UI auditor result.
- Produces: evidence for all three providers, nine messages, research-source counts, consensus, screenshot, and Auto Gate release provenance.

- [ ] **Step 1: Open and merge the PR only after required checks pass.**

  Confirm the PR diff contains no credential/runtime state, merge through GitHub protections, and record the merge SHA.

- [ ] **Step 2: Confirm the Auto Gate publication without manual deployment.**

  Wait for the Master Production Pipeline / Auto Gate to publish the merge SHA; compare `origin/main`, `current/.release-sha`, and `/api/health/version.php`.

- [ ] **Step 3: Perform targeted provider validation.**

  Check redacted `claude auth status --json`, loopback bridge `/health`, one noninteractive Claude inference using `claude-sonnet-5` with `WebSearch` and `WebFetch`, source extraction, and the three Buscador provider health states.

- [ ] **Step 4: Run the exact real browser E2E on the designated VM.**

  Execute the raquete Yonex query in the production Buscador UI, preserve its screenshot, and assert three messages per provider, nine messages total, one research source per provider, visible `Síntese de consenso`, `complete_provider_coverage=true`, `consensus_available=true`, verified finals, no errors, and no manual intervention.

- [ ] **Step 5: Record completion only from fresh evidence.**

  Confirm clean task worktree, no task PR remains open, and report the evidence fields requested by the user.
