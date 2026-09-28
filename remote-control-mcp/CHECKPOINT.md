# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=feat/private-remote-control-mcp-20260927

## Goal
Ativar a solução ShopVivaliz Remote Control/MCP, independente de GitHub em runtime, nos quatro hosts: always-free-arm-1787907847-26, shopvivaliz-free-a1, Fred-Win/LAPTOP-NIG4IFUU e DESKTOP-KOCEPSV.

## Evidence already completed
- Canonical bootstrap docs and agent rules read.
- Four Remote Desktop Commander devices observed online before quota exhaustion.
- Remote Desktop Commander monthly remote-call quota observed at 0% remaining.
- Existing private relays confirmed in code: Fred-Win via backend loopback 5557; KOCEPSV via backend loopback 5558.
- Canonical private transport confirmed: VCN/Tailscale/OpenSSH; RustDesk is GUI fallback.
- SPEC created and privilege model upgraded for administrative capabilities.
- Controller source created at remote-control-mcp/controller.py.
- systemd installer created at remote-control-mcp/install.sh.
- four-host/durable-task E2E created at remote-control-mcp/e2e.py.

## Security constraints retained
- No public shell/control endpoint.
- Never expose secrets/credentials.
- Production current/active release remains immutable.
- Browser automation remains on backend VM, not Windows hosts.
- Administrative/root/LocalSystem privilege is allowed where needed, with audit.

## Next action
Bootstrap the controller on always-free-arm-1787907847-26 using the existing auditable path, establish/install persistent host-side runtime on all four hosts, then run E2E from the new GitHub-independent control plane: health/identity/admin privilege on all four plus detached durable task persistence and restart recovery.

## Completion gate
Do not mark CONCLUIDO until all four hosts return live evidence through the new control plane without GitHub being the command transport, and the durable task survives client disconnection/retrieval.
