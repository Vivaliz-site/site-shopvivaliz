# AI Agents Map

## Agent Registry

### Core Agents
| Agent | Purpose | Scope | Financial Impact |
|-------|---------|-------|------------------|
| OlistSyncAgent | Product synchronization with Olist | Catalog sync only | None |
| ImageOptimizationAgent | Product image processing | Visual assets only | None |
| CheckoutFlowAgent | Checkout UX optimization | UI/UX only | None |
| FreightCalculationAgent | Shipping cost calculation | Logistics only | None |

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

## Communication Protocol
- Agents communicate via orchestrator only
- No direct agent-to-agent calls
- All state changes logged
- Audit trail mandatory