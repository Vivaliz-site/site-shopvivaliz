# Agent Docs Preflight

**Policy ID:** `AGENT_DOCS_PREFLIGHT_V1`

This policy is mandatory for every AI agent, automation agent, coding agent, QA agent, browser agent, and external assistant that can change ShopVivaliz code, configuration, workflows, content, runtime state, integrations, or production behavior.

## Non-negotiable rule

Before the first mutating action of a task, the agent MUST read the current versions of the required project documents and obtain a current docs receipt.

A mutating action includes editing or creating a repository file, changing a queue/task state, opening or updating a PR with code changes, merging, changing runtime configuration, changing an integration, executing a write API, or performing a production mutation.

The required base documents are:

1. `REGRAS-AGENTES-CENTRALIZADAS.md`
2. `AGENTS.md`
3. `AGENTS-ACCESS-INDEX.md`
4. `docs/ai-agents-map.md`
5. this file: `docs/AGENT-DOCS-PREFLIGHT.md`

The agent MUST also read scope-specific documentation referenced by the task or discovered while investigating it before changing that scope.

## Enforced protocol

The canonical preflight tool is `scripts/agent-docs-gate.py`.

### 1. Read

```bash
python scripts/agent-docs-gate.py read --agent <agent-id> --task <task-id>
```

The command emits the complete current contents of the required base documents and records which document digest was delivered for that agent/task.

### 2. Acknowledge after reading

Use the digest printed by the read command:

```bash
python scripts/agent-docs-gate.py acknowledge --agent <agent-id> --task <task-id> --digest <digest>
```

Acknowledgement is rejected unless a matching read delivery exists first.

### 3. Verify immediately before mutation

```bash
python scripts/agent-docs-gate.py verify --agent <agent-id> --task <task-id>
```

Exit code `0` is required before mutation. Missing, stale, mismatched, or unacknowledged receipts fail closed.

## Staleness

The receipt is bound to SHA-256 hashes of all required documents. If any required document changes, all previous receipts become invalid automatically and the agent must read and acknowledge the new versions before another mutation.

## No shortcut when a tool is unavailable

A missing preferred tool is not permission to skip required validation or evidence. The agent must investigate and use an approved alternative path where one exists. For browser validation, examples include the existing Fred-Win private tunnel/remote-browser route documented in `reports/fredwin-remote-access-repair-2026-08-07.md` when the direct Opera connector is unavailable.

If no legitimate route exists after investigation, the task may be reported as `INCONCLUSIVO`; the agent must not invent evidence or silently downgrade the requirement.

## Runtime receipts

Read deliveries and acknowledgements are runtime evidence and are stored below `storage/private/agent-docs-read/`. They are not source files and must not be committed.

## Defense in depth

The orchestrator must require a valid current receipt before handing a mutating execution step to an agent. Repository quality checks must verify that this policy and its enforcement code remain present. A repository check is a backstop, not a substitute for the pre-mutation read.
