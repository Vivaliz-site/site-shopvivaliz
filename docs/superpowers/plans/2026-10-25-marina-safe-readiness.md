# Marina Safe Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent source editorial/email execution and correctly scope certificate-renewal readiness to Marina.

**Architecture:** Keep all runtime mutations behind their existing approved operator flows. Versioned GitHub workflows gain only fail-closed Marina runner-identity checks; unit tests inspect their contracts. The migration runbook records which remaining actions require an official operator.

**Tech Stack:** GitHub Actions YAML, Python unittest, Markdown.

**Spec:** `docs/superpowers/specs/2026-10-25-marina-safe-readiness-design.md`

## Global Constraints

- Do not start, stop, enable, disable, or delete source resources.
- Do not create a blog/email scheduler or invoke DNS, Certbot, Supabase, Docker, or MySQL maintenance.
- A maintenance lease, fencing token, signer, operator credential, or UI proof cannot be inferred from chat approval.
- Preserve `storage/codex-bridge/state.json` as unrelated local state.

## Review Focus

- A source-fence check must execute before the blog workflow's SSH publisher.
- The certificate repair job must accept only Marina and must remain non-DNS/non-Certbot.
- Tests must distinguish static readiness from an executed certificate renewal or UI E2E.
- Documentation must preserve `NAO_APTO` and `NO_DECOMMISSION`.
- No path may add an additional writer, timer, cron, lease, or privileged bypass.

---

### Task 1: Marina-only editorial/email execution guard

**Files:**
- Modify: `.github/workflows/blog-publish-scheduled.yml`
- Test: `tests/test_marina_safe_readiness.py`

- [x] Write a failing test requiring a gate before the publisher SSH step.
- [x] Run it and verify RED.
- [x] Add the minimal Marina runner identity gates without creating a runner or scheduler.
- [x] Run the focused test and verify GREEN.

### Task 2: Marina-only certificate-repair readiness

**Files:**
- Modify: `.github/workflows/ml-pricing-certbot-renewal-repair.yml`
- Test: `tests/test_marina_safe_readiness.py`

- [x] Write a failing test requiring Marina identity and denying Certbot/DNS use.
- [x] Run it and verify RED.
- [x] Scope the repair workflow to Marina without dispatching it.
- [x] Run the focused test and verify GREEN.

### Task 3: Evidence and regression validation

**Files:**
- Modify: `docs/ops/oci-marina-migration-v5-20261017.md`
- Test: `tests/test_marina_safe_readiness.py`, `tests/test_marina_global_cutover_scope.py`

- [x] Record bounded readiness evidence and remaining official gates.
- [x] Run focused and related regression tests.
- [x] Review the diff for prohibited runtime mutations.

### Task 4: Independent-review remediation

**Files:**
- Modify: `.github/workflows/blog-publish-scheduled.yml`
- Modify: `.github/workflows/product-highlights-email.yml`
- Modify: `.github/workflows/ml-pricing-certbot-renewal-repair.yml`
- Modify: `tests/test_marina_safe_readiness.py`, `tests/real-email-campaigns-source-test.php`
- Modify: migration evidence/design documents

- [x] Record the review finding that an origin fence evaluated through `127.0.0.1` would not prove a Marina-only writer.
- [x] Require the short Marina hostname before any SSH material or publisher command.
- [x] Mark certificate repair blocked until a runner is officially provisioned and attested.
- [x] Re-run focused regression and validation suite after remediation.
