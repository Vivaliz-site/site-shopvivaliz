# Codex Shared Session Store Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve one resumable local Codex conversation namespace across Fred/Marina native ChatGPT failover while keeping authentication strictly isolated per profile.

**Architecture:** Extend the existing native-profile installer so it migrates host-local Codex session files into `<home>/.codex-business/shared-session-state/sessions` and makes each profile's `sessions` path resolve to that same directory. Migration is copy-first, hash-validated, conflict-fail-closed, idempotent, and reversible; `auth.json`, SQLite databases, caches, logs, plugins, memories, and other account-scoped state remain profile-local. Initial implementation shares only `sessions/`; any additional index is added only if a real focused resume test proves the installed CLI requires it.

**Tech Stack:** Python 3 standard library (`pathlib`, `hashlib`, `json`, `shutil`, `subprocess`), Bash, Windows PowerShell/cmd junctions, Python `unittest`, GitHub Actions, native Codex CLI.

**Spec:** `docs/superpowers/specs/2026-09-13-codex-shared-session-store-design.md`

## Global Constraints

- Fred and Marina authentication remain separate; never link, copy, print, log, or commit `auth.json`, cookies, tokens, API keys, or device codes.
- Initial shared state is only `sessions/`; do not share SQLite/WAL/SHM files, caches, logs, plugins, memories, goals, queues, or secrets.
- Migration is copy-first. Same-relative-path session files with different SHA-256 values abort before any profile session directory is replaced.
- KOCEPSV currently has 69 legacy session files; post-migration count there must never be lower than 69 or the fresh preflight unique count, whichever is greater.
- No daemon, watcher, cron, scheduled task, polling sync, or recurring paid-AI probe.
- Existing invariant remains: account selection happens before real model work and a started task is never automatically replayed.
- Activate hosts only from the exact merged `main` SHA; pre-merge host activity is read-only inventory or synthetic validation.
- One chat remains one CLI session; shared storage does not permit concurrent agents to drive one session.
- Rollback restores profile-local session paths and never deletes the shared store or authentication.

---

### Task 1: Deterministic session inventory and conflict detection

**Files:**
- Modify: `scripts/install-codex-native-profile-failover.py`
- Modify: `tests/test_install_codex_native_profile_failover.py`

**Interfaces:**
- Produces: `SESSION_STORE_DIR`, `SessionConflictError`, `_sha256_file(path: Path) -> str`, `_session_source_dirs(home: Path, shared_sessions: Path) -> tuple[Path, ...]`, `_build_session_inventory(sources: tuple[Path, ...], shared_sessions: Path) -> dict[str, dict]`, `_copy_inventory_to_shared(inventory: dict[str, dict], shared_sessions: Path) -> dict[str, int]`.

- [ ] **Step 1: Write failing deduplication/security test.**

```python
def test_session_inventory_is_session_only_and_deduplicates_identical_files(self):
    mod = load_module()
    with tempfile.TemporaryDirectory() as td:
        home = Path(td) / 'home'
        shared = home / '.codex-business' / 'shared-session-state' / 'sessions'
        legacy = home / '.codex' / 'sessions' / '2026' / '09' / '13'
        fred = home / '.codex-business' / 'fredmourao' / 'sessions' / '2026' / '09' / '13'
        legacy.mkdir(parents=True); fred.mkdir(parents=True)
        (home / '.codex-business' / 'fredmourao' / 'auth.json').write_text('SUPER_SECRET')
        (legacy / 'a.jsonl').write_text('same\n')
        (fred / 'a.jsonl').write_text('same\n')
        inv = mod._build_session_inventory(mod._session_source_dirs(home, shared), shared)
        self.assertEqual(set(inv), {'2026/09/13/a.jsonl'})
        self.assertNotIn('SUPER_SECRET', repr(inv))
```

- [ ] **Step 2: Write failing same-path/different-content test.**

```python
def test_session_inventory_rejects_conflicting_same_path(self):
    mod = load_module()
    with tempfile.TemporaryDirectory() as td:
        home = Path(td) / 'home'
        shared = home / '.codex-business' / 'shared-session-state' / 'sessions'
        a = home / '.codex' / 'sessions' / 'x.jsonl'
        b = home / '.codex-business' / 'fredmourao' / 'sessions' / 'x.jsonl'
        a.parent.mkdir(parents=True); b.parent.mkdir(parents=True)
        a.write_text('one'); b.write_text('two')
        with self.assertRaises(mod.SessionConflictError):
            mod._build_session_inventory(mod._session_source_dirs(home, shared), shared)
        self.assertFalse(shared.exists())
```

- [ ] **Step 3: Run both focused tests and confirm RED.**

```bash
python3 -m unittest tests.test_install_codex_native_profile_failover -v
```

Expected: failures name the missing inventory interfaces.

- [ ] **Step 4: Implement inventory/copy primitives.** `_session_source_dirs` considers legacy `.codex/sessions`, both profile `sessions`, and an existing shared store; skip profile sources already resolving to `shared_sessions`. Inventory only regular files below session roots, key by POSIX relative path, record SHA-256/size/source path, and raise `SessionConflictError` on differing content.

```python
class SessionConflictError(RuntimeError):
    pass


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open('rb') as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()
```

- [ ] **Step 5: Run installer tests GREEN and commit.**

```bash
python3 -m unittest tests.test_install_codex_native_profile_failover -v
git add scripts/install-codex-native-profile-failover.py tests/test_install_codex_native_profile_failover.py
git commit -m "feat(codex): inventory shared session state safely"
```

---

### Task 2: Copy-first migration, platform links, manifest, and rollback

**Files:**
- Modify: `scripts/install-codex-native-profile-failover.py`
- Modify: `tests/test_install_codex_native_profile_failover.py`

**Interfaces:**
- Consumes: Task 1 inventory primitives.
- Produces: `_create_directory_link(link: Path, target: Path, platform: str) -> None`, `_points_to(path: Path, target: Path) -> bool`, `_prepare_shared_sessions(home: Path, platform: str, backup: Path) -> dict`, `rollback_shared_sessions(home: Path, backup: Path) -> dict`.
- Extends `install(...)` return data with `shared_sessions`, `session_count`, `session_manifest`.

- [ ] **Step 1: Write failing migration/idempotency/auth-isolation test.**

```python
def test_shared_session_migration_preserves_auth_and_links_profiles(self):
    mod = load_module()
    with tempfile.TemporaryDirectory() as td:
        root = Path(td); home = self._home(root)
        legacy = home / '.codex' / 'sessions' / 'old.jsonl'
        legacy.parent.mkdir(parents=True); legacy.write_text('history\n')
        for profile in mod.PROFILES:
            (home / '.codex-business' / profile / 'auth.json').write_text(f'auth-{profile}')
        backup = home / '.codex-business' / 'backups' / 'case'
        result = mod._prepare_shared_sessions(home, 'linux', backup)
        shared = Path(result['shared_sessions'])
        self.assertEqual((shared / 'old.jsonl').read_text(), 'history\n')
        for profile in mod.PROFILES:
            p = home / '.codex-business' / profile
            self.assertEqual((p / 'sessions').resolve(), shared.resolve())
            self.assertEqual((p / 'auth.json').read_text(), f'auth-{profile}')
```

- [ ] **Step 2: Write failing Windows junction and rollback tests.**

```python
with mock.patch.object(mod.subprocess, 'run') as run:
    mod._create_directory_link(Path(r'C:\p\sessions'), Path(r'C:\shared\sessions'), 'windows')
    self.assertEqual(run.call_args.args[0][:5], ['cmd.exe', '/d', '/c', 'mklink', '/J'])
```

Rollback test must prove original profile session directories are restored while `shared-session-state/sessions` remains intact.

- [ ] **Step 3: Run new tests RED.**

```bash
python3 -m unittest tests.test_install_codex_native_profile_failover -v
```

- [ ] **Step 4: Implement `_prepare_shared_sessions`.** Build and validate the complete inventory first; create shared store; copy with `shutil.copy2`; re-hash destination; write `session-migration-manifest.json` under the timestamped backup containing only path/count/size/hash/timestamp metadata; only then move a real profile-local `sessions` directory to `backup/session-paths/<profile>-sessions` and replace it with a shared link.

- [ ] **Step 5: Implement cross-platform links and rollback.** Linux uses `os.symlink(..., target_is_directory=True)`. Windows uses `cmd.exe /d /c mklink /J`. `_points_to` follows symlink/junction targets for idempotency. Rollback removes only links created by this migration, restores preserved profile-local directories, and never removes the shared store.

- [ ] **Step 6: Integrate migration into `install(...)`.** Run after `_validate(...)` and before launcher rewrites; keep current launcher backups. Do not touch `session_index.jsonl`, SQLite files, or anything outside `sessions/`.

- [ ] **Step 7: Verify GREEN and commit.**

```bash
python3 -m unittest \
  tests.test_install_codex_native_profile_failover \
  tests.test_codex_windows_manual_launcher_stderr \
  tests.test_codex_native_profile_failover -v
python3 -m py_compile scripts/install-codex-native-profile-failover.py scripts/codex-native-profile-failover.py
git diff --check
git add scripts/install-codex-native-profile-failover.py tests/test_install_codex_native_profile_failover.py
git commit -m "feat(codex): share resumable sessions across profiles"
```

---

### Task 3: Security regression coverage and recurring-AI guard

**Files:**
- Modify: `tests/test_install_codex_native_profile_failover.py`
- Modify: `scripts/install-codex-native-profile-failover.py` only if the failing security test exposes a real implementation defect.

**Interfaces:**
- Consumes: Task 2 manifest/migration.
- Produces: explicit proof that only session files are shared.

- [ ] **Step 1: Add a forbidden-state test.** Seed `auth.json`, `*.sqlite`, `*.sqlite-wal`, `*.sqlite-shm`, `models_cache.json`, `history.jsonl`, `log/`, `plugins/`, `memories_1.sqlite`, and `secrets/`; after install assert only `sessions/` resolves to the shared store and every forbidden fixture remains profile-local with unchanged bytes.

- [ ] **Step 2: Add manifest secrecy test.**

```python
manifest = json.loads(Path(result['session_manifest']).read_text())
serialized = json.dumps(manifest)
self.assertNotIn('SUPER_SECRET_TOKEN', serialized)
self.assertEqual(set(manifest), {
    'version', 'created_at', 'shared_sessions',
    'session_count', 'files', 'profile_backups'
})
```

- [ ] **Step 3: Run RED/GREEN plus recurring-AI policy guard and commit.**

```bash
python3 -m unittest \
  tests.test_install_codex_native_profile_failover \
  tests.test_recurring_ai_policy_guard -v
git diff --check
git add scripts/install-codex-native-profile-failover.py tests/test_install_codex_native_profile_failover.py
git commit -m "test(codex): enforce shared-session isolation"
```

---

### Task 4: Isolated implementation branch, review, CI, and merge

**Files:**
- Include: approved spec, this plan, installer changes, tests.
- Reconcile unrelated `main` advances without overwriting concurrent work.

**Interfaces:**
- Produces: one green merge SHA and SHA-256 values used for all host activation.

- [ ] **Step 1: Use `superpowers:using-git-worktrees` to create an isolated `feat/codex-shared-session-store` worktree from the approved docs branch.** Fetch `origin/main`; integrate newer `main` normally if it advanced. Never hard-reset or force-push another agent's work.

- [ ] **Step 2: Run full task verification.**

```bash
python3 -m unittest \
  tests.test_codex_native_profile_failover \
  tests.test_install_codex_native_profile_failover \
  tests.test_codex_windows_manual_launcher_stderr \
  tests.test_agent_docs_gate \
  tests.test_recurring_ai_policy_guard -v
python3 -m py_compile scripts/codex-native-profile-failover.py scripts/install-codex-native-profile-failover.py
git diff --check
```

- [ ] **Step 3: Secret/session-artifact scan.** Diff must contain no API key value, bearer value, cookie, auth payload, session transcript content, `.sqlite` database, or host-local session file. File names such as `auth.json` in tests/docs are allowed only as non-secret literals.

- [ ] **Step 4: Use `superpowers:requesting-code-review`.** Reviewer focus: conflict atomicity, copy-before-link ordering, Windows junction safety, auth isolation, idempotency, rollback preservation, and session-loss prevention.

- [ ] **Step 5: Open PR; require task-related CI gates green; diagnose unrelated baseline failures separately.** Do not weaken checks.

- [ ] **Step 6: Merge and record exact `main` SHA plus SHA-256 of `scripts/install-codex-native-profile-failover.py` and `scripts/codex-native-profile-failover.py`.** All four hosts must install these exact blobs, not a moving branch.

---

### Task 5: Read-only four-host inventory and exact-SHA rollout

**Host-local paths:**
- Legacy source: `<home>/.codex/sessions`
- Profile sources/links: `<home>/.codex-business/{fredmourao,marinaofaleiro}/sessions`
- Shared destination: `<home>/.codex-business/shared-session-state/sessions`
- Evidence: `<home>/.codex-business/backups/native-failover-*/session-migration-manifest.json`

**Interfaces:**
- Consumes: Task 4 exact merged artifacts.
- Produces: shared session path per host with both auth profiles still valid.

- [ ] **Step 1: Preflight all four hosts read-only.** Record session-file counts/bytes only for legacy, Fred, Marina, and any pre-existing shared store. Verify both profiles say `Logged in using ChatGPT`. Verify no process is actively writing the session paths being migrated. Never print auth contents.

- [ ] **Step 2: KOCEPSV acceptance baseline.** Legacy/default session count must be at least 69. If lower, stop and investigate the missing history.

- [ ] **Step 3: Download installer/engine by exact merge SHA on each host and verify both SHA-256 values against Task 4.**

- [ ] **Step 4: Install one host at a time.** After each installation require: manifest exists; shared unique session count is not below preflight unique count; Fred and Marina `sessions` resolve to the same shared target; local before/after auth hashes match without printing auth contents; both profile `login status` commands return 0.

- [ ] **Step 5: Platform guards.** Windows: junction target correct; AI scope guard still returns RC 42 for unscoped model work and RC 0 for safe administrative commands. Linux: symlink targets correct, launchers executable, and no leftover Codex process.

- [ ] **Step 6: Verify no recurring mechanism.** No new Windows scheduled task, Linux cron entry, systemd user unit, watcher, or probe loop referencing Codex session synchronization.

---

### Task 6: Prove `codex resume` continuity under both accounts on all four hosts

**Files/runtime:** no repository edit unless a focused failure proves another non-secret session index is required.

**Interfaces:**
- Consumes: shared store from Task 5; native contract `codex resume [SESSION_ID] [PROMPT]`, plus `--last`/`--all` for diagnostics.
- Produces: real proof of one session namespace per host and real Fred↔Marina continuation on KOCEPSV.

- [ ] **Step 1: On every host choose one existing migrated session UUID when available.** Do not read/print transcript contents. If a host has zero sessions, create one harmless finite interactive session under Fred solely for validation, then use that UUID for both profiles.

- [ ] **Step 2: On every host verify the same UUID is discoverable/resumable under Fred and Marina.** Run each profile's `codex resume --all` in a controlled terminal and verify the same chosen UUID appears; exit picker without starting a duplicate session. Record only UUID/count/result.

- [ ] **Step 3: KOCEPSV real cross-profile continuity smoke.** Resume one migrated legacy UUID with Fred and prompt `Reply exactly RESUME_FRED_OK and do not use tools.`; require RC 0 and marker. Then resume the same UUID with Marina and prompt `Reply exactly RESUME_MARINA_OK and do not use tools.`; require RC 0 and marker. Close stdin in the remote harness.

- [ ] **Step 4: Prove no duplicate session was created by the cross-profile test.** The same session path must be updated and session-file count must not increase solely because of the two resume operations.

- [ ] **Step 5: Re-run automatic account failover smoke on all four hosts.** It must select a usable account before task start, execute the real task once, update only non-secret failover state, and leave no orphan process. Re-run unit test proving a started task is never automatically replayed.

- [ ] **Step 6: If sessions-only sharing fails, rollback immediately and diagnose.** Use `rollback_shared_sessions`, preserve shared data, invoke `superpowers:systematic-debugging`, identify the additional non-secret read dependency, add a focused failing test, and land a follow-up PR before sharing `session_index.jsonl` or anything else. SQLite remains forbidden unless the spec is revised and re-approved.

---

### Task 7: Final verification and cleanup

**Files:** no feature code expected.

**Interfaces:**
- Produces: clean repo/hosts with no task-specific pending PR, worktree, branch, or smoke process.

- [ ] **Step 1: Use `superpowers:verification-before-completion`.** From merged `main`, rerun task tests and confirm exact installed artifact hashes on all four hosts.

- [ ] **Step 2: Final host assertions.** Both accounts authenticated on all four hosts; both profile session paths resolve to one store per host; chosen validation UUID is visible under both profiles on every host; KOCEPSV count is at least the preflight unique count and never below 69; KOCEPSV cross-profile direct resume passed.

- [ ] **Step 3: Final security assertions.** No shared auth; no credential/session transcript leaked to Git/logs/output; no shared SQLite/WAL/SHM; no recurring sync/probe; Windows scope guard and Linux permissions still correct.

- [ ] **Step 4: Remove only task staging directories, worktree, and merged feature/docs branches.** Preserve timestamped backups, active shared store, and legacy/default sessions as rollback evidence.

- [ ] **Step 5: Confirm no task-specific open PR or pending task Action.** Report final merge SHA, four-host status, per-host session counts, validation UUIDs, and backup locations without exposing transcript/auth contents.
