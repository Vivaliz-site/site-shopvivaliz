# Remote Control MCP: bounded stdio transport and durable work

## Execution contract

The canonical tunnel runs `/usr/local/sbin/shopvivaliz-claude-mcp-stdio`.
The adapter prefers the browser MCP on loopback port 5581; the base MCP on
5580 is a fallback only when the connection is positively refused before the
request is accepted. Neither endpoint is exposed publicly by this change.

- `admin_command_run` with a timeout at most 5 seconds remains inline.
  This is a bounded synchronous transport budget, not a command-execution
  quota, token allowance, or 20-second waiting window.
- Administrative calls with timeout above 5 seconds, calls with no timeout
  (the default command timeout is 30 seconds), and explicit `durable=true`
  calls are submitted to the existing durable task queue before execution. The original command, host and timeout
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

## Failure classification and durable continuation (2026-11-06)

The **5-second foreground budget** is a synchronous adapter contract, not
ChatGPT account authorization. In particular, an explicit
`admin_command_run(durable=false, timeout=8)` returns
`foreground_budget_exceeded_use_task_submit` without running the command.
Call the **existing** `task_submit` with a stable `request_id` and then
`task_status` until terminal; require `state=succeeded`, `exit_code=0`
and task-specific readback. This is not a permission escalation or an
alternate transport to evade a provider denial.

A `controller_response_indeterminate`, connection loss after acceptance or
unknown submission state is **different**: reconcile `request_id` and audit
records before any retry to prevent duplicate operations. A 401/403,
MFA/RBAC denial or explicit platform refusal must **not** be resubmitted
under another tool.

The workspace Admin Console may separately mark an app **Allow all tools**
while the user-level app execution/approval setting reports **Allow low-risk
actions**. These are distinct policy surfaces; neither one changes the
adapter's bounded synchronous budget. A `404 Action not found` from the
provider's permissions-update tool does not prove the existing MCP tools
stopped working, and must not be represented as a successful permission edit.
See [MCP-first operational contract](../ops/mcp-first-agent-execution-2026-11-05.md)
for the required agent flow.

## Verification boundaries

`tests/test_claude_mcp_stdio_transport.py` covers routing, preserved limits,
idempotency, safe connection-refused fallback, and no replay after uncertain
responses. Its real loopback HTTP regression accepts a request and drops the
response: the old adapter records the effect twice; the corrected adapter once.
Fault-injection tests are not evidence of authenticated ChatGPT recovery.

Deployment must verify the installed adapter hash, reload the canonical tunnel
so its stdio child loads the new code, then submit one bounded command lasting
longer than the five-second synchronous budget through the actual connector. Verify exactly
one durable result and no duplicate execution. Keep all output free of secrets.

The platform's additional-check banner is a separate symptom. Transport
reliability evidence does not establish the cause of that banner.

## Browser session isolation

The general Remote Control Browser MCP and the ChatGPT continuity browser must
never share a graphical session, Chrome profile, or target window.

- General browser automation runs as `fredconsole` on `DISPLAY=:0`, using the
  dedicated profile `/home/fredconsole/.config/shopvivaliz-general-chromium`
  and window class `shopvivaliz-general`.
- ChatGPT continuity remains isolated as `fredrdp` on `DISPLAY=:99` with CDP on
  `127.0.0.1:9555`. General browser tools must never navigate, click, type in,
  or enumerate that window.
- The browser MCP may report ready with zero general-browser windows only when
  the desktop display is accessible and its configured browser binary is
  executable; `browser_open` then creates the dedicated general window.
- Deployment verification must open/navigate a benign page through the general
  browser MCP and prove that the CDP 9559 Dev target set is unchanged. A browser MCP
  health result alone is not sufficient evidence of isolation.

This boundary is mandatory because sharing `:99` previously allowed unrelated
Google/Tiny automation to repurpose the continuity browser and invalidate its
authenticated ChatGPT session.
