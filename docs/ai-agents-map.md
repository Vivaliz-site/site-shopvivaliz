# AI Agents Map

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
| PagarMeWebhookAgent | Payment webhook processing | Transaction events | Read-only |
| InventoryMonitorAgent | Stock level monitoring | Inventory alerts | None |
| SEOOptimizationAgent | Product SEO improvements | Content only | None |

## Agent Constraints
- **No agent may modify prices directly**
- **No agent may alter financial rules**
- **All agents respect Guardian of Price**
- **All agents execute through orchestrator**
- **Tri-environment sync never writes to `main` directly**

## Communication Protocol
- Agents communicate via orchestrator only
- No direct agent-to-agent calls
- All state changes logged
- Audit trail mandatory
