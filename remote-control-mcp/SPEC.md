# ShopVivaliz Private Remote Control MCP

## Value Proposition
Provide an internal, GitHub-independent remote control plane for ShopVivaliz hosts from AI assistants. The current operational pain is that Desktop Commander has usage limits and the fallback control plane relies on GitHub Actions. The new path must keep host inspection, controlled execution, files, services and durable tasks available even when GitHub and Desktop Commander are unavailable.

**Primary user:** ShopVivaliz operator and authorized AI agents.

**Core actions:**
1. Inspect host health, processes, services, files and logs.
2. Run policy-controlled commands and service operations on a named host.
3. Submit, inspect and cancel durable tasks that continue independently of the chat session.

## Why MCP / LLM
**Conversational win:** an operator can ask for an operational outcome by host name instead of manually opening SSH/RustDesk and translating the goal into commands.

**LLM adds:** intent routing, tool selection, diagnosis across outputs, safe sequencing and summarization.

**What the LLM lacks:** authenticated private-network access, durable execution, host identity, operating-system tools, task state and audit evidence. The MCP server provides those capabilities.

## User Journey
**First view:** tools expose the canonical hosts and their live status.

**Key interactions:**
- list/select a named host;
- inspect health/process/service/file/log state;
- execute an allowlisted operation;
- submit a longer task and later query its persisted status/output;
- use RustDesk separately for graphical human fallback when needed.

**End state:** each action returns structured evidence with host, timestamp, status, exit code/result and audit ID. Long tasks end in a persisted terminal state.

## Product Context
- Canonical controller host: `always-free-arm-1787907847-26` (private `10.0.1.38`).
- Production web host: `shopvivaliz-free-a1` (private `10.0.1.112`).
- Support hosts: `Fred-Win` and `KOCEPSV`.
- Private transport: VCN/Tailscale/OpenSSH using the dedicated `shopvivaliz-agent` identity and existing restricted wrappers/relays.
- GUI fallback: self-hosted RustDesk.
- ChatGPT connects to the private MCP endpoint through Secure MCP Tunnel; the MCP service itself is not publicly exposed.
- GitHub may store source code and may be used for one-time bootstrap/recovery, but it is not part of normal command transport, task queue, heartbeat, execution or state.
- Browser automation for ShopVivaliz remains on the backend VM, not Windows hosts.

## Security Model
- No public host-control endpoint.
- No hardcoded credentials, tokens, cookies or private keys.
- Named host allowlist; never route by user-supplied arbitrary address.
- Read operations and write/execute operations are distinct tools.
- Shell execution is policy-controlled, bounded by timeout/output limits and executed as the dedicated restricted account or existing allowlisted wrappers.
- Destructive or privilege-escalating operations are denied unless a dedicated explicit tool exists.
- Every request creates an append-only audit record with redacted arguments and outcome.
- Secrets are never returned in tool output.
- File access is restricted to configured roots per host.
- Sessions/tasks have TTL and cancellation support.

## Durable Task Model
- Local SQLite database in WAL mode on the backend controller.
- States: `queued`, `running`, `succeeded`, `failed`, `cancelled`, `expired`.
- Persist: task ID, host, operation, redacted arguments, timestamps, heartbeat, exit code, bounded stdout/stderr and audit linkage.
- Worker supervision by systemd with restart-on-failure.
- A chat interruption does not stop a detached task.
- Querying task status is idempotent.

## Initial MCP Tools
- `hosts_list`
- `host_health`
- `processes_list`
- `service_status`
- `service_action` (fixed actions only)
- `file_read`
- `file_list`
- `logs_tail`
- `command_run` (policy-controlled)
- `task_submit`
- `task_status`
- `task_cancel`
- `audit_recent`

## Non-Goals for V1
- Public Internet exposure of Windows or Linux control endpoints.
- Unrestricted root shell.
- Browser execution on Fred-Win or KOCEPSV.
- Replacing RustDesk for graphical human sessions.
- Depending on GitHub Actions for normal runtime.
