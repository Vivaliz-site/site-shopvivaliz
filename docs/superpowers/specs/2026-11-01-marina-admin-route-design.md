# Marina operator-approved maintenance route — design

**Date:** 2026-11-01. **Status:** security change proposed, not authorized for live installation.

## Decision and rationale
After migrating web/deploy/DB/workers to `marina-a1-4c24g`, the central MCP cannot target that host. The independent Marina MCP is read-only and not installed. Do not redirect a retired host alias or route a denied privileged action through backend SSH, Github Actions, or OCI.

Implement a **separate, local-only maintenance adapter** under `marina-direct-mcp`, initially capable only of restarting `amazon-returns-olist-erp-browser.service` after all safety conditions are genuinely satisfied. It does not grant root access or create a network shell. Expand the operation catalogue only through another owner-reviewed security change.

## Trust boundaries
- Human identity: only `shopvivaliz-human` may personally approve and merge a separate exact-scope security authorization PR. Agents cannot authenticate as that user. The PR must add `security/owner-approvals/<number>.json`, bound to head SHA, merge SHA, resource `mcp:marina`, principal `marina-mcp-service`, capability `deployment_manage`, and expiry.
- Effective host privileges are supplied by the official Marina operator, not GitHub. A reviewed PR is human authorization **not** an OS privilege, MFA replacement, or permission to ignore a provider denial.
- A trusted local adapter must derive hostname, uid, maintenance lease/fencing and scope from the protected host context. Never accept caller-provided proof booleans.
- Production restart is allowed only after protected Olist environment files and Playwright runtime are safely provisioned and validated. This implementation may inspect file *metadata* but must never read or copy their secret values.
- Never expose generic shell, sudo, SQL execution, arbitrary path, command, host or service selection.
- Maintain a one-time idempotency key, host-side lease/fence and redacted audit trail. Without a trusted lease implementation, deny all mutations.

## Alternatives
1. Add Marina as a backend alias: rejected due to retired-host boundary and security refusals.
2. Reuse the read-only independent MCP as root: rejected because its deliberately unprivileged contract would be broken.
3. **Separate operator-only, fail-closed admin adapter**: selected. Deploy separately after a human PR and installation/tunnel checks.

## Acceptance
Negative tests: missing owner proof, agent approval, wrong host or uid, stale PR, missing config, invalid lease, reused operation ID, arbitrary operation, malformed fence all deny. A positive mock-host test permits the fixed systemd command only when all authoritative gates pass. Real system integration remains `NAO_VALIDADO` until a separately approved operator provisions the service and proves UI E2E/reboot/rollback.