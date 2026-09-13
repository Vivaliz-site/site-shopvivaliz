# Codex Native ChatGPT Profile Failover Design

**Date:** 2026-09-13
**Scope:** automatic finite-session failover between two native ChatGPT-authenticated Codex profiles on the four ShopVivaLiz execution hosts.

## Goal

Make Codex prefer the primary native ChatGPT profile and automatically switch to the secondary native ChatGPT profile only when the active profile is demonstrably unavailable because of usage/quota/rate-limit exhaustion or authentication failure. The mechanism must not depend on OpenAI Platform API keys, must not introduce recurring paid-AI activity, and must preserve one-chat-one-CLI-session isolation.

## Confirmed current state

The two native profiles are already authenticated through ChatGPT on all four hosts:

- `shopvivaliz-free-a1`
- `always-free-arm-1787907847-26`
- `LAPTOP-NIG4IFUU`
- `DESKTOP-KOCEPSV`

The canonical profile homes are `<home>/.codex-business/fredmourao` for primary and `<home>/.codex-business/marinaofaleiro` for secondary. Each contains its own `auth.json`; auth material remains local and is never copied into Git, logs, PRs, process arguments, or assistant output.

The Linux VMs already have manual launchers for each profile plus a preliminary `codex-auto` launcher. That preliminary launcher only checks `codex login status`, so it cannot detect an authenticated account whose ChatGPT usage allowance is exhausted. Windows has both profiles but does not yet have equivalent native-profile automatic failover integrated into the existing AI scope guard.

## Architecture

A single canonical, deterministic failover engine will be versioned in the repository and copied to each host-local runtime location during installation. Thin Bash and PowerShell entry points will only adapt platform-specific process execution and preserve the existing ShopVivaLiz scope guard. The engine will never store or manipulate ChatGPT tokens directly; it selects profiles exclusively by setting `CODEX_HOME`.
## Profile selection and state

The ordered profiles are `fredmourao` then `marinaofaleiro`. A host-local state file stores only the preferred profile label and non-secret timestamps/results; it contains no token, cookie, e-mail password, device code, or API key. The preferred profile is tried first on the next invocation. A successful execution keeps that preference. A failover-worthy failure changes the preferred label only after the alternate profile proves usable for that invocation.

The engine must also tolerate the preferred profile becoming usable again later. When the secondary profile later produces a failover-worthy failure, the primary is eligible again; there is no permanent lockout and no infinite alternation. One invocation may attempt each profile at most once.

## Non-interactive commands

For `codex exec`, alias `e`, `review`, and other explicitly classified non-interactive finite commands, the engine runs the requested command with the preferred `CODEX_HOME` while capturing enough redacted stderr/stdout to classify the result. Normal successful output is preserved for the caller.

A retry with the alternate profile is allowed only when the first attempt fails with a failover-worthy authentication or capacity signature. The classification includes native Codex messages equivalent to usage limit reached, rate limit, quota/allowance exhausted, too many requests, authentication required, unauthorized, token expired, refresh-token failure, or account/session no longer logged in.

Ordinary command errors, repository errors, model-generated task failures, Git failures, network/DNS/time-out failures without an auth/capacity signature, user cancellation, SIGINT, and nonzero exits unrelated to account availability must return unchanged and must not cause account switching.

## Interactive commands

For a new interactive Codex session, the engine performs at most one minimal finite `codex exec --ephemeral` availability probe against the preferred native profile before opening the TUI. The probe uses the same `CODEX_HOME`, a bounded timeout, no persisted thread, no repository mutation, and a tiny deterministic prompt. It exists only to distinguish a logged-in-but-exhausted profile from a usable one.

If that probe succeeds, the interactive session opens with the preferred profile. If it fails with a recognized auth/capacity condition, the engine performs one equivalent probe with the alternate profile and opens the TUI there if successful. If both profiles are unavailable, the wrapper exits with a clear non-secret error rather than looping or opening repeated device-login flows.
## Interaction with existing API-key fallback

The native ChatGPT profile failover is the primary Codex path. The existing `scripts/codex-failover.py` for OpenAI Platform API keys remains a separate compatibility mechanism and must not be invoked implicitly by the native profile selector. Host launchers must resolve the real Codex executable directly, avoiding recursion through the older API-key wrapper. API-key failover stays opt-in behind its existing enable marker and does not participate in deciding between the two ChatGPT profiles.

## Windows and Linux integration

Linux exposes manual `codex-fred`, manual `codex-marina`, and automatic `codex-auto`. The automatic launcher delegates to the canonical engine with the real Codex executable path. Manual launchers remain available for diagnosis and explicit account selection.

Windows adds equivalent manual and automatic launchers while preserving `ai-cli-scope-guard.ps1`. The guard continues to enforce Git-scope policy before starting Codex. When automatic mode is requested, it delegates to the canonical native-profile engine; Claude behavior and unrelated scope-guard behavior remain unchanged.

No host may reuse another chat's terminal/tmux/session. The wrapper selects credentials only; session namespace and process isolation remain the responsibility of the invoking chat/agent through `CHAT_CLI_SESSION_ID` and the existing repository protocol.

## Safety and resource controls

No daemon, cron, scheduled task, watcher, periodic probe, or retry loop is introduced. A failover decision happens only inside an explicit finite Codex invocation. Each profile is attempted at most once per invocation, and each interactive availability probe has a fixed timeout. This keeps paid-AI use finite and bounded.

Probe output and command diagnostics must be redacted before persistence. Runtime state may record profile label, start/end timestamps, attempt count, result class, and selected profile, but never auth tokens or raw `auth.json` content. Existing profile files are never rewritten by the failover engine.

## Failure handling

If one profile is unavailable and the alternate succeeds, the command continues on the alternate and the preferred state is updated. If both are unavailable, the command fails once with a stable exit code and a message naming only the profile labels and failure classes. It must not invoke `codex login`, open device authorization, or ask for credentials automatically.

If the wrapper itself is missing, malformed, or cannot resolve the real Codex executable, installation validation fails and the host keeps/restores the previous launcher. A host-local backup is taken before launcher integration. Existing authenticated profile directories are never deleted during rollback.
## Validation strategy

Repository tests will exercise the selector without real credentials by injecting fake executors and deterministic command outputs. Required cases are: primary healthy; primary exhausted then secondary succeeds; secondary exhausted then primary succeeds on a later invocation; authentication expired; ordinary non-account command failure with no switch; network failure with no switch; user cancellation with no switch; both profiles unavailable with exactly one attempt per profile; interactive probe selecting primary; interactive probe selecting secondary; and state persistence containing no secrets.

Host validation is independent on all four hosts. It must verify that both native profiles report `Logged in using ChatGPT`, the real Codex executable resolves without recursion, manual launchers select the requested profile, the automatic launcher selects the preferred usable profile, and controlled synthetic failure injection proves switching without consuming or corrupting the real accounts. A single minimal real Codex invocation may then confirm the selected native profile can execute; it is finite and never repeated automatically.

Windows validation additionally confirms the Git-scope guard still blocks unscoped Codex work and permits the established safe administrative commands. Linux validation confirms wrapper permissions and that no background Codex login/probe process remains after the test.

## Rollback

Before changing host launchers, preserve timestamped copies of the current launcher/scope-guard files. Rollback restores only those launcher files and removes non-secret selector state; it does not delete or alter either authenticated `CODEX_HOME`. Repository rollback uses a normal revert after merge if required; no force-push, hard reset, production-release edit, or secret rewrite is permitted.

## Success criteria

The feature is complete only when the canonical engine and tests are merged to `main`; both native ChatGPT profiles remain independently authenticated on all four hosts; automatic selection works on Linux and Windows; synthetic tests prove Fred-to-Marina and Marina-to-Fred failover with bounded attempts; a real finite smoke succeeds with a native ChatGPT profile; no API key is required for the primary path; no recurring paid-AI process exists; the task worktree is clean; and no task-specific PR remains open.
