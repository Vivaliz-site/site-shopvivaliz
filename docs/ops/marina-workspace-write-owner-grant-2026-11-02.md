# Owner approval: Marina MCP scoped workspace write (2026-11-02)

**This PR is a human security grant, not a code PR, and must never be merged by an agent.**
It only authorizes the exact `marina_workspace_file_write` operation after the
ShopVivaliz grant validator verifies approval by `shopvivaliz-human` on the
final HEAD and exclusive human merge, and after host/Platform gates succeed.

- Host: `marina-a1-4c24g` only.
- Principal: `marina-mcp-service`, non-root service identity `shopvivaliz-marina-mcp`.
- Target repo: `Vivaliz-site/site-shopvivaliz` only.
- Capability and operation: `file_write` / `marina_workspace_file_write`.
- Scope: only private service workspace `/var/lib/shopvivaliz-marina-mcp/workspace`,
  filenames limited to simple .txt/.json, max 4096 UTF-8 bytes; NO arbitrary files,
  shell, sudo, Docker, databases, site deploy or workers.
- Preconditions: code PR #3513 merged/tested; dedicated OpenAI Platform tunnel
  separately authenticated; real host-owned write operator verifies immutable
  owner grant and effective permission, durable lease/fencing/idempotence,
  audit prepare/commit, CAS and atomic write.
- Effects **after** valid host provisioning: creation or CAS-replacement of one
  private data file per authorized call; no production file mutates.
- Negative tests: unapproved PR, invalid reviewer/HEAD/merge SHA, grant expired,
  wrong host/principal/repository/operation, missing privileged adapter,
  wrong OS identity, symlink/traversal, stale CAS, replay and lock contention.
- Rollback: revoke by removing the merged grant under a separately protected
  change, disable the trusted adapter, stop announcing this single tool;
  no changes to read-only access, site, workers or MCP central.

The separately created `security/owner-approvals/<THIS_PR_NUMBER>.json` is
the exact machine-readable grant. A PR review `APPROVED` and merge by the
human-only `shopvivaliz-human` are required; chat assent alone is insufficient.
This grant does not create OS or external Platform RBAC, keys or tunnel IDs.
