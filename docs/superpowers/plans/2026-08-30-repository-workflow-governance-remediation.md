# Repository Workflow Governance Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove every active-workflow governance blocker from the current repository while preserving read-only monitoring, mandatory evidence, and explicitly authorized repair paths.

**Architecture:** Separate observation from mutation. Automatic workflows remain read-only and evidence-producing; repository publication is removed from Actions; remote repair/terminal paths require `workflow_dispatch`; failure status is captured explicitly and rethrown when the workflow is meant to validate health.

**Tech Stack:** GitHub Actions YAML, Python 3.12 `unittest`, Bash, PHP regression scripts, Git/GitHub connector.

**Spec:** `docs/superpowers/specs/2026-08-30-repository-governance-workflow-debt-design.md`

## Global Constraints

- Do not weaken or allowlist findings in `audit_active_workflows.py` merely to make CI green.
- Do not use force-push, direct pushes to `main`, destructive Git cleanup, or hidden ref mutation through another API.
- Automatically triggered diagnostics must be read-only and produce mandatory evidence.
- Remote repair, terminal execution, and production-changing behavior require explicit manual authorization.
- Failure capture must remain observable and fail closed where the workflow is a validator.
- Preserve unrelated concurrent changes from current `main`.
- Google Ads APPLY remains closed while tracking health is `unknown`.

## Current implementation baseline

- Rebase target at plan creation: `8bcec45d6877829fb7e11f5890e25a1fe8fc3844`.
- `audit_active_workflows.py`: 208 workflows, 30 blockers, 16 affected files.
- Rules: 14 `automatic_write_workflow`, 6 `workflow_push`, 5 `set_plus_e`, 3 `continue_on_error`, 1 `production_push_trigger`, 1 `destructive_git`.
## File structure

- `scripts/maintenance/audit_active_workflows.py` — policy detector; only semantic trigger scoping changes are allowed.
- `tests/unit/test_audit_active_workflows.py` — unit coverage for trigger scoping and destructive Git detection.
- `tests/test_repository_workflow_governance_remediation.py` — repository-level contract for the 16 remediated workflows.
- Six publication workflows — remove direct Git publication and downgrade write permissions.
- Eight automatic diagnostic/recovery workflows — remove issue mutation; preserve evidence with artifacts; gate repair paths manually.
- `test-inventory.yml` — replace hidden failure suppression with explicit status handling.
- `production-functional-audit.yml` — remove the production push trigger.

---

### Task 1: Make trigger detection semantically correct

**Files:**
- Modify: `scripts/maintenance/audit_active_workflows.py`
- Modify: `tests/unit/test_audit_active_workflows.py`

**Interfaces:**
- Consumes: raw workflow YAML text.
- Produces: `workflow_trigger_block(text: str) -> str`, used by `audit_workflow()` for automatic and production trigger checks.

- [ ] **Step 1: Write failing semantic-trigger tests**

Add tests proving a manual-only workflow with `permissions: issues: write` is not mistaken for an `on: issues` trigger, while a real `on: issues` event remains blocked when it mutates issues. Also add a destructive-reset regression case.
```python
def test_manual_issue_permission_is_not_an_automatic_trigger(self):
    findings = self.audit("manual.yml", """name: Manual\non:\n  workflow_dispatch:\npermissions:\n  contents: read\n  issues: write\njobs:\n  report:\n    runs-on: ubuntu-latest\n    steps:\n      - run: gh issue comment 1 --body ok\n""")
    self.assertNotIn("automatic_write_workflow", {item.rule for item in findings})

def test_real_issue_trigger_with_write_is_automatic(self):
    findings = self.audit("event.yml", """name: Event\non:\n  issues:\n    types: [opened]\npermissions:\n  issues: write\njobs:\n  report:\n    runs-on: ubuntu-latest\n    steps:\n      - run: gh issue comment 1 --body ok\n""")
    self.assertIn("automatic_write_workflow", {item.rule for item in findings})

def test_blocks_destructive_reset(self):
    findings = self.audit("reset.yml", """name: Reset\non:\n  workflow_dispatch:\njobs:\n  x:\n    runs-on: ubuntu-latest\n    steps:\n      - run: git reset --hard origin/main\n""")
    self.assertIn("destructive_git", {item.rule for item in findings})
```

- [ ] **Step 2: Run unit tests and confirm the manual-permission case fails before implementation**

Run: `python3 -m unittest tests.unit.test_audit_active_workflows -v`
Expected: new manual-permission test FAILS because the existing regex scans the whole file.

- [ ] **Step 3: Scope event matching to the top-level `on:` block**
Use a top-level block extractor and run trigger regexes only on that text:

```python
TRIGGER_BLOCK = re.compile(r"(?ms)^on:\s*\n(?P<body>(?:^[ \t]+.*(?:\n|$))*)")

def workflow_trigger_block(text: str) -> str:
    match = TRIGGER_BLOCK.search(text)
    return match.group("body") if match else ""
```

Inside `audit_workflow()` set `triggers = workflow_trigger_block(text)`, then evaluate `AUTO_TRIGGER`, `PUSH_TRIGGER`, and `MANUAL_TRIGGER` against `triggers`, not the whole file.

- [ ] **Step 4: Run tests and audit baseline**

Run:
```bash
python3 -m unittest tests.unit.test_audit_active_workflows -v
python3 scripts/maintenance/audit_active_workflows.py > /tmp/governance-after-detector.json || true
```
Expected: unit tests PASS; the repository audit still reports the real workflow debt (no blocker disappears merely because a permission key moved out of trigger scope).

- [ ] **Step 5: Commit**

```bash
git add scripts/maintenance/audit_active_workflows.py tests/unit/test_audit_active_workflows.py
git commit -m "test(governance): scope workflow trigger detection"
```

---

### Task 2: Remove direct repository publication and destructive checkout mutation
**Files:**
- Create: `tests/test_repository_workflow_governance_remediation.py`
- Modify: `.github/workflows/agent-vm-readonly-diagnostics.yml`
- Modify: `.github/workflows/apply-gsc-indexing-fix-20260824.yml`
- Modify: `.github/workflows/fred-win-terminal.yml`
- Modify: `.github/workflows/mei-email-graph-token-diagnostic.yml`
- Modify: `.github/workflows/seo-durable-code.yml`
- Modify: `.github/workflows/seo-durable-repair.yml`

**Interfaces:**
- Consumes: the six legacy publication workflows.
- Produces: manual/read-only workflow definitions with no `git push`, no `git reset --hard`, and no `contents: write`.

- [ ] **Step 1: Add a failing publication-boundary regression test**

```python
from pathlib import Path
import re, unittest

ROOT = Path(__file__).resolve().parents[1]
PUBLISH = (
    "agent-vm-readonly-diagnostics.yml", "apply-gsc-indexing-fix-20260824.yml",
    "fred-win-terminal.yml", "mei-email-graph-token-diagnostic.yml",
    "seo-durable-code.yml", "seo-durable-repair.yml",
)

class RepositoryWorkflowGovernanceRemediationTests(unittest.TestCase):
    def text(self, name):
        return (ROOT / ".github" / "workflows" / name).read_text(encoding="utf-8-sig")

    def test_publication_workflows_never_publish_or_destructively_reset(self):
        for name in PUBLISH:
            text = self.text(name)
            self.assertNotRegex(text, r"\bgit\s+push\b", name)
            self.assertNotRegex(text, r"\bgit\s+reset\s+--hard\b", name)
            self.assertNotRegex(text, r"(?m)^\s{2}contents:\s*write\s*$", name)
```

Run: `python3 -m unittest tests.test_repository_workflow_governance_remediation -v`
Expected: FAIL on all six legacy workflows, and on the destructive reset in `agent-vm-readonly-diagnostics.yml`.
- [ ] **Step 2: Make the six workflows non-publishing**

Apply these exact behavior changes:

1. `agent-vm-readonly-diagnostics.yml`: remove the `issues` trigger; keep only `workflow_dispatch`; change `contents: write` to `contents: read`; remove checkout token override; replace the commit/push step with a patch-preview artifact. In the remote script replace `git reset --hard origin/main` with a fail-closed file hash guard:

```bash
git fetch origin main
expected_checkout=$(git rev-parse origin/main:checkout.php)
actual_checkout=$(git hash-object checkout.php)
if [ "$actual_checkout" != "$expected_checkout" ]; then
  echo "CHECKOUT_BASE_MISMATCH"
  exit 20
fi
```

2. `apply-gsc-indexing-fix-20260824.yml`: make it `workflow_dispatch` only; set `contents: read`; keep apply/test in the ephemeral runner; replace commit/push with `git diff --binary > artifacts/gsc-indexing-fix.patch` and upload it with `if-no-files-found: error`.
3. `fred-win-terminal.yml`: make it `workflow_dispatch` only; set `contents: read`; keep terminal execution; replace repository report commit/push with upload of `reports/fredwin-terminal-latest.txt`.
4. `mei-email-graph-token-diagnostic.yml`: make it `workflow_dispatch` only; set `contents: read`; replace report commit/push with artifact upload.
5. `seo-durable-code.yml`: make it `workflow_dispatch` only; set `contents: read`; replace commit/push with `git diff --binary > artifacts/seo-durable-code.patch` and required artifact upload.
6. `seo-durable-repair.yml`: same manual/read-only preview pattern, writing `artifacts/seo-durable-repair.patch`.

Artifact steps use:

```yaml
- name: Upload remediation preview
  uses: actions/upload-artifact@v4
  with:
    name: remediation-preview-${{ github.run_id }}-${{ github.run_attempt }}
    path: artifacts/
    if-no-files-found: error
```

- [ ] **Step 3: Run publication regression and active-workflow audit**

Expected after Task 2: no `workflow_push` or `destructive_git` findings; automatic-write findings for these six also disappear because the workflows are manual/read-only.
- [ ] **Step 4: Commit**

```bash
git add tests/test_repository_workflow_governance_remediation.py .github/workflows/agent-vm-readonly-diagnostics.yml .github/workflows/apply-gsc-indexing-fix-20260824.yml .github/workflows/fred-win-terminal.yml .github/workflows/mei-email-graph-token-diagnostic.yml .github/workflows/seo-durable-code.yml .github/workflows/seo-durable-repair.yml
git commit -m "fix(governance): remove workflow ref publication"
```

---

### Task 3: Convert automatic issue writers into artifact-only diagnostics

**Files:**
- Modify: `.github/workflows/actions-run-index.yml`
- Modify: `.github/workflows/desktop-commander-24h-health.yml`
- Modify: `.github/workflows/desktop-commander-three-host-control-plane.yml`
- Modify: `.github/workflows/desktop-commander-three-host-quick-probe.yml`
- Modify: `.github/workflows/mei-email-prod-probe-now.yml`
- Modify: `.github/workflows/mei-email-prod-probe-robust.yml`
- Modify: `.github/workflows/vm-desktop-commander-connection-probe.yml`
- Modify: `.github/workflows/windows-private-peer-recovery.yml`
- Extend: `tests/test_repository_workflow_governance_remediation.py`

**Interfaces:**
- Consumes: sanitized markdown/text already generated by each workflow.
- Produces: immutable GitHub Actions artifacts; no automatic issue edit/comment/create.

- [ ] **Step 1: Add a failing artifact-boundary test**

```python
AUTO_EVIDENCE = (
    "actions-run-index.yml", "desktop-commander-24h-health.yml",
    "desktop-commander-three-host-control-plane.yml", "desktop-commander-three-host-quick-probe.yml",
    "mei-email-prod-probe-now.yml", "mei-email-prod-probe-robust.yml",
    "vm-desktop-commander-connection-probe.yml", "windows-private-peer-recovery.yml",
)

def test_automatic_diagnostics_do_not_mutate_issues_and_require_artifacts(self):
    for name in AUTO_EVIDENCE:
        text = self.text(name)
        self.assertNotRegex(text, r"(?m)^\s{2}issues:\s*write\s*$", name)
        self.assertNotRegex(text, r"\bgh\s+issue\s+(?:create|edit|comment)\b", name)
        self.assertIn("actions/upload-artifact@v4", text, name)
        self.assertRegex(text, r"if-no-files-found:\s*error", name)
```

Run it before edits; expect FAIL.
- [ ] **Step 2: Replace issue publication with immutable evidence**

For each workflow, change `issues: write` to no issue permission (or `issues: read` only when actual reads exist), keep `contents: read`, materialize the existing sanitized output under `artifacts/<workflow>/`, and upload it with `if: always()` plus `if-no-files-found: error` before cleanup.

Evidence mapping:
- `actions-run-index.yml` → JSON plus sanitized diagnostic markdown.
- `desktop-commander-24h-health.yml` → `/tmp/dc-control-plane-status.json` and `.md`.
- `desktop-commander-three-host-control-plane.yml` → `/tmp/dc-status.json` and `.md`.
- `desktop-commander-three-host-quick-probe.yml` → `/tmp/quick-probe.md`.
- `mei-email-prod-probe-now.yml` → sanitized probe body.
- `mei-email-prod-probe-robust.yml` → sanitized robust probe body.
- `vm-desktop-commander-connection-probe.yml` → sanitized connection probe markdown.
- `windows-private-peer-recovery.yml` → sanitized recovery evidence markdown.

Use a workspace copy step before upload, for example:

```yaml
- name: Stage sanitized evidence
  if: always()
  run: |
    set -Eeuo pipefail
    mkdir -p artifacts/workflow-evidence
    test -f /tmp/status.md
    cp /tmp/status.md artifacts/workflow-evidence/status.md
- name: Upload immutable evidence
  if: always()
  uses: actions/upload-artifact@v4
  with:
    name: workflow-evidence-${{ github.run_id }}-${{ github.run_attempt }}
    path: artifacts/workflow-evidence/
    if-no-files-found: error
```

- [ ] **Step 3: Gate real repair paths explicitly**

`desktop-commander-24h-health.yml`: keep schedule/push/PR observation, but pass `ALLOW_REPAIR=1` only on `workflow_dispatch`. Automatic events must set `ALLOW_REPAIR=0`, and Python repair code must require that flag before running supervisor/bootstrap commands.

`windows-private-peer-recovery.yml`: remove its `push` trigger; keep only `workflow_dispatch`, because it installs/repairs Windows peer services.

- [ ] **Step 4: Run regression and audit**

Expected: all `automatic_write_workflow` findings are gone; health checks still generate required evidence; automatic Desktop Commander runs cannot repair hosts.
- [ ] **Step 5: Commit**

```bash
git add .github/workflows/actions-run-index.yml .github/workflows/desktop-commander-24h-health.yml .github/workflows/desktop-commander-three-host-control-plane.yml .github/workflows/desktop-commander-three-host-quick-probe.yml .github/workflows/mei-email-prod-probe-now.yml .github/workflows/mei-email-prod-probe-robust.yml .github/workflows/vm-desktop-commander-connection-probe.yml .github/workflows/windows-private-peer-recovery.yml tests/test_repository_workflow_governance_remediation.py
git commit -m "fix(governance): make automatic diagnostics read only"
```

---

### Task 4: Remove hidden failure suppression

**Files:**
- Modify: `.github/workflows/fred-win-terminal.yml`
- Modify: `.github/workflows/mei-email-prod-probe-now.yml`
- Modify: `.github/workflows/mei-email-prod-probe-robust.yml`
- Modify: `.github/workflows/test-inventory.yml`
- Extend: `tests/test_repository_workflow_governance_remediation.py`

**Interfaces:**
- Consumes: command exit status.
- Produces: explicit status values, evidence, and final exit status without `continue-on-error: true` or unscoped `set +e`.

- [ ] **Step 1: Add a failing failure-semantics test**

```python
FAILURE_FILES = (
    "fred-win-terminal.yml", "mei-email-prod-probe-now.yml",
    "mei-email-prod-probe-robust.yml", "test-inventory.yml",
)

def test_failure_paths_do_not_hide_errors(self):
    for name in FAILURE_FILES:
        text = self.text(name)
        self.assertNotRegex(text, r"continue-on-error\s*:\s*true", name)
        self.assertNotRegex(text, r"(?m)^\s*set\s+\+e\s*$", name)
```

Run before edits; expect FAIL.
- [ ] **Step 2: Replace failure suppression with explicit branching**

For commands whose status must be observed without aborting before evidence is written, use shell conditional capture rather than disabling fail-fast:

```bash
rc=0
if ssh -o BatchMode=yes -i "$HOME/.ssh/id_rsa" "${VM_USER}@${VM_HOST}" 'systemctl is-active mei-mg-email-worker.service'; then
  rc=0
else
  rc=$?
fi
printf 'PROBE_RC=%s\n' "$rc" > artifacts/mei-email/probe-status.txt
exit "$rc"
```

Specific changes:
- `fred-win-terminal.yml`: execute SSH in an `if` block, capture `terminal_rc`, always write `reports/fredwin-terminal-latest.txt`, upload it, then let the existing enforcement step fail when `terminal_rc != 0`.
- `mei-email-prod-probe-now.yml`: replace both `set +e` blocks with `if` assignments/pipelines; service inactivity is represented as data, while an unexpected probe failure remains non-zero.
- `mei-email-prod-probe-robust.yml`: remove step-level `continue-on-error`; capture SSH collection status inside the step, produce the sanitized artifact, and add a final enforcement step that exits with the captured status.
- `test-inventory.yml`: remove job-level `continue-on-error`; run each PHP test inside `if php "$file"; then ... else ... fi`; run pytest inside an `if`; write summaries/artifacts; exit non-zero when failures exist so the inventory is observable instead of silently green.

- [ ] **Step 3: Run targeted tests and audit**

Run:
```bash
python3 -m unittest tests.test_repository_workflow_governance_remediation -v
python3 scripts/maintenance/audit_active_workflows.py
```
Expected: no `set_plus_e` or `continue_on_error` findings.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/fred-win-terminal.yml .github/workflows/mei-email-prod-probe-now.yml .github/workflows/mei-email-prod-probe-robust.yml .github/workflows/test-inventory.yml tests/test_repository_workflow_governance_remediation.py
git commit -m "fix(governance): make workflow failures explicit"
```

---

### Task 5: Enforce the production authorization boundary
**Files:**
- Modify: `.github/workflows/production-functional-audit.yml`
- Extend: `tests/test_repository_workflow_governance_remediation.py`

- [ ] **Step 1: Add a failing trigger contract**

```python
def test_production_functional_audit_has_no_push_trigger(self):
    text = self.text("production-functional-audit.yml")
    match = re.search(r"(?ms)^on:\s*\n(?P<body>(?:^[ \t]+.*(?:\n|$))*)", text)
    self.assertIsNotNone(match)
    self.assertNotRegex(match.group("body"), r"(?m)^\s{2}push\s*:")
```

- [ ] **Step 2: Remove only the `push` stanza**

Keep `workflow_dispatch` and the hourly `schedule` because this workflow is a read-only functional audit. Do not add write permissions or mutation steps.

- [ ] **Step 3: Run trigger regression and global audit**

Expected: `production_push_trigger` disappears and `audit_active_workflows.py` reaches `blocking_finding_count: 0`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/production-functional-audit.yml tests/test_repository_workflow_governance_remediation.py
git commit -m "fix(governance): remove production push trigger"
```

---

### Task 6: Full verification, integration, and post-merge proof

**Files:**
- Verify all changed workflows and governance tests.
- Add the approved spec and this plan to the branch.

- [ ] **Step 1: Parse every changed workflow YAML**

Use Python with `yaml.BaseLoader` so the YAML 1.1 `on` key is not coerced to boolean:

```bash
python3 - <<'PY'
from pathlib import Path
import yaml
for p in Path('.github/workflows').glob('*.yml'):
    yaml.load(p.read_text(encoding='utf-8-sig'), Loader=yaml.BaseLoader)
print('YAML_PARSE_OK')
PY
```

Expected: `YAML_PARSE_OK`.
- [ ] **Step 2: Run all governance gates**

```bash
python3 -m unittest tests.unit.test_audit_active_workflows tests.test_repository_workflow_governance_remediation -v
python3 scripts/maintenance/audit_active_workflows.py
python3 scripts/maintenance/audit_secret_references.py
python3 scripts/audit-agents-real-work.py
base=$(git merge-base origin/main HEAD)
python3 scripts/maintenance/audit_automation_changes.py --base "$base" --head HEAD
git diff --check "$base"...HEAD
```

Expected: every command exits 0; active-workflow audit prints zero blockers.

- [ ] **Step 3: Run affected workflow contract tests and Google Ads safety tests**

Locate current canonical Google Ads tests with `find tests -type f -iname '*google*ads*'`; run the Python tests that exist on the current base plus the existing Google Ads PHP attribution/CRO contract. Run Desktop Commander contract tests and production functional audit contract tests because their workflows changed.

Expected: all selected tests PASS. Do not claim the historical 43-test count if the current branch no longer contains that suite; report the exact current tests executed.

- [ ] **Step 4: Commit documentation and any final test-only adjustments**

```bash
git add docs/superpowers/specs/2026-08-30-repository-governance-workflow-debt-design.md docs/superpowers/plans/2026-08-30-repository-workflow-governance-remediation.md
git commit -m "docs(governance): record workflow remediation design and plan"
```

- [ ] **Step 5: Synchronize with current `main` before publication**

Fetch `main`; if it advanced, inspect the delta, rebase/fast-forward the feature branch without force, rerun all verification, and refuse to overwrite concurrent edits.

- [ ] **Step 6: Publish through GitHub connector and open one short-lived PR**

Use connector writes because VM HTTPS push credentials are not assumed. The PR must include the exact candidate SHA and audit evidence. Do not enable auto-merge.

- [ ] **Step 7: Require all repository checks to complete successfully**

Inspect every PR workflow result. Treat `action_required`, skipped jobs that should run, or missing required gates as non-green.

- [ ] **Step 8: Squash merge with `expected_head_sha`**

Merge only the verified head SHA. Never force, bypass, or direct-push `main`.

- [ ] **Step 9: Verify post-merge `main`**

On the exact merged SHA rerun `audit_active_workflows.py` and confirm zero blockers. Inspect Repository Governance on the push event and require genuine success.

- [ ] **Step 10: Verify deployment and application safety**

Confirm the canonical production release SHA, public HTTPS smoke routes, and Google Ads read-only audit. Google Ads must remain `partial=false` where supported and APPLY must remain closed while tracking is `unknown`.
