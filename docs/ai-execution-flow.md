# AI Execution Flow

## Workflow Phases
1. **Trigger Phase**
   - Event detection (product update, inventory change)
   - Context validation (financial rules check)

2. **Agent Selection**
   - Orchestrator determines appropriate agent(s)
   - Constraints validation (no price modification)

3. **Execution**
   - Agent runs in isolated environment
   - Audit trail generation
   - Result verification
   - Tasks that depend on missing credentials or manual domain/DNS access are auto-blocked instead of waiting indefinitely

4. **Completion**
   - Status update to orchestrator
   - Compliance check
   - Audit trail finalization

## Financial Safeguards
- All price-related operations prohibited
- Financial rules enforced at trigger phase
- Guardian of Price protection maintained
- No direct main branch modifications

## Audit Requirements
- All operations logged with:
  - Timestamp
  - Agent ID
  - Input/Output
  - Financial context
- Audit trail stored in immutable format
- Compliance verification required for all changes
- Canonical autonomous queue lives in `tasks-queue.json` and is mirrored to `logs/tasks-queue.json` for backward compatibility
