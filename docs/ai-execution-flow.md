# AI Execution Flow

## Workflow Phases
1. **Trigger Phase**
   - Event detection (product update, inventory change)
   - Context validation (financial rules check)

2. **Agent Selection**
   - Orchestrator determines appropriate agent(s)
   - Constraints validation (no price modification)
   - Human-approval gates are evaluated before execution for ads or budget-sensitive work

3. **Execution**
   - Agent runs in isolated environment
   - Audit trail generation
   - Result verification
   - Tasks that depend on missing credentials or manual domain/DNS access are auto-blocked instead of waiting indefinitely
   - Tasks that require campaign or budget approval are auto-blocked with explicit status instead of running silently

4. **Completion**
   - Status update to orchestrator
   - Compliance check
   - Result verification (including unit and integration tests)
   - Audit trail finalization

5. **Tri-Environment Synchronization**
   - `scripts/tri-environment-sync.js` compares PC, cloud/GitHub and Oracle state
   - Auto-pull is allowed when the working tree is clean
   - Auto-push is blocked on protected branches and only allowed on approved feature-style branches
   - Sync state is surfaced in `logs/tri-environment-sync.json`, `api/agent/autonomous-report.php` and `api/monitor/api.php`

6. **Sales Flow Priority**
   - `sales_flow` is the highest revenue dimension in the autonomous selector
   - ROI top opportunities feed the monitor so agents can keep working on conversion, SEO, marketplace and product-page tasks first
   - Credentials missing from the runtime are surfaced explicitly so the flow does not stall silently

## Phase Mode
- Autonomous growth work may be grouped into phased execution for safer progression.
- `scripts/run-autonomy-phases.py` classifies growth tasks as local-ready, CI-ready with repo secrets, or blocked by approval/manual access.
- Tasks with `phase-*` metadata or sales-oriented tags are included in the phase report so new revenue work does not stall in the selector.
- Phase reports are written to `logs/autonomy-phase-report.json` and `logs/autonomy-phase-report.md`.

## Financial Safeguards
- All price-related operations prohibited
- Financial rules enforced at trigger phase
- Guardian of Price protection maintained
- No direct main branch modifications
- Ads and paid media preparation can be queued automatically, but campaign execution remains approval-gated

## Audit Requirements
- All operations logged with:
  - Timestamp
  - Agent ID
  - Input/Output
  - Financial context
- Autonomous cycles must also emit a structured event log in `logs/autonomous-cycle-events.jsonl` containing changed files, executed tests, chosen next task and selection reason.
- Tri-environment sync must never push directly to `main`; any local commit drift must be reported before synchronization continues.
- Audit trail stored in immutable format
- Compliance verification required for all changes
- Canonical autonomous queue lives in `tasks-queue.json` and is mirrored to `logs/tasks-queue.json` for backward compatibility
- Architecture follows `Sensor -> Director -> Executor -> Validator`, with the revenue engine prioritizing autonomous SEO, catalog, product-page and CRO work
