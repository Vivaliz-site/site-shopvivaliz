#!/usr/bin/env bash
set -euo pipefail

saw_path=0
web_change=0
backend_only=1
backend_server=0
backend_writer=0
while IFS= read -r path; do
  [ -n "$path" ] || continue
  saw_path=1
  # Explicit, restrictive MCP-only exception: only the checked-in
  # Orquestrador metadata writer plus the exact accompanying CI/runbook paths.
  # Any additional or unknown path falls back to the normal web deployment gate.
  case "$path" in
    remote-control-mcp/server.py)
      backend_server=1 ;;
    scripts/continuity/checkpoint_update_atomic.py)
      backend_writer=1 ;;
    remote-control-mcp/SPEC.md|\
tests/test_orquestrador_atomic_checkpoint.py|\
tests/remote-control-mcp-test.py|\
scripts/remote_mcp_bootstrap_scope.py|\
tests/test_remote_mcp_bootstrap_scope.py|\
.github/workflows/remote-control-mcp-bootstrap.yml|\
scripts/should-deploy-production.sh|\
scripts/setup-remote-control-access.sh|\
tests/should-deploy-production-test.sh)
      ;;
    *)
      backend_only=0 ;;
  esac
  case "$path" in
    # Both entries below are consumed by CI/gates from the current checkout;
    # neither is an application runtime script. Do not generate a new public
    # release just because a governance policy/verifier changed.
    # Any other scripts/* and all real web/PHP payloads stay deployment-triggering.
    *.md|docs/*|.codex/*|.github/*|tests/*|\
scripts/check_pr_completion_policy.py|\
scripts/should-deploy-production.sh)
      ;;
    *)
      web_change=1 ;;
  esac
done

# Empty or untrusted diff remains conservative.
if [ "$saw_path" -eq 0 ]; then
  printf '%s\n' true
elif [ "$backend_only" -eq 1 ] && [ "$backend_server" -eq 1 ] && [ "$backend_writer" -eq 1 ]; then
  printf '%s\n' false
elif [ "$web_change" -eq 1 ]; then
  printf '%s\n' true
else
  printf '%s\n' false
fi
