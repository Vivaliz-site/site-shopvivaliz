# CI Performance Monitor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect GitHub Actions performance regressions automatically every 24 hours without consuming self-hosted runners or creating issue spam.

**Architecture:** A pure-stdlib Python analyzer consumes one paginated snapshot of recent workflow runs and produces JSON + Markdown metrics. A daily `ubuntu-latest` workflow fetches the last 24h once, runs the analyzer, publishes an artifact/step summary, and upserts a single regression issue only when thresholds are exceeded.

**Tech Stack:** GitHub Actions, GitHub REST via `gh api`, Python 3 stdlib, `actions/github-script@v7`, `actions/upload-artifact@v7`.

**Spec:** User-approved item #28 from the CI performance investigation.

## Global Constraints

- Daily monitor runs only on `ubuntu-latest`; never reserve a self-hosted production runner.
- One paginated workflow-runs API fetch per execution; no per-run polling or sleep loops.
- Window: 24 hours.
- Alert thresholds: average duration > 150 seconds or failure rate > 20%.
- Minimum sample for duration/failure thresholds: 3 terminal runs per workflow.
- Any `action_required` conclusion is independently alert-worthy because it indicates a workflow authorization regression.
- `cancelled` and `skipped` do not count as failures.
- Keep one open issue marked `<!-- ci-performance-monitor -->`; update it while degraded and close it after recovery.
- Artifact retention must be 1 day to match repository policy.
- No paid AI, no external SaaS, no production secrets.

## Review Focus

- Fewer than 3 samples: report metrics but do not alert on average duration/failure rate.
- Mixed success/cancelled/skipped: denominator must exclude cancelled/skipped.
- Missing timestamps: run remains countable for conclusion metrics but does not corrupt average duration.
- Pagination output from `gh api --paginate --slurp`: analyzer must flatten all pages safely.
- Existing monitor issue: degraded runs update it; healthy runs close it instead of creating duplicates.

---

### Task 1: Pure metrics analyzer

**Files:**
- Create: `scripts/ci_performance_monitor.py`
- Create: `tests/test_ci_performance_monitor.py`
- Modify: `scripts/repository-governance-validate.sh`

**Interfaces:**
- Consumes: JSON from GitHub Actions list-runs endpoint, either one response object or `--slurp` page array.
- Produces: report dict with `generated_at`, `window_hours`, `thresholds`, `workflows`, `regression`, and `alerts`; CLI writes JSON and Markdown.

- [ ] **Step 1: Write failing tests for healthy, slow, failure-rate, action_required, exclusions, missing timestamps, and paged input.**
- [ ] **Step 2: Add the test to Repository Governance and confirm RED because the analyzer does not exist.**
- [ ] **Step 3: Implement the minimal analyzer with stdlib only.**
- [ ] **Step 4: Confirm all analyzer tests pass in Repository Governance.**
- [ ] **Step 5: Commit.**

### Task 2: Daily hosted workflow and issue lifecycle

**Files:**
- Create: `.github/workflows/ci-performance-monitor.yml`
- Create: `tests/test_ci_performance_monitor_workflow.py`
- Modify: `scripts/repository-governance-validate.sh`

**Interfaces:**
- Consumes: analyzer CLI and GitHub token with `actions: read`, `issues: write`, `contents: read`.
- Produces: `artifacts/ci-performance-24h.json`, `artifacts/ci-performance-24h.md`, step summary, and at most one open regression issue.

- [ ] **Step 1: Write failing workflow contract test for daily schedule, ubuntu-latest only, one paginated fetch, 24h window, thresholds, artifact retention=1, and marker-based issue lifecycle.**
- [ ] **Step 2: Confirm RED because the workflow does not exist.**
- [ ] **Step 3: Implement the workflow with no loops/sleeps and a single issue upsert/close script.**
- [ ] **Step 4: Confirm workflow contract and analyzer suites are green.**
- [ ] **Step 5: Commit.**

### Task 3: Review and rollout

**Files:**
- Modify only if review finds a defect.

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces: mergeable PR and first manual monitor run evidence.

- [ ] **Step 1: Run repository governance and mandatory PR gates.**
- [ ] **Step 2: Review for false-alert/spam risk and required permissions.**
- [ ] **Step 3: Merge after green gates.**
- [ ] **Step 4: Trigger one manual monitor run and verify hosted runner, report artifact, summary, and issue behavior.**

## Execution Rulings

- Ruling: the no-per-run-API contract inspects the workflow-runs fetch step, not arbitrary actions/runs/<id> hyperlinks in issue evidence. A report link is not an API request; treating it as one creates a false positive while providing no N+1 protection.
