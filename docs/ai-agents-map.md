<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Estado de runtime posterior, prevalente (2026-10-29):** o MCP central do backend agora anuncia **somente backend, Fred-Win e KOCEPSV**; a origem `shopvivaliz-free-a1` foi removida da allowlist (PRs #3391/#3392) e nunca e alvo. Marina permanece fora do MCP central, mas RDC direto e SSH privado foram comprovados; o tunel SSH do MEI para Marina esta ativo (HTTP 200), separado do MCP independente Marina que continua nao instalado. O PR #3395 bloqueou 20 jobs antigos sem migrar automaticamente seus objetivos. Controlador 24x7 ainda precisa de lease valido e recuperacao de checkpoints; CI `mei-ci-a1` segue sem registro. Declaracoes de "MCP ainda inclui origem" abaixo sao apenas historicas. Referencia: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Regra vigente para todos os agentes (2026-10-29):** ler no `main` `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md` e `docs/knowledge/agent-rules.md` antes de qualquer tarefa/retomada; registrar SHA e comprovar identidade/autorizações. **Web/deploy/DB/workers na Marina (`marina-a1-4c24g`, OCI ESPOSA), RDC direto validado; backend/MCP/controller/browser em `always-free-arm-1787907847-26` (OCI PRINCIPAL). `shopvivaliz-free-a1` foi excluída: nunca acessar, testar, reativar ou recriar, nem reutilizar seu alias.** O MCP central ainda anuncia a origem e não roteia Marina; o MCP independente/túnel da Marina está inativo e sem app registrado. RDC 2, SSH privado, OCI e privilégios exigem validação própria. Scripts/workflows históricos com destino antigo ficam proibidos para execução até correção e teste, sem troca cega por Marina.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

# AI Agents Map

## Mandatory preflight for every agent

Policy: `AGENT_DOCS_PREFLIGHT_V1`.

Before any mutating action, every agent in this registry — including Claude, Gemini, ChatGPT/GPT, Codex, browser/QA agents, marketplace agents, autonomous workers, and future agents — MUST execute the protocol in `docs/AGENT-DOCS-PREFLIGHT.md`:

1. `python scripts/agent-docs-gate.py read --agent <agent-id> --task <task-id>`
2. read all emitted required documents and any scope-specific docs;
3. acknowledge the exact current digest;
4. `python scripts/agent-docs-gate.py verify --agent <agent-id> --task <task-id>` must return exit code 0 before the first mutation.

A stale or missing receipt prevents the orchestrator from handing a mutating execution step to the agent. If a preferred tool is unavailable, the agent must investigate an approved alternative route rather than silently skipping a required validation.

### Fred-Win scope

Any agent that accesses, diagnoses or reports the state of Fred-Win MUST read `docs/FRED-WIN-PRIVATE-RELAY.md` and use the canonical relay:

```text
GitHub Actions -> Oracle VM 137.131.156.17 -> VM 127.0.0.1:5557 -> reverse SSH -> Fred-Win 127.0.0.1:5557 -> MCP
```

The allowlisted `health` action in `.github/workflows/fred-win-remote-action.yml` is the first required test. The deprecated `rce-shopvivaliz.trycloudflare.com` hostname and the absence of a direct desktop connector are not valid status evidence. Without the canonical health test, report `INCONCLUSIVO`, not `INATIVO`.

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
| FredWinRelayValidator | Validate Fred-Win through the private relay | Read-only health and evidence | None |

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
- **Fred-Win status must use `docs/FRED-WIN-PRIVATE-RELAY.md`; unavailable evidence means `INCONCLUSIVO`**

## Communication Protocol
- Agents communicate via orchestrator only
- No direct agent-to-agent calls
- All state changes logged
- Audit trail mandatory
- Remote MCP access, when available, is an operator-controlled channel documented in `docs/AGENT-MCP-REMOTE.md`; it does not expand repository, production, marketplace, payment, or secret permissions.
- The Fred-Win private relay is documented in `docs/FRED-WIN-PRIVATE-RELAY.md` and must not be confused with a direct Desktop Commander connection or the historical public Cloudflare endpoint.
