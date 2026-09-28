# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=CONCLUIDO
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=main
COMPLETION_COMMIT=fe4d56a1b7778d26937e2d12f13480a7f4c9b423
COMPLETION_RUN=36367529240
COMPLETION_RUN_NUMBER=15

## Goal
Ativar o ShopVivaliz Remote Control MCP independente de GitHub em runtime nos quatro hosts canônicos.

## Canonical implementation
- Controller/MCP: `remote-control-mcp/server.py`
- Controller endpoint: backend loopback `127.0.0.1:5580`
- Controller service: `shopvivaliz-remote-control-mcp.service`
- Linux bootstrap: `scripts/setup-remote-control-access.sh`
- Four-host bootstrap/E2E: `.github/workflows/remote-control-mcp-bootstrap.yml`
- Tests: `tests/remote-control-mcp-test.py`

## Runtime architecture
- `always-free-arm-1787907847-26`: controller executes locally as root.
- `shopvivaliz-free-a1`: private VCN SSH using the dedicated `shopvivaliz-remote` identity with administrative sudo.
- Fred-Win/LAPTOP-NIG4IFUU: canonical backend loopback reverse relay `127.0.0.1:5557`, Administrator context.
- KOCEPSV/DESKTOP-KOCEPSV: canonical backend loopback reverse relay `127.0.0.1:5558`, Administrator context.
- GitHub is bootstrap/recovery only; it is not command transport, queue, heartbeat, execution or task state at runtime.

## Security
- MCP binds only to backend loopback.
- Privileged MCP calls require a root-only bearer token generated on the backend; token contents never enter Git.
- Controller SSH identity is generated and kept on the backend.
- Production host key is pinned.
- Audit stores command hashes rather than raw commands.
- Secret-bearing file paths are denied.
- Production active releases remain immutable.

## Completion evidence

Merged implementation/recovery:
- PR #1961 — initial GitHub-independent private remote control MCP.
- PR #1962 — bootstrap workflow syntax repair.
- PR #1964 — canonical Windows transport through private loopback relays 5557/5558.
- PR #1966 — TDD fix for controller readiness race after restart.
- Main completion commit: `fe4d56a1b7778d26937e2d12f13480a7f4c9b423`.

Post-merge bootstrap run #15 (`36367529240`) completed with conclusion `success` and produced all mandatory live markers:
- `REMOTE_CONTROL_CONTROLLER_INSTALL=PASS`
- `REMOTE_CONTROL_TARGET_INSTALL=PASS`
- `REMOTE_CONTROL_READINESS=PASS`
- `REMOTE_CONTROL_CONTROLLER=ACTIVE`
- `WINDOWS_RELAY_PASS=fred-win`
- `WINDOWS_RELAY_PASS=desktop-kocepsv`
- `HOST_HEALTH_PASS=always-free-arm-1787907847-26 exit=0`
- `HOST_HEALTH_PASS=shopvivaliz-free-a1 exit=0`
- `HOST_HEALTH_PASS=Fred-Win exit=0`
- `HOST_HEALTH_PASS=KOCEPSV exit=0`
- `DURABLE_TASK_PASS=always-free-arm-1787907847-26`
- `DURABLE_TASK_PASS=shopvivaliz-free-a1`
- `DURABLE_TASK_PASS=Fred-Win`
- `DURABLE_TASK_PASS=KOCEPSV`
- `REMOTE_CONTROL_FOUR_HOST_E2E=PASS`

The E2E gate itself rejects success unless both Linux hosts execute as uid 0 and both Windows hosts report Administrator=true.

## Verification against original goal
All four canonical hosts are live through the new private control plane with administrative privilege. Durable task submission, execution and later retrieval passed on all four hosts. Runtime command transport/state is independent of GitHub and Desktop Commander.

TERMINAL_STATE=CONCLUIDO
