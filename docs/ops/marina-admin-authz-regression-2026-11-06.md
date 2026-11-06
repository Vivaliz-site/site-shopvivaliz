# Marina administrative authorization regression — owner review

## Reproduced 2026-11-06
Orquestrador hosts_list announces marina_admin_command, but native
admin_command_run persisted task 92a0c469-ca7c-4f98-946c-8368642049e7
with exit_code=77 and authorization_denied_before_execution.
marina_host_health returned exit_code=0 and the independent MCP service
was active. Catalog visibility is not effective command authorization.

## Protected remediation plan
Trace the task authorization decision for the actual authenticated principal,
host, command, owner grant #3670 and audit ID. Reconcile approved grant
propagation or stale authorization cache only after confirming the cause.
Any expanded privileges require separate owner approval. Do not bypass
MFA, RBAC, leases, fencing, branch protection or provider refusals.
Do not access the retired VM.

## Regression gate
Run scripts/ops/verify-marina-admin-execution.py on a sanitized
task_status JSON receipt for the exact canary hostname -s; id -un.
A failed or denied receipt must fail the gate. Catalog-only checks are
insufficient. Validate both native clients and GUI parity independently.

This PR adds evidence and a fail-closed verification gate; it does not
modify authorization policy or assert that the live denial is fixed.
