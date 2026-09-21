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

## Mandatory Fred-Win scope preflight

For any task that mentions or depends on Fred-Win, Windows automation, Remote MCP, port `5557`, Exchange Admin on the Windows computer, browser validation through the Windows computer, `ssh-tunnel-service-managed.ps1`, `fred-win-remote-action.yml` or `ops/fredwin-request.json`, the agent MUST additionally read:

1. `docs/FRED-WIN-PRIVATE-RELAY.md`
2. `docs/AGENT-MCP-REMOTE.md`
3. `.github/workflows/fred-win-remote-action.yml`
4. `ops/fredwin-request.json`

Before stating that Fred-Win is active or inactive, the agent MUST apply the canonical status protocol from `docs/FRED-WIN-PRIVATE-RELAY.md`.

Critical classification rule:

- `COMPROVADO / ATIVO`: only after the allowlisted `health` succeeds through GitHub Actions -> Oracle VM `137.131.156.17` -> VM loopback `127.0.0.1:5557` -> reverse SSH -> Fred-Win.
- `FALHOU / INATIVO`: only after the canonical health path fails and objective process, tunnel or log evidence confirms the component is down or broken.
- `INCONCLUSIVO`: when the workflow, VM, health endpoint or logs cannot be checked. Lack of access is never evidence of inactivity.

The historical endpoint `https://rce-shopvivaliz.trycloudflare.com` MUST NOT be used to determine current Fred-Win status.

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

A missing preferred tool is not permission to skip required validation or evidence. The agent must investigate and use an approved alternative path where one exists. For browser validation, examples include the existing Fred-Win private tunnel/remote-browser route documented in `docs/FRED-WIN-PRIVATE-RELAY.md` and `reports/fredwin-remote-access-repair-2026-08-07.md` when the direct Opera connector is unavailable.

If the Fred-Win route cannot be tested, the agent must report it as `INCONCLUSIVO`; it must not infer `INATIVO` from an unavailable connector, the deprecated Cloudflare hostname, lack of a recent workflow run or inability to inspect the local Windows filesystem from the current session.

If no legitimate route exists after investigation, the task may be reported as `INCONCLUSIVO`; the agent must not invent evidence or silently downgrade the requirement.

## Runtime receipts

Read deliveries and acknowledgements are runtime evidence and are stored below `storage/private/agent-docs-read/`. They are not source files and must not be committed.

## Defense in depth

The orchestrator must require a valid current receipt before handing a mutating execution step to an agent. Repository quality checks must verify that this policy and its enforcement code remain present. A repository check is a backstop, not a substitute for the pre-mutation read.
