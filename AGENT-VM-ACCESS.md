# Agent VM access

Future agents should not recreate temporary VM workflows or ask the owner to restate VM access details.

Use the canonical runbook:

- `docs/agent-vm-access-runbook.md`

Use the reusable read-only diagnostic workflow when GitHub Actions is the access path:

- `.github/workflows/agent-vm-readonly-diagnostics.yml`

Required secret names are documented in the runbook. Never hardcode the VM host, never commit raw VM output, and never grant `contents: write` to read-only diagnostics.
