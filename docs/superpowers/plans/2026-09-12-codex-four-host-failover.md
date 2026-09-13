# Codex Four-Host Credential Failover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Codex CLI transparently fail over between the two approved OpenAI API credentials on all four ShopVivaLiz hosts.

**Architecture:** Keep secrets only in per-host protected `~/.codex/api-keys.env` files. Extend the existing `scripts/codex-failover.py` classification logic, then install a host-local launcher shim that preserves the real Codex executable and routes finite invocations through the failover engine.

**Tech Stack:** Python 3 standard library, Codex CLI, Bash on Linux, PowerShell/cmd on Windows, Git/GitHub.

**Spec:** `docs/superpowers/specs/2026-09-12-codex-four-host-failover-design.md`

## Global Constraints

- Never commit, log, echo, or include either API key in process arguments.
- No daemon, cron, polling loop, or recurring paid-AI task.
- Preserve current launcher and credential files before changing a host.
- Linux VMs are primary; Windows hosts remain optional fallback capacity.
- Each chat retains its isolated CLI namespace.
- Finish through branch, tests, push, PR, merge, post-merge validation, and no pending task PR.

---

### Task 1: Failover classification regression tests

**Files:**
- Create: `tests/test_codex_failover.py`
- Modify later: `scripts/codex-failover.py`
**Interfaces:**
- Consumes: `http_response_usable(status: int, body: str) -> bool` and `run_with_failover(...)`.
- Produces: regression coverage for billing/quota/rate-limit classification and switching.

- [ ] **Step 1: Write the failing test**

```python
import importlib.util
import pathlib
import unittest

SCRIPT = pathlib.Path(__file__).parents[1] / "scripts" / "codex-failover.py"
spec = importlib.util.spec_from_file_location("codex_failover", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class CodexFailoverTests(unittest.TestCase):
    def test_unusable_http_statuses_trigger_failover(self):
        for status, body in [
            (401, ""), (403, ""), (402, ""),
            (429, '{"error":{"code":"rate_limit_exceeded"}}'),
            (429, '{"error":{"code":"usage_limit_reached"}}'),
            (429, '{"error":{"code":"insufficient_quota"}}'),
        ]:
            with self.subTest(status=status, body=body):
                self.assertFalse(mod.http_response_usable(status, body))
```

- [ ] **Step 2: Run `python3 -m unittest tests.test_codex_failover -v` and verify failure on HTTP 402/429 cases not handled today.**
- [ ] **Step 3: Add the switching test**

```python
    def test_failed_primary_switches_to_secondary_when_primary_becomes_unusable(self):
        checks = {"p": [True, False], "s": [True]}
        calls = []
        def checker(key):
            return checks[key].pop(0)
        def runner(key):
            calls.append(key)
            return 7 if key == "p" else 0
        rc, active = mod.run_with_failover("p", "s", "primary", checker, runner)
        self.assertEqual((rc, active), (0, "secondary"))
        self.assertEqual(calls, ["p", "s"])
```

- [ ] **Step 4: Re-run unittest and verify only the HTTP classification test remains red.**
- [ ] **Step 5: Change `http_response_usable` minimally so HTTP 401, 402, 403 and 429 return `False`; preserve network errors as non-disqualifying in `preflight`.**
- [ ] **Step 6: Run `python3 -m unittest tests.test_codex_failover -v` and `python3 -m py_compile scripts/codex-failover.py`; both must pass.**

### Task 2: Document and package the host-local launcher contract

**Files:**
- Modify: `scripts/codex-failover.py` only if recursion protection needs a code-level guard.
- Modify: `docs/superpowers/specs/2026-09-12-codex-four-host-failover-design.md` only for findings discovered during implementation.

**Interfaces:**
- `CODEX_REAL` points to the preserved original Codex executable.
- `~/.codex/api-keys.env` may contain the original Gmail attachment names `openai_token_1` and `openai_token_2`, or the backward-compatible canonical key names.
- `~/.codex/api-key-active` contains only `primary` or `secondary`.
- [ ] **Step 1: Audit real launcher paths on all four hosts without changing them.**
- [ ] **Step 2: For each host, create a timestamped backup of the existing launcher/config and any `api-keys.env`; do not print contents.**
- [ ] **Step 3: Install the merged failover script as a host-local protected copy under `~/.codex/`.**
- [ ] **Step 4: Preserve the real Codex launcher as `codex-real`/equivalent and create a transparent `codex` shim that exports/sets `CODEX_REAL` before invoking the failover script.**
- [ ] **Step 5: Confirm `codex --version` resolves through the shim and cannot recursively call itself.**

### Task 3: Securely install the approved credential pair on four hosts

**Files outside Git:**
- Linux: `~/.codex/api-keys.env`, `~/.codex/api-key-active`.
- Windows: `%USERPROFILE%\.codex\api-keys.env`, `%USERPROFILE%\.codex\api-key-active`.

- [ ] **Step 1: Obtain the two values only from the approved Gmail attachment, without copying literals into this plan or a terminal command.**
- [ ] **Step 2: Write them as `OPENAI_API_KEY_PRIMARY` and `OPENAI_API_KEY_SECONDARY` using a protected file-transfer/write mechanism.**
- [ ] **Step 3: Set POSIX mode `0600`; on Windows restrict ACLs to the owner and SYSTEM where supported.**
- [ ] **Step 4: Compute SHA-256 locally on each host and compare only hashes with the approved source hashes; never display key prefixes/suffixes.**
- [ ] **Step 5: Initialize the active label to `primary` only after both hashes match.**

### Task 4: Four-host functional validation

- [ ] **Step 1: Run `codex --version` on each host through the wrapper.**
- [ ] **Step 2: Run `python .../codex-failover.py --check` or the equivalent non-secret status check; output may contain only `primary=usable|unavailable` and `secondary=usable|unavailable`.**
- [ ] **Step 3: Validate controlled failover with injected test credentials/checkers, never by corrupting the approved stored values.**
- [ ] **Step 4: Confirm `api-key-active` changes to `secondary` only after a proven primary-unusable case and can later return to `primary`.**
### Task 5: Merge and post-merge verification

- [ ] **Step 1: Run `git diff --check`, the focused unittest, Python compile check, and the repository policy/doc tests relevant to agent governance.**
- [ ] **Step 2: Review `git diff` for secret literals; the two API key values and their hashes must not appear in versioned files.**
- [ ] **Step 3: Commit the spec, plan, regression test, and minimal failover code change on `agent/codex-four-host-failover`.**
- [ ] **Step 4: Push the branch and open a PR to `main`; wait for required checks and correct any failures.**
- [ ] **Step 5: Merge the PR, verify `main` contains the merged files, and confirm no task PR remains open.**
- [ ] **Step 6: Re-copy the failover script from the merged `main` SHA to the four host-local locations if the merged content differs from the pre-merge candidate.**
- [ ] **Step 7: Re-run the four-host wrapper/hash/status checks and record only non-secret evidence: host, CLI version, wrapper status, primary/secondary usability, active label, and permission result.**

## Expected final evidence

- Branch/base SHA and merge SHA.
- Focused regression test output and compile check.
- Required GitHub checks green and PR merged.
- Four independent host validations with no secret output.
- `git status --porcelain` empty in the worktree.
- No open/draft PR for this task.