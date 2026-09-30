# CHECKPOINT — Private Remote Control MCP

TASK_ID=private-remote-control-mcp-4hosts-20260927
STATUS=RUNNING
REPOSITORY=Vivaliz-site/site-shopvivaliz
BRANCH=main (REMOTE_CONTROL_FOUR_HOST_E2E=PASS achieved live for the first time — see ACTION STAGE 6 below; Stage 6 runtime independence is also PASS; remaining work is Stage 7 cloud-client integration.)

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

### ACTION STAGE 5 — Zombie-tunnel hypothesis confirmed and fixed; both Windows hosts now pass real SSH auth; new blocker is a backend controller-restart race
STATUS=RUNNING
- Between this session's ACTION STAGE 4 entry and this one, a separate session/agent landed 9 more commits directly reacting to live bootstrap failures (`f04393f32` through `e6d755b90`, none of which updated this checkpoint file — same recurring gap as before). Summary of what they found and fixed, reconstructed from `git log` and live run evidence since this file was stale again:
  - The real root cause on the Windows side was not a stale SSH master connection per se, but that **Windows OpenSSH Server (sshd) was not reliably installed/registered as a service** on at least one host — commits `0064edc18`/`bf0cb2088`/`c24ec76e6` add `scripts/windows-openssh-recovery.ps1`, which installs the `OpenSSH.Server` capability, registers `New-Service -Name sshd`, and is invoked via the existing legacy MCP relays (5557/5558) when the reverse-SSH port fails a **real SSH protocol handshake** check (not just raw TCP) — `ssh_protocol_alive()`, added to the bootstrap workflow, replaces the old `</dev/tcp/...>` raw-connect check that could never tell a live tunnel from a zombie one.
  - PR `ef3baa41e`/`e6d755b90` (referenced in commit history; corresponds to GitHub PR #2035, merged as `0c9572cc0`) fixed the next-layer problem: the bootstrap was trying to authenticate Windows hosts with the VM bootstrap's own SSH key, which was never authorized on a freshly-recovered sshd — a circular trust dependency. Fixed by staging `setup-remote-control-windows.ps1` through the already-authenticated legacy relays (5557/5558) to install the **controller's own generated public key**, then verifying with the controller's private key (`/var/lib/shopvivaliz-remote-control/id_ed25519`) over real SSH 2222/2223.
- **Live re-verification performed this session (2026-09-28, ~15:06 UTC), two consecutive runs (`36436982503` from PR #2035's merge, and `36441085607` from a fresh manual `workflow_dispatch` I triggered on current main tip `40419f1ba`):** both runs show, reliably and reproducibly:
  ```
  REMOTE_CONTROL_KOCEPSV_PRECHECK_2223=SSH_OK
  REMOTE_CONTROL_KOCEPSV_REVERSE_SSH=PASS
  REMOTE_CONTROL_ADMIN_KEY_STEP=FRED relay bootstrap install OK
  REMOTE_CONTROL_ADMIN_KEY_STEP=DESKTOP relay bootstrap install OK
  REMOTE_CONTROL_ADMIN_KEY_STEP=FRED ssh-keyscan OK (3 host key line(s))
  REMOTE_CONTROL_ADMIN_KEY_STEP=FRED controller-key auth OK
  REMOTE_CONTROL_ADMIN_KEY_STEP=DESKTOP ssh-keyscan OK (3 host key line(s))
  REMOTE_CONTROL_ADMIN_KEY_STEP=DESKTOP controller-key auth OK
  ```
  **Both Fred-Win and KOCEPSV now have live, real SSH connectivity AND the controller's own key successfully authenticates on both.** The zombie-reverse-SSH-tunnel hypothesis from the prior entry is effectively confirmed and resolved by the `ssh_protocol_alive()` + sshd-recovery + key-bootstrap-via-relay work above. This is genuinely new, substantial progress — the Windows bootstrap problem that blocked this task since 2026-09-27 is no longer the blocker.
- **New blocker found and fixed this session:** both runs fail one step later, in "Pin private host keys for controller" — `sudo -n systemctl restart shopvivaliz-remote-control-mcp.service` followed immediately by `curl -fsS http://127.0.0.1:5580/health`, with zero wait. `systemctl is-active --quiet` only proves the process forked (`Type=simple`, no readiness signal to systemd), not that it finished binding port 5580, so the curl routinely hits `Couldn't connect to server` (0ms, connection refused). The exact same race is already defended against elsewhere: `scripts/setup-remote-control-access.sh`'s `install_controller()` has `sleep 2` before its own equivalent check; this second restart site (added later, to pick up freshly pinned host keys) never got the same treatment. **PR #2038 opened, all 6 CI checks green (avoided `|| true`/`set +e` per `tests/remote-control-mcp-test.py::test_bootstrap_surfaces_do_not_discard_failures` by using explicit `if ! cmd; then ...; fi` guards), and merged to main as `2538517acf91963ca79e4694556d8db3c771335c`.**
- Next authorized stage: ACTION STAGE 6 — re-run the bootstrap live on current main (now containing PR #2038) and confirm the controller health check now passes reliably. If it does, the "Pin private host keys for controller" step should complete and the workflow should reach "Four-host live MCP health validation" (currently `skipped` because it requires `workflow_dispatch` with `run_e2e: true` explicitly) — that stage should be attempted next once the controller-restart fix is confirmed live.

### ACTION STAGE 6 — REMOTE_CONTROL_FOUR_HOST_E2E=PASS achieved live for the first time; all five completion-gate items confirmed
STATUS=PASS
- Re-ran the bootstrap live with `run_e2e=true` on main containing PR #2038 (run `36442818366`). Controller health check now passed reliably (`REMOTE_CONTROL_CONTROLLER_HEALTH=PASS attempt=2`, confirming PR #2038's fix works in practice), but "Four-host live MCP health validation" itself failed for the first time it was ever reached: `host_health` on Fred-Win crashed with `'utf-8' codec can't decode byte 0xa2 in position 268: invalid start byte`. Root cause: `run_host_command()` in `remote-control-mcp/server.py` used `subprocess.run(..., text=True)`, which decodes SSH/PowerShell output as strict UTF-8; non-interactive Windows PowerShell commonly emits legacy OEM/ANSI codepage bytes, not UTF-8. Fixed via PR #2041 (merged `7f38bed74`): capture raw bytes, decode with `errors="replace"`.
- Re-ran again (run `36447520346`): `host_health` now passed on all four hosts for the first time ever. The very next step, the async durable-task path (`task_submit`/`task_status`, executed by `task_worker()`), hit the **same bug in a second code path** — `subprocess.Popen(..., text=True, ...)` in `task_worker()` was a separate call site from `run_host_command()`, missed by the first fix. Fred-Win's durable task ended in state `failed` with the decode error as `stderr`. Opened PR #2043 with the same fix; concurrently, another session/agent found and fixed the identical bug independently via PR #2042 (merged `494ee0817` first). Verified #2042's fix was equivalent and adequate, closed #2043 as superseded without merging (per the standing rule against duplicate/conflicting work).
- **Re-ran a third time on main with both fixes present (run `36449123839`, run_attempt 2, completed 2026-09-28T17:18:02Z):** full live evidence, first time ever reached:
  ```
  HOST_HEALTH_PASS=always-free-arm-1787907847-26 exit=0   (uid=0 confirmed)
  HOST_HEALTH_PASS=shopvivaliz-free-a1 exit=0               (uid=0 confirmed)
  HOST_HEALTH_PASS=Fred-Win exit=0                           (administrator:true confirmed)
  HOST_HEALTH_PASS=KOCEPSV exit=0                            (administrator:true confirmed)
  DURABLE_TASK_PASS=always-free-arm-1787907847-26
  DURABLE_TASK_PASS=shopvivaliz-free-a1
  DURABLE_TASK_PASS=Fred-Win
  DURABLE_TASK_PASS=KOCEPSV
  REMOTE_CONTROL_FOUR_HOST_E2E=PASS
  ```
  This satisfies all five items of the Completion gate above: (1) live health on all four hosts, (2) root on both Linux hosts, (3) Administrator on both Windows hosts, (4) durable task execution and retrieval on all four hosts, (5) `REMOTE_CONTROL_FOUR_HOST_E2E=PASS`.
- Job also confirmed `runtime_github_dependency=false` in the persisted bootstrap evidence artifact — the controller call path (`host_health`/`task_submit`/`task_status`) executes entirely via the SSH client on the backend host talking to `127.0.0.1:5580`, never touching the GitHub API at runtime; GitHub Actions is only the execution shell this session uses to trigger and observe the run, not part of the MCP's own runtime transport.
- STATUS remains RUNNING at the task level (not CONCLUIDO) because `remote-control-mcp/ACTION_PLAN.md` Etapa 6 (formal proof/documentation of the non-GitHub-runtime property, distinct from the architectural fact already evidenced above) and Etapa 7 (ChatGPT integration) are still PENDING with no work done.
- Next authorized stage: ACTION STAGE 7 — scope and execute Etapa 6 (decide what additional evidence, if any, is needed beyond the `runtime_github_dependency=false` artifact field already produced) and Etapa 7 (ChatGPT integration — blocked from this session by a platform-level "External Ingress Tunnel" denial when attempting to build a public Cloudflare Tunnel + Access in front of the loopback-only controller; requires either the user provisioning the public endpoint themselves, or a ChatGPT client running on the same private network as the backend). Per the task's standing evidence rule, PR #2038 being merged is not itself proof the fix works in practice — a fresh live run is still required.

### ACTION STAGE 7 — Runtime independence formally reconciled; cloud-client work remains live-gated
STATUS=RUNNING
- **Stage 6 is PASS.** Run `36449123839` persisted `runtime_github_dependency=false` while calling the backend controller through `127.0.0.1:5580`. Independent disconnected-client evidence in issues #2045/#2046 recorded `DURABLE_AFTER_DISCONNECT=PASS` and `RUNTIME_GITHUB_DEPENDENCY=false`. These prove GitHub is neither transport, queue, heartbeat, executor nor state store at runtime.
- ChatGPT is BLOCKED_EXTERNAL: OpenAI Platform authentication was proven in run `36472954776`, but tunnel management was unavailable; the later `36491312473` retry found the current Platform browser session unauthenticated. Secure MCP Tunnel is the supported OpenAI path for a private MCP server, so no bearer-only publication was attempted.
- Cloudflare Access is BLOCKED_EXTERNAL: API probe `36473749120` proved the protected token cannot read Access Apps or Service Tokens. Dashboard fallback run `36492154682` was unauthenticated and required an interactive challenge. Managed OAuth cannot be safely created until a Cloudflare administrator completes login and grants Access Apps/Policies permission or creates the application manually.
- Claude server-side bridge work is complete: post-merge run `36493201912` proved bridge install, non-secret MCP configuration, and `tools/list` through the root-only local adapter. Workspace trust for the canonical prepared repository is authorized. PRs #2162/#2163 added a bounded allowlisted PTY confirmation and persistence window, but live install runs `36505458895`, `36505902804` and `36506225110` still failed in the trust substep. Claude remains RUNNING; next is safe diagnostic refinement, then install/status and real cloud-session validation.
- No `.mcp.json` remote configuration was committed because no secure public OAuth MCP endpoint exists. The controller remains loopback-only and its bearer never reaches Claude config, Cloudflare, or source control.


### ACTION STAGE 7A — OpenAI Platform auth repaired; Secure MCP Tunnel now gated at Manage/enablement
STATUS=RUNNING
DATE_UTC=2026-09-30

- A ausência de mensagem no celular foi explicada por evidência live, não por suposição. Antes do fix final, o fluxo nunca alcançava Google MFA.
- PR #2365 (merge `8fbcabeca387cc0820b784d716d79f41a9988868`) adicionou suporte a popup Google e espera humana limitada para MFA. RED run `36656882195`; PR gates verdes.
- PR #2371 (merge `e017f58bf37fdaa4d5bf8c3d515195302d324330`) adicionou diagnóstico sanitizado de localização/classe do bloqueio. Run `36657522330` mostrou:
  ```
  OPENAI_PLATFORM_POST_GOOGLE_LOCATION=openai_auth
  OPENAI_PLATFORM_FINAL_LOCATION=login
  OPENAI_PLATFORM_AUTH_BLOCKER=login
  OPENAI_PLATFORM_AUTHENTICATED=false
  ```
  Isso provou que o helper interrompia o OAuth no OpenAI Auth e voltava ao target cedo demais.
- PR #2376 (merge `1fde558a614c28bab21e1565fdd552dcc162e6cc`) substituiu esse retorno prematuro por waits limitados de handoff/conclusão OAuth. RED run `36657671418` falhou exatamente pela ausência desses waits; Remote Control MCP CI e gates do PR passaram após o GREEN.
- Fresh auth run `36657864181`, current-main:
  ```
  OPENAI_PLATFORM_AUTH_STAGE=already_authenticated
  OPENAI_PLATFORM_AUTHENTICATED=true
  OPENAI_PLATFORM_AUTH_BLOCKER=none
  OPENAI_PLATFORM_AUTH_RESULT=PASS
  SECURE_MCP_PLATFORM_AUTH=PASS
  ```
  Nenhum MFA foi solicitado porque a sessão já estava autenticada.
- Fresh tunnel UI probe `36657955719`:
  ```
  OPENAI_TUNNEL_UI_AUTHENTICATED=true
  OPENAI_TUNNEL_UI_MANAGE_AVAILABLE=false
  OPENAI_TUNNEL_UI_ACCESS_REQUIRED=false
  OPENAI_TUNNEL_UI_EXISTING_COUNT=0
  OPENAI_TUNNEL_UI_PROBE=PASS
  SECURE_MCP_PLATFORM_DIRECT_PROBE=PASS
  ```
- Interpretação conservadora: autenticação OpenAI não é mais blocker. O gate atual é a capacidade de criar/gerenciar o primeiro Secure MCP Tunnel (RBAC `Manage` e/ou habilitação da superfície). Como `ACCESS_REQUIRED=false`, não declarar ausência total de Read; como `MANAGE_AVAILABLE=false`, não declarar Manage.
- Evidência oficial vigente: criação/edição exige `Tunnels Read + Manage`; execução/seleção exige `Tunnels Read + Use`; permissões são da organização Platform e podem ser concedidas por owner/RBAC admin. Não usar endpoint público bearer-only como atalho.
- Claude avançou em paralelo: run `36654114288` / job `109694566519` emitiu `CLAUDE_REMOTE_CONTROL_INSTALL=PASS` e `OCI_MCP_CLAUDE_INSTALL=PASS`. Isso não substitui a exigência de STATUS + prova real da sessão cloud.
- Completion gate da Stage 7 permanece: (a) Secure MCP Tunnel criado/associado, client privado saudável e `initialize/tools/list` provados pelo caminho OpenAI; (b) ChatGPT workspace consegue descobrir/usar o tunnel; (c) Claude STATUS PASS e sessão Remote Control cloud real comprovada.
