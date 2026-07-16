# 🤖 Agent Dual-Validation Policy

**EFFECTIVE: 2026-07-16**  
**APPLIES TO: All agents (Claude, GPT, Gemini) making code changes**

## Core Rule

Every agent making code changes **MUST**:

1. **Self-Validate** - Validate all changes (lint, type-check, tests, build)
2. **Request Peer Validation** - Ask another agent to review
3. **Wait for Approval** - Only proceed with deployment after peer validates
4. **No Pending Changes** - Validation must happen immediately, never left pending

## Validation Workflow

```
AGENT MAKES CHANGE
        ↓
AGENT SELF-VALIDATES
        ↓
AGENT REQUESTS PEER VALIDATION
        ↓
PEER AGENT VALIDATES (Immediately)
        ↓
BOTH APPROVE?
        ├─ YES → AUTO-MERGE & DEPLOY
        └─ NO → FIX & RETRY
```

## The Three Agents

| Agent | Primary Role | Validation Focus |
|-------|--------------|------------------|
| **Claude** | Code quality, architecture, safety | Correctness, best practices |
| **GPT** | Feature implementation, logic | Functionality, integration |
| **Gemini** | Performance, optimization, testing | Speed, coverage, edge cases |

## What Validation Includes

### Self-Validation (Every Change)
```
✓ Syntax/lint checks (eslint, tsc, php)
✓ Unit tests passing
✓ Type checking passing
✓ Build completes without errors
✓ No hardcoded secrets/credentials
✓ No breaking changes to APIs
```

### Peer Validation (Another Agent)
```
✓ Code quality and style
✓ Security issues
✓ Performance concerns
✓ Architecture alignment
✓ Test coverage adequate
✓ Edge cases handled
```

## GitHub Automation

**Workflow:** `.github/workflows/agent-dual-validation.yml`

1. When a PR is opened/updated:
   - Agent self-validates automatically
   - If pass, peer validation triggers immediately
   - If peer approves, auto-merge happens
   - Deployment follows automatically

2. Failures:
   - If self-validation fails: PR blocked, agent must fix
   - If peer validation fails: Specific issues noted, agent must address

## Priority & Urgency

**Validation is priority-blocking:**
- No "I'll validate later"
- No "merge first, validate after"
- No pending validations
- Everything must be synchronous

## When to Invoke Peer Validation

**ALWAYS invoke peer validation when:**
- Making core API changes
- Touching authentication/security
- Modifying data models
- Changing pricing/payment logic
- Adding new third-party integrations
- Modifying CI/CD workflows

**CAN skip peer validation only when:**
- Fixing typos/documentation
- Updating comments
- Reformatting (no logic change)
- Renaming with automated refactoring

## Communication Format

**When requesting peer validation, use:**

```
@[PEER_AGENT] Please validate these changes:

**What Changed:**
- [brief summary]

**Self-Validation:**
- ✅ Lint passed
- ✅ Tests passed (X tests)
- ✅ Build successful
- ✅ No secrets found

**Validation Checklist:**
- Code quality
- Security
- Performance
- Test coverage
- Architecture

Waiting for approval before merge.
```

## Violations

**If an agent merges without peer validation:**
1. Auto-revert the change
2. Create issue: "Agent validation bypass detected"
3. Require both agents to re-validate before re-merge
4. Document in audit log

## Examples

### ✅ CORRECT - Dual Validation
```
Claude: "Making auth changes. Self-validated. @GPT please review."
GPT: "Reviewed. Security checks passed. Code quality good. ✅ APPROVED"
Claude: "Both validated. Merging now."
[Auto-merge happens, deploy follows]
```

### ❌ WRONG - No Peer Validation
```
Claude: "Making auth changes. Validated myself. Merging."
[Auto-revert triggered]
Claude: "What happened?!"
[Issue created, both must re-validate]
```

### ❌ WRONG - Pending Validation
```
Claude: "Making changes. Self-validated. @GPT please validate later."
[PR sits pending for hours]
[User frustrated, validation is left hanging]
```

## Dashboard

Check validation status:
```bash
gh pr view [PR_NUMBER] --json statusCheckRollup
```

Each PR must show:
1. ✅ Agent Self-Validation (PASS)
2. ✅ Peer Agent Review (PASS)
3. ✅ Auto-Approval & Deploy (READY)

## Questions?

When validation fails:
1. Read the error messages carefully
2. Fix the issue in code
3. Re-trigger validation by pushing again
4. Validation re-runs automatically
5. Request peer validation again if needed

---

**This policy ensures code quality, security, and continuous deployment confidence across all agents.**

Approved by: Shopvivaliz Development  
Effective Date: 2026-07-16  
Last Updated: 2026-07-16
