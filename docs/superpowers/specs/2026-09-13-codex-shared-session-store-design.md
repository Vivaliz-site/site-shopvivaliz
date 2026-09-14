# Codex Shared Session Store Design

**Date:** 2026-09-13
**Scope:** preserve `codex resume` continuity when the native ChatGPT failover engine switches between the Fred and Marina Codex profiles on the four ShopVivaLiz execution hosts.

## Relationship to the existing failover design

This document extends `2026-09-13-codex-native-profile-fallback-design.md`. The existing requirement that each ChatGPT identity has an isolated `CODEX_HOME` and isolated authentication remains unchanged. This design changes only where resumable conversation state is stored.

## Problem

The native-profile failover intentionally uses separate homes:

- `<home>/.codex-business/fredmourao`
- `<home>/.codex-business/marinaofaleiro`

That keeps `auth.json` and account-specific state isolated, but Codex also discovers resumable sessions relative to `CODEX_HOME`. When failover changes the active profile, `codex resume` therefore sees a different local session namespace.

On `DESKTOP-KOCEPSV`, the legacy/default Codex home currently contains 69 session files while both native failover profile homes contain zero session files. The observed `No sessions yet` screen is therefore a local-storage namespace problem, not evidence that the two ChatGPT accounts belong to different workspaces.

## Goal

A session created before an account failover must remain resumable after the failover without copying, merging, or exposing authentication material. `codex resume` should present the same local sessions regardless of whether Fred or Marina is the currently selected ChatGPT identity.

## Non-goals

This change does not merge ChatGPT accounts, move tokens between accounts, share `auth.json`, change workspace membership, synchronize sessions between different physical hosts, or create a background synchronization service. Each host keeps its own local session history.

## Architecture

Authentication state and resumable-session state are separated conceptually and physically.

Each profile keeps its own private `CODEX_HOME` for account-scoped state. A third host-local location, `<home>/.codex-business/shared-session-state`, becomes the canonical store for the minimum set of files/directories required by `codex resume`.

The installer exposes that shared store inside both profile homes through filesystem links rather than copying session data on every invocation. This makes both profiles observe the same session namespace while leaving the remaining profile contents independent.

The first implementation target is deliberately minimal:

- share the `sessions/` directory;
- share `session_index.jsonl` only if the installed Codex version uses it for discovery;
- share no SQLite database by default;
- share no auth, cache, model, plugin, memory, queue, goal, log, or secret file.

If host validation proves that another non-secret session index is required for `resume`, it may be added only after a focused regression test demonstrates the need. Mutable SQLite databases must not be shared merely for convenience because concurrent Codex processes may have independent locks/WAL state.

## Platform integration

On Linux, the installer uses symbolic links for the selected session-state paths. On Windows, it uses an NTFS directory junction or symbolic link for the shared `sessions/` directory and a supported filesystem link for any required shared index file. The installer must preflight link creation on the target filesystem and fail safely instead of falling back to an unbounded copy/sync loop.

The existing launchers remain unchanged in purpose:

- `codex-fred` selects Fred authentication;
- `codex-marina` selects Marina authentication;
- `codex-auto` selects the usable account according to the failover engine;
- the Windows AI scope guard remains authoritative for repository scope.

All three launch paths must observe the same resumable-session namespace.

## Existing-session migration

Installation must preserve existing histories. Before changing links, the installer inventories session-bearing locations in the legacy/default Codex home and both native profile homes.

The migration is copy-first and validation-first:

1. create a timestamped backup manifest containing only paths, counts, sizes, and hashes where practical;
2. create the shared session store without deleting any source data;
3. copy unique existing session files into the shared store, preserving relative paths and timestamps where possible;
4. reject conflicting same-path files whose contents differ instead of overwriting either copy;
5. validate file counts and hashes before switching profile paths to links;
6. only after validation, replace empty/profile-local session paths with links to the shared store;
7. preserve the original source history until post-install validation is complete.

For the currently observed KOCEPSV state, the migration must retain all 69 legacy session files. A lower post-migration count is an installation failure.

## Concurrency and ownership

The shared session store is host-local and may be used by multiple finite Codex processes. The design relies on Codex's existing session-file behavior rather than introducing a second writer or synchronization daemon.

The failover wrapper must not copy or rewrite transcript files while Codex is running. It selects the account before the model task begins; session reads/writes then go directly to the shared filesystem path. Existing one-chat-one-CLI-session rules still apply and remain the primary protection against two agents driving the same session concurrently.

No recurring scheduled task, watcher, polling job, or paid-AI probe is added.

## Resume semantics across accounts

A Codex session is treated as local conversation state, not as authentication state. After Fred-to-Marina or Marina-to-Fred selection, the selected profile may resume a session created while the other profile was active if Codex accepts that session format under the same installed CLI/workspace context.

The system must not assume this compatibility merely because both accounts belong to the same ChatGPT workspace. The implementation is complete only after a real controlled cross-profile resume succeeds on the installed Codex version.

If Codex itself rejects a cross-profile session because the transcript embeds account-bound server state, the installer must leave authentication isolation intact and report that limitation; it must not copy tokens or force a common `auth.json` to bypass it.

## Validation strategy

Repository tests must cover installer behavior without real credentials:

- profile authentication files remain distinct and untouched;
- both profile session paths resolve to the same shared store;
- migration preserves pre-existing legacy sessions;
- same-path/different-content conflicts fail closed;
- rerunning the installer is idempotent;
- rollback restores pre-install paths;
- no secret-bearing file is linked into the shared store;
- no recurring sync/probe mechanism is created.

Host validation must run independently on all four hosts. It must inventory session counts before and after migration, confirm both accounts still report `Logged in using ChatGPT`, confirm Fred and Marina resolve the same session store, and prove `codex resume` discovers the same session set under both profiles.

At least one host with existing history must perform a real cross-profile continuity smoke: select a known resumable session created under one profile/home, resume it using the other authenticated profile, send a harmless deterministic prompt that uses no tools, and verify the expected response without creating a second copy of the session.

The KOCEPSV migration has an additional acceptance check: the existing 69-session history must remain discoverable after installation.

## Rollback

Before changing any session path, preserve a timestamped backup manifest and the original source paths. Rollback removes only the newly created links and restores the original directories/files from their preserved locations. It must never delete either profile's `auth.json` or overwrite authentication state.

The shared store is not automatically deleted during rollback if it contains sessions that were created after migration. In that case rollback preserves the shared data and reports its location for manual reconciliation, preventing loss of conversations created while the shared store was active.

## Security constraints

Authentication remains strictly profile-local. The shared store must exclude `auth.json`, API keys, cookies, device codes, browser state, secret directories, and raw credential diagnostics. Logs and state may record only profile labels, session counts/ids, paths, hashes, timestamps, and result classes.

The repository contains only installer logic and tests; it never contains user session transcripts or authentication material.

## Success criteria

The extension is complete only when:

- repository tests and all required CI gates pass;
- the implementation is merged to `main` before host activation;
- the exact merged artifact is installed on all four hosts;
- Fred and Marina remain independently authenticated on every host;
- both profiles on each host resolve the same local resumable-session namespace;
- existing histories are preserved, including all 69 currently observed KOCEPSV sessions;
- a real cross-profile `codex resume` continuity smoke succeeds;
- automatic Fred-to-Marina failover still works without repeating an already-started task;
- no shared authentication, background sync daemon, recurring paid-AI task, or secret exposure is introduced;
- rollback remains possible without session loss;
- task-specific worktrees/branches are cleaned after merge and no task PR remains open.
