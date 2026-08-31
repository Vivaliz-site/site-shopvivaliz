# Repository Workflow Governance Debt Remediation Design

Date: 2026-08-30
Baseline: `e59dae3e765fdb046f6ad18ace781056b821d427`
Repository: `Vivaliz-site/site-shopvivaliz`

## Context

Google Ads Phase 1 is merged, deployed, and read-only safe, but the repository-wide workflow policy audit still fails on `main`. The active-workflow audit scans 209 workflows and reports 29 blocking findings spread across 16 workflow files. These findings are pre-existing governance debt; the Phase 1 changes did not introduce them.

The repository must reach a state where the strict global audit passes on `main` without suppressing, excluding, or allowlisting unsafe patterns.

## Goals

1. Reduce repository-wide active-workflow findings from 29 to 0.
2. Preserve legitimate diagnostics, monitoring, and recovery evidence.
3. Eliminate direct publication of Git refs from GitHub Actions workflows.
4. Remove automatically triggered repository/issue mutations from diagnostic workflows.
5. Preserve fail-closed behavior while still producing mandatory evidence on failures.
6. Keep production-changing workflows explicitly authorized and reviewable.
7. Avoid destructive Git commands, force-pushes, protection bypasses, and hidden failure suppression.

## Non-goals

- Do not weaken `scripts/maintenance/audit_active_workflows.py` merely to make CI green.
- Do not add path-based exceptions or debt allowlists.
- Do not convert risky operations into equivalent mutations through `gh api`, curl, or another hidden mechanism.
- Do not change Google Ads mutation policy; Google Ads APPLY remains closed while tracking health is `unknown`.
- Do not redesign unrelated business logic or production services.

## Current Findings

The current audit reports:

- 14 `automatic_write_workflow` findings.
- 6 `workflow_push` findings.
- 5 `set_plus_e` findings.
- 3 `continue_on_error` findings.
- 1 `production_push_trigger` finding.

The affected workflow set is:

- `.github/workflows/actions-run-index.yml`
- `.github/workflows/agent-vm-readonly-diagnostics.yml`
- `.github/workflows/apply-gsc-indexing-fix-20260824.yml`
- `.github/workflows/desktop-commander-24h-health.yml`
- `.github/workflows/desktop-commander-three-host-control-plane.yml`
- `.github/workflows/desktop-commander-three-host-quick-probe.yml`
- `.github/workflows/fred-win-terminal.yml`
- `.github/workflows/mei-email-graph-token-diagnostic.yml`
- `.github/workflows/mei-email-prod-probe-now.yml`
- `.github/workflows/mei-email-prod-probe-robust.yml`
- `.github/workflows/production-functional-audit.yml`
- `.github/workflows/seo-durable-code.yml`
- `.github/workflows/seo-durable-repair.yml`
- `.github/workflows/test-inventory.yml`
- `.github/workflows/vm-desktop-commander-connection-probe.yml`
- `.github/workflows/windows-private-peer-recovery.yml`

The six direct-ref publishers are `agent-vm-readonly-diagnostics`, `apply-gsc-indexing-fix-20260824`, `fred-win-terminal`, `mei-email-graph-token-diagnostic`, `seo-durable-code`, and `seo-durable-repair`.

The eight automatic issue writers are `actions-run-index`, the three Desktop Commander health/probe workflows, both MEI production probes, `vm-desktop-commander-connection-probe`, and `windows-private-peer-recovery`.

## Chosen Architecture

Use separation of observation from mutation.

Automatically triggered workflows may observe, validate, emit logs, upload mandatory artifacts, and fail. They must not write repository refs or mutate GitHub issues as part of the same automatic execution path.

Any remediation that changes repository content or production state must use an explicitly authorized path. Repository changes are prepared as reviewed branches/PRs outside the automatically triggered diagnostic workflow. Production-changing operations use `workflow_dispatch` or an equivalent explicit authorization boundary and retain least-privilege permissions.

This keeps the auditor strict and makes the workflow topology itself compliant rather than teaching the auditor to ignore known debt.

## Audit Correctness Preflight

Before changing the 16 workflows, protect the detector itself with a semantic regression test: a manual-only `workflow_dispatch` workflow that has `permissions: issues: write` and an issue command must not be classified as automatically triggered merely because the permission line contains `issues:`.

If that test is RED, fix automatic-trigger detection so it only recognizes events declared under the workflow `on:` stanza. This is a detector-correctness fix, not an exception to a policy rule. Rerun the full audit after the detector fix and use the resulting count as the authoritative migration baseline.

The currently observed 29 findings remain the historical baseline and all 16 listed workflows still have genuine unsafe or fail-open behavior that requires review.

## Remediation Rules

### 1. Direct Git publication

All six `git push` findings are removed from active workflows. A workflow may produce a patch, report, or suggested change as an artifact, but it may not publish a Git ref directly.

No replacement using `gh api`, raw REST ref mutation, or another semantically equivalent shortcut is allowed.

### 2. Automatically triggered write workflows

For automatic-write findings, scheduled/push/issues/workflow-run/repository-dispatch diagnostic paths become read-only.

If a workflow currently writes an issue solely to report health, replace that write with required artifacts plus a failing job when intervention is required. Workflow run status and required artifacts become the durable evidence channel. Any centralized status surface that depends on issue mutation is documented as intentionally retired rather than silently preserved through another write mechanism.

If a workflow performs a real remediation, split the remediation into an explicitly authorized manual path rather than retaining write permissions on the automatic diagnostic path.

### 3. Failure capture

Replace unsafe `set +e` blocks with the repository's recognized captured-status pattern:

1. temporarily disable fail-fast only for the audited command;
2. capture `$?` immediately;
3. restore `set -e` immediately;
4. generate mandatory evidence;
5. exit with exactly the captured status.

The existing audit already recognizes this narrow evidence-preserving pattern.

### 4. `continue-on-error`

Remove all three `continue-on-error: true` uses that hide a step failure. If evidence must be uploaded after a failed command, capture the status explicitly and rethrow it after evidence creation.

### 5. Production push trigger

Remove the `push` trigger from `production-functional-audit.yml` when it coexists with a production-like/manual execution surface. Keep production-impacting execution explicitly authorized. Read-only production observation, if needed automatically, belongs in a separate read-only workflow.

## Rollout Batches

### Batch A — repository publication boundary

Target the six workflows containing direct `git push`. Remove ref publication first because it is the highest-risk behavior and overlaps several automatic-write findings.

Acceptance: no `workflow_push` findings and no destructive Git regression.

### Batch B — automatic-write boundary

Convert the remaining automatic diagnostics/monitors to read-only execution. Where a file mixes diagnosis and remediation, split those concerns so the automatic side is read-only and the mutating side requires explicit authorization.

Acceptance: no `automatic_write_workflow` findings while existing diagnostic evidence remains available through workflow status and required artifacts.

### Batch C — failure semantics

Replace unsafe `set +e` and `continue-on-error` constructs with captured-status fail-closed flows.

Acceptance: no `set_plus_e` or `continue_on_error` findings; mandatory artifacts still exist on command failure.

### Batch D — production authorization

Remove the production `push` trigger and keep an explicit manual boundary for any production-impacting operation.

Acceptance: no `production_push_trigger` finding.

## Testing Strategy

Every batch follows RED -> GREEN TDD.

Before changing a workflow, add or extend regression tests that demonstrate the currently unsafe pattern. The tests must fail before the production change and pass after it.

Required validation for each batch:

- parse every changed YAML file;
- `python -m unittest tests.unit.test_audit_active_workflows -v`;
- targeted workflow regression tests for the changed files;
- resolve `BASE_SHA="$(git merge-base origin/main HEAD)"` and run `python scripts/maintenance/audit_automation_changes.py --base "$BASE_SHA" --head HEAD`;
- `python scripts/maintenance/audit_active_workflows.py`;
- `python scripts/maintenance/audit_secret_references.py`;
- `python scripts/audit-agents-real-work.py`;
- `git diff --check`;
- all repository-required PR checks.

The final acceptance run must show `blocking_finding_count: 0` from the repository-wide active-workflow audit on the exact candidate head.

## Deployment and Operational Safety

Workflow-only changes are integrated through reviewed PRs. No force-push or direct write to `main` is permitted.

After each merged batch, inspect the post-merge workflow runs. If a change touches a production pipeline or remote-control workflow, verify its intended read-only or manual behavior with evidence before continuing to the next batch.

Do not manually deploy application code as part of this governance cleanup unless a changed workflow is itself part of the canonical deploy pipeline and the protected pipeline performs the deployment.

## Success Criteria

The work is complete only when all of the following are simultaneously true on current `main`:

1. `audit_active_workflows.py` reports 0 blocking findings.
2. Secret-reference audit reports 0 active blocking findings.
3. Agent/deep-work audits report 0 blockers.
4. Required GitHub checks pass on the integrating PR and post-merge `main` run.
5. No active workflow contains direct Git ref publication.
6. Automatically triggered diagnostics are read-only and evidence-producing.
7. Failure paths remain fail-closed and observable.
8. Production-changing workflows require explicit authorization.
9. Google Ads remains read-only until tracking health satisfies the existing APPLY policy.
