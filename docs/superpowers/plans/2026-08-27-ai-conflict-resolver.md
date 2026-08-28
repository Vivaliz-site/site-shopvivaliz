# AI Conflict Resolver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a zero-per-token-cost, conservative AI-assisted merge-conflict resolver for pull requests.

**Architecture:** GitHub Actions detects same-repository PR conflicts, applies deterministic safety gates, starts local Ollama on the runner, asks a small open code model to resolve only non-protected files, validates the candidate, runs repository checks, and pushes a normal commit to the PR branch. Forks and production-sensitive areas fail closed.

**Tech Stack:** GitHub Actions, Python 3 standard library, git, Ollama, qwen2.5-coder:1.5b.

**Spec:** `docs/superpowers/specs/2026-08-27-ai-conflict-resolver-design.md`

## Global Constraints
- No paid AI API.
- Never force-push or merge automatically.
- Never send secrets, production deploy controls, payment/auth logic, migrations, credential files, or destructive mass changes to the model.
- Same-repository PR branches only.
- Existing QA, governance, policy, history-integrity and deployment gates remain authoritative.

---

### Task 1: Safety core
- [ ] Add unit tests for protected paths, conflict markers, model JSON parsing, and safe paths.
- [ ] Implement `.github/scripts/ai_conflict_resolver.py` with standard-library-only helpers.
- [ ] Verify tests.

### Task 2: Resolver
- [ ] Read BASE/OURS/THEIRS from git stages.
- [ ] Reject protected paths before inference.
- [ ] Call local Ollama and require JSON full-file output.
- [ ] Reject markers, empty destructive output, excessive deletion, or path mismatch.

### Task 3: Workflow
- [ ] Add `.github/workflows/ai-conflict-resolver.yml` for PR and manual execution.
- [ ] Skip forks/bots and use least privileges.
- [ ] Install/start Ollama only when a safe conflict exists.
- [ ] Run unit tests and repository validation before push.

### Task 4: Verification
- [ ] Repair any pre-existing CI checkout blocker required to validate this PR without weakening policy.
- [ ] Confirm tests and normal repository checks.
- [ ] Preserve branch protection and review rules.
- [ ] Merge only with fresh verification evidence.