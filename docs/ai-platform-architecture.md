# AI Platform Architecture

## Core Structure
1. **Agent Layer**
   - Autonomous agents for specific tasks (Olist sync, image processing, checkout optimization)
   - Isolated execution environments

2. **Orchestrator**
   - config/ai-orchestrator.json defines workflow rules
   - Enforces financial boundaries and compliance
   - Models the autonomous flow as `Sensor -> Director -> Executor -> Validator`

3. **Execution Engine**
   - Secure API gateway
   - Rate limiting for Olist/Tiny integrations
   - Model selection for cost optimization (e.g., `gemini-2.5-flash`, `claude-3-haiku-20240307`)
   - Audit logging for all operations

4. **Workspace Agent Bootstrap**
   - `.vscode/settings.json` bootstraps Roo Code at workspace open
   - `config/roo-autonomous-settings.json` auto-imports execution defaults
   - Auto-approval is enabled for routine engineering operations with command denylist protection

5. **Tri-Environment Sync**
   - `scripts/tri-environment-sync.js` is the canonical runtime for PC, cloud/GitHub and Oracle
   - `config/tri-environment-sync.json` defines branch policy, environment roles and pull/push boundaries
   - `logs/tri-environment-sync.json` and `logs/autonomous-sync.json` expose the last runtime status for monitor and report endpoints

6. **Sales Focus**
   - `scripts/autonomous-continuous-cycle.py` now prioritizes `sales_flow` before other revenue dimensions
   - `logs/roi-engine-report.json` feeds the monitor and report endpoints with the top sales opportunity
   - Sales work stays within governance: no price edits, no budget changes, no campaign publish without approval

7. **Canonical Task Queue**
   - `tasks-queue.json` is the source of truth for autonomous work
   - `logs/tasks-queue.json` is mirrored for legacy scripts and reports
   - `scripts/run-autonomy-phases.py` includes phase-tagged and sales-tagged revenue tasks so the director can keep selecting safe growth work continuously
   - External-access tasks can be auto-blocked when required credentials are absent
   - Budget-sensitive tasks such as Google Ads are auto-blocked until human approval exists

## Execution Flow
1. Trigger -> Agent Selection
2. Context Validation
3. Autonomous Execution
4. Result Verification
5. Audit Trail Creation

## Growth Governance
- Revenue-oriented prioritization focuses on conversion impact, SEO gaps and catalog readiness
- Learning loop uses queue history, generated reports and catalog metrics as feedback
- SEO, content, performance, dynamic product pages and catalog sync can run autonomously
- Paid media execution remains approval-gated even when technical preparation is automated

## Security Compliance
- No direct price manipulation
- Financial rules enforced at orchestration level
- Guardian of Price protection maintained
- All changes go through Git workflow
- Synchronization must never push directly to `main`; tri-environment sync may only pull, or push approved branches
- Roo autonomous mode must keep write protection for workspace control files and deny direct push to `main`
