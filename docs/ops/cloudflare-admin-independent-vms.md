# Independent Cloudflare administrative tunnels for ShopVivaliz Oracle VMs

**Status: STAGED ONLY / NOT DEPLOYED / NO_CUT.** This document prepares a deployment. It is not authorization to create Cloudflare resources, escalate Linux privilege or cut over production.

## Verified backend provisioning after owner authorization (2026-10-26)

- Backend `always-free-arm-1787907847-26`, UID 0: installed official `cloudflared` release **2026.10.4** into `/opt/shopvivaliz-cloudflared-admin/releases/2026.10.4/cloudflared`; binary is root-owned 0755. SHA256 **2b7203b4fb0ba28a0c1e12f8855ce2c25f76d266a8d5edafac5540b6812acff6**, compared against the official GitHub Cloudflare release at https://github.com/cloudflare/cloudflared/releases/tag/2026.10.4. `cloudflared version` returned 2026.10.4. This is a verified **binary installation**, NOT a connected tunnel.
- Created `/etc/systemd/system/shopvivaliz-cloudflared-admin.service` in the backend, using the reviewed template at `ops/cloudflare-admin/shopvivaliz-cloudflared-admin.service`. Uses `DynamicUser=yes`, `NoNewPrivileges=yes`, `LoadCredential` and `--token-file %d/tunnel-token`; does not put tokens on the command line. `systemd-analyze verify` exited 0; `systemd-analyze security` reported **3.2 OK**. The service is **loaded, disabled and inactive** by design.
- **Two real missing gates:** `/etc/shopvivaliz/cloudflare-admin/token` and `/etc/shopvivaliz/cloudflare-admin/access-approved` are ABSENT. The service must stay disabled/inactive until an approved operator authenticates to Cloudflare, configures a restrictive Access policy and safely provisions that host's unique token. Do NOT create the approval marker before this is confirmed. No external hostname, Access app, tunnel, or route has been created.
- Latest backend root filesystem check: **97% used / about 2.9 GiB free**. The pinned binary was installed successfully, but disk headroom needs to be monitored before enabling persistent logging. No cleanup was attempted.
- Both existing MCP transport and existing Cloudflare/Solange workloads remained untouched. Marina's express privileged maintenance refusal remains binding; no attempt was made to bypass it.

## Objective and identity separation

Each active VM should run its **own** Cloudflare Tunnel connector with a distinct remotely managed tunnel ID, distinct token, and separate Cloudflare Access policy/application. Marina must not depend on the backend for SSH forwarding, command execution, heartbeats or tasks. Do not reuse credentials from existing Cloudflare Returns/Solange or OpenAI Secure MCP tunnels.

| VM | Role | Proposed hostname (not yet created) | Gate |
| --- | --- | --- | --- |
| always-free-arm-1787907847-26 | Backend/controller | ssh-backend.shopvivaliz.com.br | Authorized host root reachable; Cloudflare Access grant still needed |
| marina-a1-4c24g | Separate OCI ESPOSA tenancy | ssh-marina.shopvivaliz.com.br | Direct unprivileged RDC works; official host maintenance authorization required |
| shopvivaliz-free-a1 | Previous production origin | ssh-origem.shopvivaliz.com.br | Latest OCI inventory: STOPPED; do not restart without writer/timer fencing and rollback plan |

Names are proposals only. Verify exact Cloudflare zone, DNS conflicts, account ownership and authorization before creation. Keep inbound Internet TCP/22 disabled in OCI security rules. A locally authorized connector may forward SSH to ssh://127.0.0.1:22 if the SSH daemon and policies are confirmed.

## Supported Cloudflare access architectures

- **Simpler:** Cloudflare Tunnel + Cloudflare Access SSH application + client-side cloudflared. Access authenticates the user, and SSH still enforces its own keys and UNIX permissions. This mode must not be represented as Cloudflare command logging.
- **More granular:** Cloudflare Access for Infrastructure with a Cloudflare One Client/Gateway on-ramp, short-lived SSH certificates and optional command logs, subject to account entitlement and approved log retention.
- **Neither grants sudo.** Privilege elevation is exclusively governed by a genuinely authorized host owner. Require named SSH identities, restricted sudo command allowlists, identity policy and audit. Never deploy root/public SSH or NOPASSWD: ALL. A Cloudflare tunnel must not be used to bypass an explicit privileged maintenance refusal.

Official Cloudflare documentation:
- https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/use-cases/ssh/
- https://developers.cloudflare.com/cloudflare-one/access-controls/applications/non-http/
- https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/configure-tunnels/remote-tunnel-permissions/

## Operator-gated provisioning order

1. **Preserve migration state.** Origin is stopped in latest OCI inventory; never boot it without certified fencing for old workers/timers and approved migration rollback gate. No production DNS cutover.
2. **Verify official Cloudflare authorization.** The file C:\Users\FRED\.cloudflare\api-token.txt was confirmed to exist, but neither its token content nor scope was verified. Prior tool safety refusal to use it is binding: never use another tool, script or Actions runner to circumvent a refusal. The backend Cloudflare GUI required an official login/MFA challenge; resolve it via the legitimate account operator.
3. **Access policy before reachability.** Configure deny-by-default Access/MFA restrictions for named administrators before creating SSH public hostnames or routing traffic. Reject unknown identities and service-to-service requests not explicitly allowed.
4. **Create separated tunnels.** Through the approved Cloudflare UI/identity, create one new remotely managed tunnel per VM. Do not reuse Returns/Solange/cloudflared tokens. Deliver each tunnel token only to that host through an authorized secret provisioning channel; never echo, paste or log it.
5. **Service isolation.** On a host where an operator has genuinely authorized installation rights, install the provenance-verified ARM64 cloudflared package, protected credential at /etc/shopvivaliz/cloudflare-admin/token (regular non-symlink file, private 0400/0600), and a dedicated unprivileged systemd unit shopvivaliz-cloudflared-admin.service using the token via file, not process arguments. The cloudflared service user must NOT have sudo, and NoNewPrivileges=yes should remain enabled. Leave all existing services and ports intact. Do not start before the cloud identity policy is verified.
6. **Read-only readiness probe.** Run scripts/ops/cloudflare-admin-preflight.sh locally per host. It does not read the token, execute sudo or create anything. LOCAL_PREFLIGHT=CANDIDATE_ONLY is not end-to-end evidence. The STOPPED origin cannot be probed in guest Linux.
7. **E2E gates.** From a separately authenticated client, show Access denial for an unauthorized identity, Access MFA for an authorized one, SSH key enforcement, the expected host identity, absence of public SSH exposure, an explicitly permitted harmless sudo operation for an actually approved user, and denial of sudo for unapproved users. Verify tunnel process restart, dedicated credentials, and that Marina's path does not use the backend. Audit successful/failed attempts. Do not claim command logs if the plan/mode does not provide them.
8. **Rollback.** Only authorized operators may disable/revoke each newly created standalone service, token and policy. Never disturb existing tunnel workloads, origin fencing, data, or production DNS. Keep NO_CUT/NAO_APTO until all requested active VMs pass E2E.

## Grounded blockers at 2026-10-26

- PRINCIPAL OCI CLI DEFAULT authenticated successfully on Fred-Win. OCI Resource Search showed backend RUNNING, origin STOPPED. OCI_BOOTSTRAP temporary Windows session expired, and no separate local ESPOSA profile was in the Windows config; independent historical ESPOSA GitHub Actions proof does not make that session current.
- Direct Marina RDC (without backend hop): aarch64, ubuntu (UID 1001), ssh.service active, NoNewPrivileges=1 for that session, sudo -n unavailable, cloudflared not installed and no standalone tunnel unit. This is NOT a host-admin authorization. The official existing refusal must not be bypassed via another connector, OCI, GitHub, SSH or Cloudflare.
- Backend Remote Control MCP is operational as root. Existing solange-demo-tunnel container has cloudflared; this does not establish a dedicated backend administrator tunnel.
- The official backend Cloudflare UI probe showed an authentication challenge and no usable Access admin UI session. No new tunnel was created, and no secret was accessed, copied or exposed.

## Existing safeguards and project links

- docs/knowledge/host-access.md and docs/knowledge/agent-rules.md
- docs/ops/marina-independent-secure-mcp-tunnel.md (independent OPENAI MCP design; NOT the same as Cloudflare SSH)
- https://github.com/Vivaliz-site/site-shopvivaliz/issues/3156

**Acceptance:** a verified distinct Cloudflare connector/Tunnel ID on each authorized VM, active restrictive Access policies, host sudo capability verified through official ownership, successful and negative client E2E tests, and no dependency on controller for Marina. A green local preflight does not qualify.
