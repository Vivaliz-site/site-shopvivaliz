# AI Agents Map

## Mandatory preflight for every agent

Policy: `AGENT_DOCS_PREFLIGHT_V1`.

Before any mutating action, every agent in this registry — including Claude, Gemini, ChatGPT/GPT, Codex, browser/QA agents, marketplace agents, autonomous workers, and future agents — MUST execute the protocol in `docs/AGENT-DOCS-PREFLIGHT.md`:

1. `python scripts/agent-docs-gate.py read --agent <agent-id> --task <task-id>`
2. read all emitted required documents and any scope-specific docs;
3. acknowledge the exact current digest;
4. `python scripts/agent-docs-gate.py verify --agent <agent-id> --task <task-id>` must return exit code 0 before the first mutation.

A stale or missing receipt prevents the orchestrator from handing a mutating execution step to the agent. If a preferred tool is unavailable, the agent must investigate an approved alternative route rather than silently skipping a required validation.

## Agent Registry

### Core Agents
| Agent | Purpose | Scope | Financial Impact |
|-------|---------|-------|------------------|
| OlistSyncAgent | Product synchronization with Olist | Catalog sync only | None |
| ImageOptimizationAgent | Product image processing | Visual assets only | None |
| CheckoutFlowAgent | Checkout UX optimization | UI/UX only | None |
| FreightCalculationAgent | Shipping cost calculation | Logistics only | None |


### Development & QA Agents
| Agent | Purpose | Scope | Financial Impact |
|-------|---------|-------|------------------|
| ReleaseManagerAgent | Version control & release notes | Deployment | None |
| QASelfTestAgent | Lint, integrity, endpoints | System health | None |
| SeleniumTestRunnerAgent | UI/E2E testing for Olist | Olist UI | None |
| ConfigValidatorAgent | Validate configurations & dependencies | System health | None |
| TriEnvironmentSyncAgent | Synchronize PC, cloud and Oracle | Repository state | None |

### Specialized Agents
| Agent | Purpose | Scope | Financial Impact |
|-------|---------|-------|------------------|
| InventoryMonitorAgent | Stock level monitoring | Inventory alerts | None |
| SEOOptimizationAgent | Product SEO improvements | Content only | None |

## Agent Constraints
- **No agent may modify prices directly**
- **No agent may alter financial rules**
- **All agents respect Guardian of Price**
- **All agents execute through orchestrator**
- **All agents must pass `AGENT_DOCS_PREFLIGHT_V1` before mutation**
- **Tri-environment sync never writes to `main` directly**

## Communication Protocol
- Agents communicate via orchestrator only
- No direct agent-to-agent calls
- All state changes logged
- Audit trail mandatory
- Remote MCP access, when available, is an operator-controlled channel documented in `docs/AGENT-MCP-REMOTE.md`; it does not expand repository, production, marketplace, payment, or secret permissions.
