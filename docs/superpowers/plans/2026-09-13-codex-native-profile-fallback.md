# Codex Native ChatGPT Profile Failover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every ShopVivaLiz Codex invocation use bounded automatic failover between the already-authenticated native ChatGPT profiles `fredmourao` and `marinaofaleiro` on all four execution hosts.

**Architecture:** Add one cross-platform Python selector that chooses a `CODEX_HOME`, classifies only native auth/capacity failures as failover-worthy, and attempts each profile at most once. A deterministic installer creates host-local Linux/Windows launchers, preserves the existing Windows Git-scope guard, and makes native ChatGPT profile selection the default Codex path without invoking the older API-key fallback implicitly.

**Tech Stack:** Python 3 standard library, Codex CLI, Bash, PowerShell, `unittest`, Git/GitHub.

**Spec:** `docs/superpowers/specs/2026-09-13-codex-native-profile-fallback-design.md`

## Global Constraints

- Profile order is exactly `fredmourao` then `marinaofaleiro`; each profile lives under `<home>/.codex-business/<profile>`.
- Auth material remains local; never read or emit raw `auth.json`, ChatGPT tokens, device codes, cookies, passwords, or API keys.
- Native ChatGPT profile failover is primary. `scripts/codex-failover.py` remains separate and is never called implicitly.
- One explicit invocation may attempt each profile at most once; no daemon, cron, watcher, scheduled task, or unbounded retry loop.
- Network/DNS/time-out failures, ordinary command errors, Git failures, generated-task failures, and user cancellation do not switch profiles unless a native auth/capacity signature is also present.
- Interactive availability probes are `codex exec --ephemeral`, bounded to 45 seconds, read-only, non-persistent, and run only when starting an explicit interactive invocation.
- The selector never invokes `codex login`, device authorization, or credential collection automatically; unavailable profiles fail once with non-secret diagnostics.
- Preserve `CHAT_CLI_SESSION_ID` isolation, existing authenticated profile directories, and the Windows `ai-cli-scope-guard.ps1` Git-scope checks.
- All host launcher changes require timestamped local backup and rollback without deleting either authenticated `CODEX_HOME`.

---

### Task 1: Canonical selector, classifier, and state

**Files:**
- Create: `scripts/codex-native-profile-failover.py`
- Create: `tests/test_codex_native_profile_failover.py`

**Interfaces:**
- Produces: `classify_failure(returncode, stderr, stdout='') -> str`, `ordered_profiles(preferred) -> tuple[str, str]`, `read_state(path) -> dict`, `write_state(path, preferred, result_class, attempts) -> None`, and `command_mode(argv) -> str`.

- [ ] **Step 1: Write failing tests for classification and ordering.**

```python
class NativeProfileFailoverTests(unittest.TestCase):
    def test_capacity_and_auth_failures_are_retryable(self):
        for text in (
            'usage limit reached', 'rate limit exceeded', 'quota exhausted',
            'too many requests', 'authentication required', 'unauthorized',
            'token expired', 'refresh token failed', 'not logged in',
        ):
            self.assertEqual(mod.classify_failure(1, text), 'failover')

    def test_generic_network_cancel_and_task_failures_do_not_switch(self):
        self.assertEqual(mod.classify_failure(1, 'connection timed out'), 'terminal')
        self.assertEqual(mod.classify_failure(1, 'tests failed'), 'terminal')
        self.assertEqual(mod.classify_failure(130, 'usage limit reached'), 'cancelled')

    def test_ordered_profiles_respects_preferred(self):
        self.assertEqual(mod.ordered_profiles('fredmourao'), ('fredmourao', 'marinaofaleiro'))
        self.assertEqual(mod.ordered_profiles('marinaofaleiro'), ('marinaofaleiro', 'fredmourao'))
```

- [ ] **Step 2: Run `python3 -m unittest tests.test_codex_native_profile_failover -v` and verify it fails because the module/functions do not exist.**
- [ ] **Step 3: Implement constants and deterministic classification.**

```python
PROFILES = ('fredmourao', 'marinaofaleiro')
FAILOVER_PATTERNS = (
    'usage limit', 'rate limit', 'quota', 'too many requests',
    'authentication required', 'unauthorized', 'token expired',
    'refresh token', 'not logged in', 'limit reached',
)
CANCEL_CODES = {130, -2}

def classify_failure(returncode: int, stderr: str, stdout: str = '') -> str:
    if returncode == 0:
        return 'success'
    if returncode in CANCEL_CODES:
        return 'cancelled'
    text = (stderr or stdout[-4096:]).lower()
    return 'failover' if any(p in text for p in FAILOVER_PATTERNS) else 'terminal'
```

- [ ] **Step 4: Add atomic JSON state under `~/.codex-business/failover-state.json`; only profile label, UTC timestamp, result class, and attempt count may be stored.**
- [ ] **Step 5: Add command parsing with three modes: `model` for `exec`, `e`, `review`; `admin` for known non-model subcommands such as `login`, `doctor`, `mcp`, `plugin`, `completion`, `features`, `update`; otherwise `interactive`. Add tests proving admin commands do not trigger model probes and persisted state contains only the allowed non-secret fields.**
- [ ] **Step 6: Run focused unittest and `python3 -m py_compile scripts/codex-native-profile-failover.py`; both must pass.**
- [ ] **Step 7: Commit with `git commit -m "feat(codex): add native profile selector core"`.**

### Task 2: Bounded non-interactive retry and interactive probe

**Files:**
- Modify: `scripts/codex-native-profile-failover.py`
- Modify: `tests/test_codex_native_profile_failover.py`

**Interfaces:**
- Produces: `AttemptResult`, `run_noninteractive(...) -> tuple[int, str]`, `probe_profile(...) -> AttemptResult`, and `run_interactive(...) -> tuple[int, str]`; CLI `main(argv=None) -> int` returns only the final process code.

- [ ] **Step 1: Add failing tests using an injected fake runner.**

```python
def test_primary_exhausted_secondary_succeeds(self):
    runner = FakeRunner([
        mod.AttemptResult('fredmourao', 1, '', 'usage limit reached'),
        mod.AttemptResult('marinaofaleiro', 0, 'ok\n', ''),
    ])
    rc, selected = mod.run_noninteractive(['exec', 'work'], 'fredmourao', runner)
    self.assertEqual((rc, selected), (0, 'marinaofaleiro'))
    self.assertEqual(runner.profiles, ['fredmourao', 'marinaofaleiro'])

def test_generic_error_never_retries(self):
    runner = FakeRunner([mod.AttemptResult('fredmourao', 1, '', 'tests failed')])
    rc, selected = mod.run_noninteractive(['exec', 'work'], 'fredmourao', runner)
    self.assertEqual((rc, selected), (1, 'fredmourao'))
    self.assertEqual(runner.profiles, ['fredmourao'])
```

- [ ] **Step 2: Run the two new tests and verify they fail before implementation.**
- [ ] **Step 3: Implement `AttemptResult` and subprocess execution with `CODEX_HOME=<home>/.codex-business/<profile>`; capture output only for classification and replay only the final attempt to the caller.**
- [ ] **Step 4: Enforce exactly one attempt per profile per invocation; update preferred state only after the alternate succeeds. A later failover-worthy failure on `marinaofaleiro` must make `fredmourao` eligible again and persist Fred only after Fred succeeds.**
- [ ] **Step 5: Add interactive probe command exactly as a finite child process:**

```python
PROBE_ARGS = [
    'exec', '--ephemeral', '--skip-git-repo-check', '--ignore-user-config',
    '--ignore-rules', '--sandbox', 'read-only', '--ask-for-approval', 'never',
    'Reply exactly PROFILE_OK and do not use tools.',
]
PROBE_TIMEOUT_SECONDS = 45
```

- [ ] **Step 6: Test interactive primary selection, secondary selection after a recognized failure, secondary-to-primary recovery on a later invocation, both unavailable with exactly one attempt per profile, timeout/network failure with no switch, cancellation with no switch, and proof that no path invokes `codex login`.**
- [ ] **Step 7: Run all selector tests and compile check; commit with `git commit -m "feat(codex): add bounded native profile failover"`.**

### Task 3: Deterministic cross-platform installer and launchers

**Files:**
- Create: `scripts/install-codex-native-profile-failover.py`
- Create: `tests/test_install_codex_native_profile_failover.py`

**Interfaces:**
- Produces: `render_linux_launcher(real_path) -> str`, `render_windows_launcher(real_path) -> str`, `patch_windows_scope_guard(text, launcher_path) -> str`, and `install(home, platform, real_codex, source_engine, scope_guard=None) -> dict`.

- [ ] **Step 1: Write failing installer tests against a temporary fake home.**
- [ ] **Step 2: Test that Linux output creates `codex`, `codex-auto`, `codex-fred`, and `codex-marina`, where manual launchers call the real executable directly and automatic launchers call the canonical engine.**
- [ ] **Step 3: Test that Windows output creates `codex-auto.ps1`, `codex-fred.ps1`, and `codex-marina.ps1`, and patches `ai-cli-scope-guard.ps1` inside unique sentinel comments without changing Claude or Git-scope logic.**
- [ ] **Step 4: Test idempotency: running `install(...)` twice produces identical active files and a second run does not duplicate the Windows sentinel block.**
- [ ] **Step 5: Implement timestamped backup before any launcher/guard replacement. Backups contain launcher/guard files only; authenticated profile directories are never copied, deleted, or rewritten.**
- [ ] **Step 6: Persist the resolved real executable path under `~/.codex-business/codex-real-path`; native launchers must never resolve through `scripts/codex-failover.py` or an already-wrapped `codex` path.**
- [ ] **Step 7: Make installation fail closed if either profile directory or the real Codex executable is missing.**
- [ ] **Step 8: Run installer tests, selector tests, and compile checks; commit with `git commit -m "feat(codex): install native failover launchers"`.**

### Task 4: Staged four-host validation before changing defaults

**Files outside Git:**
- Linux staging: `~/.codex-fallback/staging-<commit>/`
- Windows staging: `%USERPROFILE%\.codex-fallback\staging-<commit>\`

- [ ] **Step 1: On each host record host name, Codex version, current default launcher path, both profile `login status` results, and current launcher hash. Never read `auth.json`.**
- [ ] **Step 2: Copy the candidate engine and installer into a staging directory without changing the active launcher.**
- [ ] **Step 3: Run the selector unit tests locally on each host where Python is available.**
- [ ] **Step 4: Run a synthetic fake-Codex test that proves `fredmourao -> marinaofaleiro` and `marinaofaleiro -> fredmourao` without using the real accounts.**
- [ ] **Step 5: Run exactly one finite real native ChatGPT smoke on each host through the staged engine, using `exec --ephemeral --skip-git-repo-check --sandbox read-only --ask-for-approval never` with a tiny non-tool prompt. Do not retry the real smoke automatically.**
- [ ] **Step 6: Confirm no probe/login/Codex child process remains after each smoke. If any host fails, leave its active launcher untouched and record the exact failure.**

### Task 5: Repository validation, PR, and merge

- [ ] **Step 1: Reconcile `origin/main` by normal fetch plus non-destructive merge/replay only if it advanced; never use hard reset or force-push, and preserve unrelated concurrent work.**
- [ ] **Step 2: Run `git diff --check`, `python3 -m unittest tests.test_codex_native_profile_failover tests.test_install_codex_native_profile_failover -v`, both Python compile checks, `tests.test_agent_docs_gate`, and `tests.test_recurring_ai_policy_guard`.**
- [ ] **Step 3: Scan the diff for `sk-proj-`, bearer tokens, cookies, raw auth JSON, and accidental host-local files; result must be clean.**
- [ ] **Step 4: Push `agent/codex-native-profile-fallback`, open a PR to `main`, wait for required checks, and fix any task-related failure.**
- [ ] **Step 5: Merge only after required checks pass; record the merge SHA and verify `main` contains the spec, plan, engine, installer, and tests.**

### Task 6: Activate the merged implementation on all four hosts

- [ ] **Step 1: Re-copy the engine and installer from the merged `main` SHA, not from a pre-merge staging copy.**
- [ ] **Step 2: On each host run the installer once, preserving the timestamped launcher/guard backup and both authenticated profile homes.**
- [ ] **Step 3: Verify manual launchers select the requested profile and `login status` reports `Logged in using ChatGPT` for both profiles.**
- [ ] **Step 4: Verify the automatic path resolves to the native selector and that the older API-key failover is not invoked implicitly.**
- [ ] **Step 5: On Windows prove `ai-cli-scope-guard.ps1` still blocks an unscoped Codex work command with its established block code and still permits safe administrative commands.**
- [ ] **Step 6: On Linux verify executable permissions, real-executable resolution without recursion, and absence of lingering background Codex processes.**
- [ ] **Step 7: Run controlled synthetic failover through each active launcher, then one finite real smoke per host. No automatic repeated real probes are allowed.**
- [ ] **Step 8: If a host fails active validation, restore only that host's launcher/guard backup and leave the authenticated profile directories untouched.**

### Task 7: Final evidence and cleanup

- [ ] **Step 1: Record non-secret evidence for each host: Codex version, both profile status values, selected preferred label, launcher hash, synthetic failover result, real smoke exit code, and process-cleanliness result.**
- [ ] **Step 2: Confirm no daemon, cron, scheduled task, watcher, or recurring paid-AI probe was introduced.**
- [ ] **Step 3: Confirm the task PR is merged, no task-specific PR remains open/draft, the task worktree is clean, and temporary staging directories/processes are removed.**
- [ ] **Step 4: Remove any accidental temporary remote branches created during planning after confirming they contain no unique commits.**
- [ ] **Step 5: Report only `COMPROVADO`, `FALHOU`, or `INCONCLUSIVO` for each host and for the overall feature, with merge SHA and non-secret evidence.**
