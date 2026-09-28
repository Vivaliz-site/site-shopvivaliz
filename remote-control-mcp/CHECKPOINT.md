# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=fix/remote-control-windows-reverse-ssh-20260927

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


## Stage checkpoints

### STAGE 1 — Merge and workflow integrity
STATUS=PASS
- PR #1969 merged.
- Bootstrap workflow corruption was detected before rollout.
- PR #1970 repaired the broken heredoc, removed duplicated E2E/evidence blocks, and added an integrity regression test.
- PR #1970 merged as `39d94fc61f4a2f51bdfaffab85583744a36f4281`.

### STAGE 2 — Linux bootstrap
STATUS=PASS
- Bootstrap run #28 started from main.
- Controller install on `always-free-arm-1787907847-26` passed.
- Privileged target identity on `shopvivaliz-free-a1` passed.
- Runtime service is installed on backend and production target bootstrap completed.

### STAGE 3 — Windows bootstrap diagnosis
STATUS=FAIL_CONFIRMED_CAUSE
- Bootstrap stopped in `Discover and bootstrap Windows peers` with exit code 124.
- Failure occurred at the first direct TCP/22 reachability probe from backend to a Windows Tailscale peer.
- Canonical repo docs prove the existing design uses reverse relays instead:
  - Fred-Win SSH reverse relay: backend `127.0.0.1:2222`.
  - Fred-Win MCP relay: backend `127.0.0.1:5557`.
  - KOCEPSV MCP relay: backend `127.0.0.1:5558`.
- Current correction branch will stop relying on direct peer TCP/22 and will establish/persist a reverse SSH path for KOCEPSV before rerunning four-host E2E.

### CHECKPOINT POLICY
- Persist this file after every completed stage before advancing.
- Do not mark CONCLUIDO until four-host privilege + durable-task E2E and non-GitHub runtime proof pass.


### STAGE 4 — Windows route design
STATUS=PASS
- Execution mode changed to one stage at a time with a persisted checkpoint after every stage.
- Fred-Win canonical reverse SSH remains backend `127.0.0.1:2222 -> 127.0.0.1:22`.
- KOCEPSV will gain backend `127.0.0.1:2223 -> 127.0.0.1:22`; repository search found no canonical conflict for port 2223.
- Existing MCP relays `5557/5558` remain bootstrap/recovery surfaces only.
- Remote Control MCP Windows runtime will use loopback reverse SSH, not direct peer TCP/22 over Tailscale.
- Detailed implementation sequence persisted in `remote-control-mcp/ACTION_PLAN.md`.


### STAGE 5 — Windows reverse-SSH implementation and tests
STATUS=PASS
- Controller runtime uses Fred-Win via backend loopback `127.0.0.1:2222` and KOCEPSV via `127.0.0.1:2223`; Windows runtime no longer discovers Tailscale peer IPs.
- KOCEPSV managed tunnel now carries both SSH `2223 -> 22` and legacy recovery MCP `5558 -> 5557`.
- KOCEPSV supervisor only accepts a live tunnel when both forwards are present and replaces an incomplete legacy tunnel.
- Bootstrap workflow uses reverse SSH for both Windows hosts; KOCEPSV legacy MCP `5558` is used only for the one-time relay upgrade when `2223` is absent.
- Windows reverse-port host keys are pinned on the backend before live MCP validation.
- Regression review found and fixed a stale Tailscale unit-test reference plus duplicated/malformed temporary-forward cleanup.
- Canonical executable validation: Remote Control MCP CI run `36370863304` completed SUCCESS at implementation commit `8756e28d88dbbd0b2ff8a9de6e2e833c6839d3c8`.
- Local container clone could not resolve `github.com`; this was not counted as a pass. GitHub CI provided the successful executable evidence.
- Next stage is PR validation/merge only. No PR was opened and no bootstrap/deploy was performed in this stage.


### ACTION STAGE 2 — Reverse SSH implementation and tests
STATUS=PASS
- Branch: `fix/remote-control-windows-reverse-ssh-20260927`.
- Runtime Windows changed from direct Tailscale peer SSH to backend-loopback reverse SSH.
- Fred-Win target: `127.0.0.1:2222`.
- KOCEPSV target: `127.0.0.1:2223`.
- KOCEPSV managed tunnel now persists SSH `2223->127.0.0.1:22` plus legacy bootstrap MCP `5558->127.0.0.1:5557`.
- Bootstrap workflow no longer probes `$fred_ip:22` / `$desk_ip:22`.
- Bootstrap uses legacy KOCEPSV MCP 5558 only to queue the one-time relay upgrade, then requires reverse SSH 2223.
- Controller known_hosts pins production plus loopback ports 2222 and 2223 before live validation.
- CI coverage extended to KOCEPSV relay scripts/contract.
- Validation PR #1974 is DRAFT only.
- Remote Control MCP CI run #36, id `36370874616`: SUCCESS.
  - Python syntax and unit tests: PASS.
  - KOCEPSV reverse relay contract: PASS.
  - Shell syntax: PASS.
- No merge, live Windows bootstrap, or four-host E2E was executed in this stage.
- Next authorized stage: ACTION STAGE 3 — validate and merge PR.
