# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=fix/remote-control-windows-relays-v2-20260928

## Goal
Ativar o ShopVivaliz Remote Control MCP independente de GitHub em runtime nos quatro hosts canônicos.

## Canonical implementation
- Controller/MCP: `remote-control-mcp/server.py`
- Controller endpoint: backend loopback `127.0.0.1:5580`
- Controller service: `shopvivaliz-remote-control-mcp.service`
- Linux bootstrap: `scripts/setup-remote-control-access.sh`
- Windows bootstrap: `scripts/setup-remote-control-windows.ps1`
- Four-host bootstrap/E2E: `.github/workflows/remote-control-mcp-bootstrap.yml`
- Tests: `tests/remote-control-mcp-test.py`

## Runtime architecture
- `always-free-arm-1787907847-26`: controller executes locally as root.
- `shopvivaliz-free-a1`: private VCN SSH using the dedicated `shopvivaliz-remote` identity with administrative sudo.
- Fred-Win/LAPTOP-NIG4IFUU: existing backend loopback reverse relay `127.0.0.1:5557`, Administrator context required by E2E.
- KOCEPSV/DESKTOP-KOCEPSV: existing backend loopback reverse relay `127.0.0.1:5558`, Administrator context required by E2E.
- GitHub is bootstrap/recovery only; it is not command transport, queue, heartbeat, execution or state at runtime.

## Security
- MCP binds only to backend loopback.
- Privileged MCP calls require a root-only bearer token generated on the backend; token contents never enter Git.
- Controller SSH identity is generated and kept on the backend.
- Host keys are pinned.
- Audit stores command hashes rather than raw commands.
- Secret-bearing file paths are denied.
- Production active releases remain immutable.

## Completion gate
Do not mark CONCLUIDO until the merged bootstrap proves, through the new MCP:
1. live health on all four hosts;
2. root on both Linux hosts;
3. Administrator on both Windows hosts;
4. durable task execution and retrieval on all four hosts;
5. `REMOTE_CONTROL_FOUR_HOST_E2E=PASS`.

## Latest progress
- PR #1961 merged as `321d5a1068b9a240ed721bc317d98135b06ad109`.
- First post-merge bootstrap failed before job creation because the workflow YAML was corrupted by overlapping edits.
- Recovery branch rewrites the workflow cleanly and switches Windows runtime transport to the already-canonical private relays 5557/5558.
- Next action: merge recovery PR, run bootstrap, require four-host privilege + durable-task E2E PASS.

- Main bootstrap run #13 proved backend controller install PASS and production privileged identity PASS; it failed only at Windows direct-SSH bootstrap.
- Recovery v2 removes that failing Windows direct-SSH bootstrap and uses the already-canonical private loopback relays 5557/5558.
