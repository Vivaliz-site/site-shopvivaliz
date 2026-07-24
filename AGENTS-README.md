# 🤖 Agent Operations Guide

**For: Claude, GPT, and Gemini**  
**Updated: 2026-07-16**  

## Important: New Mandatory Policy

**Effective immediately: All agents MUST follow the Dual-Validation Policy before deploying.**

Read: [`AGENT-DUAL-VALIDATION-POLICY.md`](AGENT-DUAL-VALIDATION-POLICY.md)

## Quick Start for Agents

### Before Making ANY Code Changes

1. **Read the policy** → `AGENT-DUAL-VALIDATION-POLICY.md`
2. **Check what changed** → Run `git status`
3. **Create your branch** → `git checkout -b feature/your-name`

### After Making Code Changes

```bash
# 1. Self-validate (automatic via GitHub workflow)
   ✓ npm run lint
   ✓ npm run type-check
   ✓ npm run test
   ✓ npm run build

# 2. Commit changes
   git add .
   git commit -m "description of change"
   git push origin feature/your-name

# 3. Create/update PR
   # GitHub workflow triggers automatically

# 4. Request peer validation
   @[OTHER_AGENT] Please validate PR #[NUMBER]
   
# 5. Wait for approval
   # No merge until peer approves

# 6. Auto-merge & deploy (happens automatically)
```

## The Three-Agent Team

| Role | Primary Tasks | Validation Focus |
|------|---------------|------------------|
| **Claude** | Architecture, security, code quality | Correctness, best practices, safety |
| **GPT** | Features, implementation, integration | Functionality, completeness, integration |
| **Gemini** | Performance, testing, optimization | Speed, test coverage, edge cases |

## Validation Checklist

### Self-Validation (Every Agent Does This)

npm run lint           # ✅ No lint errors?
npm run type-check     # ✅ All types correct?
npm run test           # ✅ Tests pass?
npm run build          # ✅ Builds successfully?
# ✅ Visual browser validation performed (if layout, HTML, CSS, JS or templates were changed)
```

**If ANY fail:** Fix the issue, commit again, push again. Validation re-runs automatically.

### Peer Validation (Other Agent Does This)

When requested to validate:

```bash
# Review for:
☑ Code quality & style
☑ Security issues
☑ Performance concerns
☑ Architecture alignment
☑ Test coverage adequate
☑ Edge cases handled
```

**Comment on PR:**
```
✅ APPROVED - All checks passed, code quality is good, ready for deployment.
```

or

```
❌ CHANGES NEEDED - Please fix:
  - [Issue 1]
  - [Issue 2]
```

## Workflow: Agent Dual-Validation

Located in: `.github/workflows/agent-dual-validation.yml`

```
You push code
    ↓
Self-validation runs (lint, tests, build)
    ↓
You request peer validation
    ↓
Peer agent reviews (immediately, don't delay!)
    ↓
Both approve?
    ├─ YES → Auto-merge → Deploy
    └─ NO → Fix issues → Push again
```

## Emergency: What If Something Breaks?

1. **Pull request won't merge?**
   - Check the workflow output: `gh pr view [NUMBER] --json statusCheckRollup`
   - See what failed
   - Fix the issue
   - Push again (auto-triggers validation)

2. **Peer validation taking too long?**
   - Validation must be immediate (priority-blocking)
   - Check if peer agent is available
   - Escalate to user if needed

3. **Tests failing?**
   - Run tests locally: `npm run test`
   - Fix the failing test
   - Commit and push
   - Don't wait for others to fix it

## Important Rules

❌ **DON'T:**
- Merge without peer validation
- Skip self-validation checks
- Leave PRs pending
- Push code you haven't tested locally
- Hardcode secrets or credentials

✅ **DO:**
- Always self-validate first
- Always request peer validation
- Validate peers immediately (it's blocking)
- Test locally before pushing
- Read error messages carefully
- Fix issues promptly

## Daily Workflow Example

**Scenario:** Claude wants to fix a bug

```
Claude: "Fixing authentication bug. Self-validated locally.
         Making PR..."
[Push code]
[GitHub workflow starts auto-validation]
[Workflow passes: ✅ Lint, ✅ Tests, ✅ Build]
[PR created automatically]

Claude: "@GPT Please validate PR #305"

GPT: "Reviewing... code quality good, security checks pass,
     edge cases handled. ✅ APPROVED"

Claude: "Great! Both validated. Auto-merging now."
[Auto-merge happens]
[Deploy starts automatically]
[🎉 Feature deployed]
```

## Troubleshooting

### "My validation failed!"

1. Read the error message carefully
2. Fix the issue in code
3. Commit: `git commit -m "fix: issue description"`
4. Push: `git push`
5. Validation re-runs automatically
6. No need to open new PR (same PR, same workflow)

### "Peer agent hasn't validated yet"

**Peer validation is priority-blocking.** This means:
- Don't start other work
- Validation should happen immediately
- If peer is unavailable, escalate to another agent

### "I need to revert my change"

```bash
git revert [COMMIT_HASH]
git push
# Auto-validation runs
# Auto-merge happens
# Revert deployed
```

## MCP Server Access

All agents can access the remote machine via MCP:

**Server:** `http://137.131.156.17:5556`  
**Documentation:** `MCP-REAL-SERVER-SETUP.md`

Available tools:
- `execute_command` - Run shell commands
- `git_*` - Git operations
- `file_*` - File operations
- `service_*` - Service management
- `npm_build` - Build the project
- `system_health` - Health checks

## CI/CD Pipelines

Key workflows running on every push:

1. **ShopVivaliz QA** - Lint, type-check, PHP lint
2. **Storefront Quality** - Quality gates, smoke tests
3. **Real E2E Gate** - End-to-end tests (Playwright)
4. **Agent Dual Validation** - Your new workflow!

Check status: `gh pr view [PR_NUMBER]`

## Questions?

- Read: `AGENT-DUAL-VALIDATION-POLICY.md`
- Check: GitHub PR checks for error details
- Ask: Use `@mention` on PR comments

---

**Welcome to autonomous agent operations! 🚀**

The dual-validation system ensures every change is validated by two independent agents before deployment. This keeps code quality high and reduces bugs in production.

Let's build great things together!
