# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=fix/remote-control-bootstrap-integrity-20260927

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
- Fred-Win/LAPTOP-NIG4IFUU: private Tailscale/OpenSSH path, Administrator context.
- KOCEPSV/DESKTOP-KOCEPSV: private Tailscale/OpenSSH path, Administrator context.
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


## Current continuation
- PR #1969 merged to main at `82e9a10aff512cfaa40a0bafc7155947e6a128f0`.
- Post-merge review found the bootstrap workflow structurally corrupted before execution; no four-host bootstrap run was registered for that merge.
- Current branch repairs the host-key pinning heredoc, removes duplicated E2E/evidence/cleanup blocks, and adds a regression test for workflow integrity.
- STATUS remains RUNNING until live bootstrap plus non-GitHub runtime proof pass.
