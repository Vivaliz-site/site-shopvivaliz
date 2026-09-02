# Agent Completion Governance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make it impossible for repository agents to treat a local commit or an open PR as a completed task.

**Architecture:** Keep the canonical rule in `REGRAS-AGENTES-CENTRALIZADAS.md` and mirror a concise mandatory summary in the agent entrypoints that can override one another (`AGENTS.md`, `CLAUDE.md`, `docs/AGENTS.md`, `.github/AGENTS.md`). Record the rule in shared agent memory and keep the dedicated PR policy aligned.

**Tech Stack:** Markdown governance files, Git branches, GitHub Pull Requests.

**Spec:** User rule confirmed on 2026-09-01: branch -> real validation -> commit -> push -> PR -> checks -> merge -> post-merge validation -> clean working tree -> no pending PR for the task.

## Global Constraints

- Never commit directly to `main`.
- A commit is an intermediate checkpoint, never a final state.
- Do not leave an open/draft PR as a parking lot at task end.
- Do not merge before real, reproducible validation appropriate to the change.
- After merge, verify the target branch and confirm the task has no remaining open PR and no uncommitted local changes.

---

### Task 1: Align agent instructions and memory

- [ ] Strengthen the canonical completion rule.
- [ ] Mirror it in Claude/Codex-facing repository instructions.
- [ ] Record it in shared agent memory and PR policy.
- [ ] Remove contradictory direct-to-main examples from `CLAUDE.md`.
- [ ] Validate documentation consistency and Git diff.
- [ ] Commit, push, open PR, validate checks, merge, and verify the final repository state.