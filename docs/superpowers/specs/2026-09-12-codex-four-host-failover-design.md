# Codex Four-Host Credential Failover Design

**Date:** 2026-09-12
**Scope:** Codex CLI authentication on the four ShopVivaLiz execution hosts.

## Goal

Make Codex CLI use two approved OpenAI API credentials as an automatic failover pair. If the active credential becomes unusable because of authentication, authorization, quota, billing/usage limit, or rate limiting, the next finite Codex invocation must continue with the other credential without requiring device-code login.

## Hosts

- `shopvivaliz-free-a1` — primary Linux VM.
- `always-free-arm-1787907847-26` — secondary Linux VM.
- `LAPTOP-NIG4IFUU` — Windows fallback host.
- `DESKTOP-KOCEPSV` — Windows fallback host.

The Linux VMs remain primary infrastructure. Windows hosts are fallback capacity and must not become required for normal operation.

## Credential handling

The two credential values come from the owner's Gmail message with subject `openai`. Their literal values must never enter Git, PRs, logs, command-line arguments, or assistant responses. To avoid rewriting secret material, the protected runtime file may be a byte-for-byte copy of that attachment using `openai_token_1` and `openai_token_2`; the parser also remains backward compatible with `OPENAI_API_KEY_PRIMARY` and `OPENAI_API_KEY_SECONDARY`.
On POSIX hosts the credential file is `~/.codex/api-keys.env` with mode `0600`. On Windows the equivalent file is `%USERPROFILE%\.codex\api-keys.env` with an ACL restricted to the owning user and SYSTEM where practical. Existing credential files are backed up before replacement; backups remain local and protected.

## Runtime behavior

`scripts/codex-failover.py` remains the canonical failover engine. It tracks the currently preferred credential in `~/.codex/api-key-active`, tries the preferred credential first, and changes the preference only when evidence shows the credential is unusable.

Failover-worthy conditions include HTTP 401/403, payment/quota exhaustion, usage/billing hard limits, and HTTP 429 responses. Network/DNS/time-out failures alone do not mark a credential bad. User interruption is never retried with the alternate credential.

Each Linux host gets a transparent `codex` shim that invokes the canonical failover engine while preserving the real Codex executable path separately. On Windows, the existing ShopVivaLiz AI scope guard remains the entry point and delegates only Codex invocations to the failover engine while preserving its Git-scope checks and leaving Claude behavior unchanged. Both paths must avoid recursive resolution and pass arguments unchanged.

No daemon, cron, watcher, or polling loop is introduced. Failover occurs only as part of a finite user/agent Codex invocation, consistent with the repository policy against recurring paid-AI consumption.

## Safety and concurrency

Each chat continues to use an isolated CLI namespace. The failover state contains only the labels `primary` or `secondary`, never secret material. A credential change on one host does not mutate another host; all four hosts are validated independently.
## Validation

Repository tests must cover failover classification and primary-to-secondary switching without making real API calls. Host validation must confirm: Codex CLI exists, both configured credentials match the approved pair by SHA-256 only, local secret permissions are restrictive, the wrapper resolves without recursion, and a non-destructive Codex authentication check can execute with the active credential.

A controlled failover test uses an intentionally invalid in-memory/local test credential or dependency injection rather than modifying the approved secret values. No test may print either credential.

## Rollback

Before changing each host, preserve the current Codex launcher/configuration and any existing credential file in a timestamped local backup. If wrapper validation fails, restore the previous launcher and credential file on that host. Repository rollback is the normal Git revert path after merge; no production release directory is edited in place.

## Success criteria

All four hosts resolve `codex` through the failover path, both approved credentials are present only in protected local storage, repository tests pass, the change is merged to `main`, and no PR or task-specific branch remains pending. A host that cannot be reached or securely configured is reported as an external blocker rather than silently omitted.