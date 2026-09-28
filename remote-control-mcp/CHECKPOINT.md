# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=main (PR #1974 merged; follow-up fixes #2003/#2004/#2007 merged directly to main; KOCEPSV still unproven — see ACTION STAGE 3 below)

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

### ACTION STAGE 3 — PR #1974 merged; live Windows bootstrap still failing on KOCEPSV
STATUS=RUNNING
- PR #1974 merged to main at `1d7425a96702ec8c974230a86db0268f68391496` (2026-09-28T02:47:22Z). All 5 required checks (Remote Control MCP CI, Mandatory Validation Gate, Repository Governance, ShopVivaliz QA, Desktop Commander 24h Health) were SUCCESS on the merge head.
- Post-merge live bootstrap runs (workflow `remote-control-mcp-bootstrap.yml`) repeatedly failed on KOCEPSV only. Three follow-up diagnostic/fix PRs landed same-day, none of which updated this checkpoint file when merged (gap now closed by this entry):
  - PR #2003 `fix(remote-control): tolerate hidden KOCEPSV ssh executable path` — merged `6020d5d9...`.
  - PR #2004 `fix(remote-control): classify KOCEPSV sidecar bootstrap failures` — merged `4de07874c...`.
  - PR #2007 `fix(remote-control): classify KOCEPSV controller invocation failure` — merged `fda0aba3d...`.
- **Live re-verification performed this session (2026-09-28, after all three fixes above were already on main):** bootstrap workflow run #56 (`36374487789`, head `fda0aba3d...`, the fix from #2007 itself) — job log shows `REMOTE_CONTROL_FRED_REVERSE_SSH=PASS` printed, then the job exits with code 1 with **no** `REMOTE_CONTROL_KOCEPSV_*` marker of any kind ever printed (not `_STAGE=PASS`, not `_RELAY_UPGRADE_QUEUED=PASS`, not a Python traceback) before the failure. This means the added diagnostic classification from #2004/#2007 is still not being reached/surfaced in the log, and KOCEPSV port `2223` is confirmed, via this live run, still not reachable from the backend loopback as of this run.
- Cross-checked against `docs/knowledge/host-access.md` (updated same-day by PR #2010/#2012): explicitly documents "Fred-Win `2222` PASS recente" vs "KOCEPSV `2223` **ainda não comprovado**" — consistent with the live log finding above. No contradiction between docs and live evidence at this point.
- Attempted direct live host diagnosis via Remote Desktop Commander (all four canonical devices confirmed online: `shopvivaliz-free-a1`, `LAPTOP-NIG4IFUU`, `always-free-arm-1787907847-26`, `DESKTOP-KOCEPSV`) to check `ss -ltnp` for port 2223 on the backend directly — **blocked by RDC's monthly tool-call quota being exhausted for this account** (explicit quota-paused response, not a connectivity/auth failure; device pairing itself is intact). This is a transient external tool-quota block, not a proof that the route is broken beyond what the GitHub Actions log already shows, and not a reason to consider the task BLOCKED_EXTERNAL — only this session's ability to do additional live host-level diagnosis via RDC is currently blocked.
- Static read of `scripts/desktopkocepsv-remote-control-ssh-bridge.ps1` (current main) did not surface an obvious additional bug beyond what #2003 already fixed (ExecutablePath fallback, CommandLine-only requirement, regex-based forward substitution) — root-causing further requires live state on `DESKTOP-KOCEPSV` (is the legacy 5558 tunnel actually running right now? does the regex substitution actually produce a working ssh.exe invocation? does the new 2223 process survive past 3 seconds?), which is not obtainable this session.
- STATUS remains RUNNING. Next authorized stage: ACTION STAGE 4 — obtain live KOCEPSV host state (via Remote Desktop Commander once quota resets, or another live channel) to root-cause why `2223` still does not come up, before attempting another blind fix PR. Do not repeat the PR-without-live-verification pattern from #2003/#2004/#2007.

### ACTION STAGE 4 — Diagnostic instrumentation (PR #2014/#2015) reveals a likely zombie reverse-SSH tunnel, not a KOCEPSV-specific bug
STATUS=RUNNING
- PR #2014 (`diag(remote-control): surface why KOCEPSV port 2223 fails silently`, merged `108f7842b`) and PR #2015 (`diag(remote-control): surface which admin-key-install step fails`, merged `0344eea6f`) instrumented the bootstrap workflow's KOCEPSV block and the shared FRED/DESKTOP admin-key-install loop with explicit `REMOTE_CONTROL_KOCEPSV_*`/`REMOTE_CONTROL_ADMIN_KEY_STEP=<label>` OK/FAIL markers, plus `ss -tln`/`ss -tlnp` listener dumps captured directly on the self-hosted runner (which IS the backend host) — worked around RDC's exhausted quota.
- **Live run `36410163131`** (right after #2014 merged) proved, for the first time with real evidence, that KOCEPSV's reverse tunnel is NOT closed at the TCP level: `REMOTE_CONTROL_KOCEPSV_PRECHECK_2223=OPEN`, `ss -tln` shows a genuine `LISTEN` socket on both `127.0.0.1:2223` and `[::1]:2223`, and `REMOTE_CONTROL_KOCEPSV_REVERSE_SSH=PASS` printed. The job still failed ~2s later in the (at the time unobserved) admin-key-install loop.
- **Live run `36410984419`** (right after #2015 merged) pinpointed the actual failure precisely: it happens on the very first loop iteration, **FRED (port 2222)**, not KOCEPSV — `REMOTE_CONTROL_ADMIN_KEY_STEP=FRED FAIL at ssh-keyscan rc=1 stderr=127.0.0.1: Connection closed by remote host` (repeated 5×, one per `ssh-keyscan` retry). Fred-Win's raw TCP port 2222 was open (same `ss -tln` evidence as always), but the actual SSH protocol handshake was refused/closed instantly.
- **Working hypothesis (well-supported, not yet directly confirmed on the host):** this is the classic "zombie reverse-SSH forward" failure mode — an `ssh -R 2222:127.0.0.1:22 ...` forwarded port keeps its local listening socket bound on the backend even after the underlying master SSH connection to Fred-Win has died; a new connection to that port is accepted by the stale listener and then immediately reset, because there is no live tunnel left to relay it through. This exactly matches the observed symptom split: the bash `</dev/tcp/...>` raw-connect check (which only opens/closes a TCP socket, no protocol) always reports the port as "open", while any real SSH client (`ssh-keyscan`, `scp`, `ssh`) gets "Connection closed by remote host" instantly, with no banner exchange. If confirmed, the same is almost certainly true for KOCEPSV's port 2223 once the loop gets that far (it hasn't yet reached KOCEPSV in the two most recent live runs, because it now fails on FRED first).
- **This reframes the whole KOCEPSV investigation:** the problem was very likely never specific to KOCEPSV's PowerShell sidecar/relay scripts (the target of PRs #2001/#2003/#2004/#2007) — it is a reverse-tunnel liveness/reconnection problem affecting the shared backend-loopback-reverse-SSH architecture for *both* Windows hosts, which just happened to surface as "KOCEPSV never proven" first because the admin-key-install loop order (`'FRED 2222 FRED' 'DESKTOP 2223 user'`) previously let Fred's entry pass on stale evidence while KOCEPSV's raw-TCP precheck sometimes failed outright.
- **Not yet done, and explicitly NOT attempted this session per the instruction to propose before implementing:** no fix was pushed. Confirming the zombie-tunnel hypothesis and fixing it requires either (a) live command execution on `always-free-arm-1787907847-26` and/or `LAPTOP-NIG4IFUU`/`DESKTOP-KOCEPSV` to inspect the actual `ssh -R` master process state (e.g. is the master connection's `ServerAliveInterval`/`ExitOnForwardFailure` configured, is the process still alive, when did it last successfully relay traffic), or (b) a workflow-level change that detects a dead-but-listening forwarded port (e.g. attempt a real SSH connect, not just a raw TCP connect, before trusting `REMOTE_CONTROL_FRED_REVERSE_SSH=PASS`/`REMOTE_CONTROL_KOCEPSV_REVERSE_SSH=PASS`) and forces the managed tunnel service to reconnect. Both are live-SSH-bootstrap-affecting changes to production-adjacent Windows hosts and were flagged to the user rather than implemented unilaterally.
- Next authorized stage: ACTION STAGE 5 — confirm the zombie-tunnel hypothesis with live host state (RDC once quota resets, or another live channel) and, once confirmed, design a minimal fix (real-SSH-liveness check + reconnect trigger) with the user's sign-off before implementing, rather than another blind PowerShell patch.
