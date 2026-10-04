# Remote Control MCP: bounded stdio transport and durable work

## Execution contract

The canonical tunnel runs `/usr/local/sbin/shopvivaliz-claude-mcp-stdio`.
The adapter prefers the browser MCP on loopback port 5581; the base MCP on
5580 is a fallback only when the connection is positively refused before the
request is accepted. Neither endpoint is exposed publicly by this change.

- `admin_command_run` with a timeout at most 20 seconds remains inline.
- Longer administrative calls, calls with no timeout (the original default is
  30 seconds), and explicit `durable=true` calls are submitted to the existing
  durable task queue before execution. The original command, host and timeout
  are preserved; the response is a queued receipt, not completed work.
- A promoted response contains `task_id`, `execution_mode=durable`,
  `requested_tool=admin_command_run`, and its idempotency `request_id`.
  Read `task_status` until a terminal result and inspect `exit_code` and the
  goal-specific evidence. Queue acceptance does not prove success.
- Explicit `durable=false` with a timeout above the inline budget is rejected
  before execution. Use `task_submit` rather than silently changing that intent.
- A caller-supplied `request_id` is preserved. Otherwise the adapter assigns one
  per submission and keeps it across any safe pre-connect fallback.
- A timeout, connection reset or lost response returns
  `controller_response_indeterminate`, `retry_safe=false`, and the submission
  key when available. Do not blindly repeat the operation: reconcile its durable
  record or audit first. A lost response does not prove that execution never ran.
- The adapter does not remove inline process-group cancellation, resource limits,
  explicit `task_cancel`, token authentication, host restrictions or access gates.

## Verification boundaries

`tests/test_claude_mcp_stdio_transport.py` covers routing, preserved limits,
idempotency, safe connection-refused fallback, and no replay after uncertain
responses. Its real loopback HTTP regression accepts a request and drops the
response: the old adapter records the effect twice; the corrected adapter once.
Fault-injection tests are not evidence of authenticated ChatGPT recovery.

Deployment must verify the installed adapter hash, reload the canonical tunnel
so its stdio child loads the new code, then submit one bounded command lasting
longer than the old 30-second limit through the actual connector. Verify exactly
one durable result and no duplicate execution. Keep all output free of secrets.

The platform's additional-check banner is a separate symptom. Transport
reliability evidence does not establish the cause of that banner.
