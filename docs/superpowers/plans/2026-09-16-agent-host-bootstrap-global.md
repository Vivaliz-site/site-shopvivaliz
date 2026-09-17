# Agent Host Bootstrap Global Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Make the canonical ShopVivaliz host/access bootstrap visible at the repository entrypoints agents read first, while keeping `docs/knowledge/host-access.md` authoritative.

**Architecture:** Add a short binding bootstrap block near the top of `AGENTS.md`, `CLAUDE.md`, and `docs/AGENTS.md`. Do not duplicate secrets or turn historical incident notes into current operational guidance; instead, explicitly mark older VM/IP sections as historical when they conflict with the canonical Knowledge Base.

**Tech Stack:** Markdown documentation, Git/GitHub pull-request workflow.

**Spec:** `docs/knowledge/host-access.md`

## Global Constraints

- Current production web/deploy host: `shopvivaliz-free-a1` (`137.131.149.55`, private `10.0.1.112`).
- Current backend/MEI/M365/relay host: `always-free-arm-1787907847-26` (private `10.0.1.38`, no public IP).
- `shopvivaliz-ai` / `137.131.156.17` is legacy DEV/e-mail/tests, never production web.
- Prefer Remote Desktop Commander by device name; public direct SSH is disabled.
- Never include private key, token, password, cookie, or secret contents.

---

### Task 1: Propagate canonical bootstrap to agent entrypoints

**Files:** Modify `AGENTS.md`, `CLAUDE.md`, `docs/AGENTS.md`.

- [x] Insert a concise mandatory bootstrap callout linking `docs/knowledge/host-access.md`, `docs/knowledge/README.md`, and `docs/knowledge/agent-rules.md`.
- [x] State the current host roles and mark contradictory historical VM/IP passages as non-authoritative.
- [x] Preserve historical incident material unless it is presented as current operational truth.

### Task 2: Validate and integrate

**Files:** Validate all modified Markdown plus this plan.

- [x] Grep the entrypoints for the canonical Knowledge Base links and current host roles.
- [x] Confirm no new secret values were introduced and no stale public production IPs are presented in the new bootstrap blocks.
- [x] Review `git diff --check`, `git diff`, and `git status --porcelain`.
- [x] Commit on an isolated branch, push, open PR, wait for required checks, merge, and verify the merged `main` content independently through GitHub.