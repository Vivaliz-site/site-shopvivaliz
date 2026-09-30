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

## Privilege Model
- The controller/agent daemon runs with administrative OS privilege: root on Linux and LocalSystem/Administrator on Windows.
- Administrative privilege is intentional so the replacement is not constrained by the current minimal sudo allowlist.
- The MCP client never receives a reusable OS credential; authorization is enforced before privileged execution.
- V1 supports privileged service/process/package/network/container/filesystem operations needed for administration and recovery.
- Arbitrary privileged command execution is available only through an explicitly marked administrative tool and is always audited, bounded and redacted.
- Privilege does not authorize credential theft, secret disclosure, authentication bypass, disabling audit, or bypassing platform/repository protections.
- Production immutability remains mandatory: privileged access must not edit the active `current/` release directly.

## Security Model
- No public host-control endpoint.
- No hardcoded credentials, tokens, cookies or private keys.
- Named host allowlist; never route by user-supplied arbitrary address.
- Read operations and write/execute operations are distinct tools.
- Shell execution is policy-controlled and bounded by timeout/output limits. The daemon may execute with root/LocalSystem privilege when the requested administrative capability requires it; ordinary operations should use the least privilege that still completes the task.
- High-impact destructive operations require a dedicated explicit tool or policy rule; elevation itself is not treated as an error because administrative recovery is a core capability.
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
- Credential/secret extraction, audit disabling, authentication bypass, or direct mutation of immutable production releases.
- Browser execution on Fred-Win or KOCEPSV.
- Replacing RustDesk for graphical human sessions.
- Depending on GitHub Actions for normal runtime.


## UX Flows and API Design

### Inspect and repair a host
1. List canonical hosts.
2. Inspect live health, services, processes, filesystem or logs.
3. Run a privileged administrative action when remediation is needed.
4. Return structured evidence and audit ID.

Tools: `hosts_list`, `host_health`, `processes_list`, `service_status`, `service_action`, `file_read`, `file_list`, `logs_tail`, `admin_command_run`.

### Run a durable privileged task
1. Submit an operation for a named host.
2. Worker executes independently of the chat and persists heartbeat/output.
3. Query or cancel by task ID.
4. Return the persisted terminal state and audit evidence.

Tools: `task_submit`, `task_status`, `task_cancel`.

### Review control-plane activity
1. Request recent audit events.
2. Inspect redacted inputs, host, actor, status and timestamps.

Tool: `audit_recent`.

No custom UI is required in V1; these are tool-only conversational flows.


## Bootstrap Integrity
- The bootstrap workflow must contain exactly one four-host live validation sequence.
- Host-key pinning must complete before any live MCP validation begins.
- CI must regression-test the bootstrap structure so a malformed heredoc or duplicated E2E block cannot merge as a false green.


## Windows Reverse SSH Transport
- Fred-Win runtime transport is backend loopback SSH `127.0.0.1:2222 -> Fred-Win 127.0.0.1:22`.
- KOCEPSV runtime transport is backend loopback SSH `127.0.0.1:2223 -> KOCEPSV 127.0.0.1:22`.
- Direct controller-to-Windows TCP/22 over Tailscale is not part of the Remote Control MCP runtime.
- Existing loopback MCP relays remain recovery/bootstrap only: Fred-Win `5557`, KOCEPSV `5558`.
- KOCEPSV's managed tunnel must persist both `-R 2223:127.0.0.1:22` and `-R 5558:127.0.0.1:5557`.
- Host keys for both reverse SSH ports are pinned in the controller known_hosts before live MCP validation.


## Staged Bootstrap Execution
- Stage 4 Windows bootstrap must be able to recover KOCEPSV even when its local repository branch is divergent or dirty.
- The legacy KOCEPSV MCP relay on backend loopback `5558` may stage only the two canonical relay scripts directly from `origin/main`; it must not require a full local branch merge.
- Script staging is synchronous and must emit `REMOTE_CONTROL_KOCEPSV_STAGE=PASS` before the relay restart is queued.
- The relay restart may be asynchronous because restarting the tunnel intentionally drops the legacy `5558` request path.
- A normal `push` bootstrap performs installation, Windows bootstrap and controller host-key pinning only.
- Stage 5 four-host health/durable-task E2E runs only from an explicit `workflow_dispatch` with `run_e2e=true`.
