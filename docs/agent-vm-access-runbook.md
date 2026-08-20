# Agent VM access runbook

This repository must keep VM access knowledge in documentation and in a constrained reusable workflow, not in ad-hoc temporary workflows.

## Purpose

Future agents may need to inspect production state without asking the owner to restate SSH details. Use this runbook and the reusable workflow instead of creating `tmp-*` workflow files or committing raw live-state reports.

## Required GitHub secrets

The repository/environment must provide these secrets. Do not write their values to files, logs, issues, PRs, reports, or docs.

- `SHOPVIVALIZ_VM_HOST` — production VM host or DNS name.
- `SHOPVIVALIZ_VM_USER` — optional; defaults to `ubuntu` when absent.
- `SHOPVIVALIZ_VM_SSH_KEY` — private SSH key for the production VM.
- `SHOPVIVALIZ_VM_KNOWN_HOSTS` — pinned known_hosts entry for the VM.

Do not hardcode host IPs in workflows. Do not fall back to legacy VM secrets unless a separate security review explicitly approves it.

## Preferred access path for read-only diagnostics

Use `.github/workflows/agent-vm-readonly-diagnostics.yml`.

That workflow is intentionally limited:

- `workflow_dispatch` only.
- `permissions: contents: read` only.
- Uses `SHOPVIVALIZ_VM_HOST` and pinned `SHOPVIVALIZ_VM_KNOWN_HOSTS`.
- Does not commit reports back to the repository.
- Runs a small allowlisted set of read-only diagnostic modes.
- Writes only sanitized summaries to the Actions log and job summary.

Allowed modes are:

- `health` — uptime, disk, memory, systemd overview for known services.
- `docker` — Docker container names/images/status only.
- `git-status` — deployment working tree status without file contents.
- `mei-baseline` — minimal MEI service/database summary without schema dumps or row-level data.

## Rules for agents

1. Read this file before attempting VM access.
2. Prefer the reusable read-only workflow for diagnostics.
3. If direct SSH is available in the execution environment, use the same secret names and pinned known_hosts policy.
4. Never create temporary workflows that can auto-run on `push` to access the VM.
5. Never grant `contents: write` to VM diagnostic workflows.
6. Never auto-commit raw VM output or production database output to the repository.
7. Never print tokens, passwords, private keys, full connection strings, headers, cookies, authorization values, or `.env` contents.
8. Avoid printing private network addresses, exact internal paths, database schemas, row-level production data, customer data, or operational timestamps unless strictly needed and already sanitized.
9. Mutating production requires a dedicated reviewed workflow or direct operator session with explicit guardrails. Do not hide mutation inside a diagnostic workflow.
10. If a report is needed, summarize conclusions in a PR comment or doc with sensitive details redacted.

## What was replaced

On 2026-08-19, a temporary workflow named like `tmp-mei-baseline-live-read-*` was found in `.github/workflows/`. It used VM SSH and committed raw live-state output under `reports/`. The workflow and raw report were removed because workflows are executable infrastructure, not safe long-term memory.

The safe replacement is this runbook plus `.github/workflows/agent-vm-readonly-diagnostics.yml`.
