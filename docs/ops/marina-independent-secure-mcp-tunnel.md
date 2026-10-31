<!-- RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->
**AVISO OPERACIONAL (2026-10-29):** a VM de origem `shopvivaliz-free-a1` foi excluída conforme confirmação do proprietário. As etapas históricas abaixo que requerem **manter, acessar, monitorar, validar, interromper ou recriar** essa VM (incluindo `NO_DECOMMISSION` e critérios dos "quatro hosts") **não são instruções executáveis atuais**. A produção web está em `marina-a1-4c24g`, o backend/controller em `always-free-arm-1787907847-26`. Não substituir aliases/SSH/tenancy da origem por Marina. Preservar backups e evidência acessível, rever gates ainda pertinentes e seguir `docs/knowledge/current-topology-and-access-2026-10-29.md`. A exclusão não prova `APTO`, integração de MCP novo, paridade de dados ou passagem de E2E; `GLOBAL_MIGRATION_STATUS=NAO_APTO` enquanto houver gates pendentes.
<!-- /RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->

# Marina: independent Secure MCP Tunnel - operator gated / NO_CUT

## Evidence verified on 2026-10-20 UTC

- Direct access: marina-a1-4c24g, Ubuntu on ARM64 (aarch64). HTTPS egress to api.openai.com:443 and the official GitHub Release API succeeded. HTTP 404 on the unauthenticated root /v1/tunnel/ is an egress observation, NOT an authenticated tunnel.
- Official OpenAI tunnel-client v0.0.16 linux-arm64 was downloaded into a temporary directory on Marina, checked against its official GitHub Release SHA-256, executed for version, quickstart and doctor help, then removed. No permanent binary, credential, daemon or authenticated tunnel was installed by that test.
- Authorized read-only Platform UI probe on the backend browser observed OPENAI_TUNNEL_UI_AUTHENTICATED=false and OPENAI_TUNNEL_UI_MANAGE_AVAILABLE=false (task audit 0a0c3432-5046-48cd-a507-31a1b273d5f2). No tunnel ID, Platform role or workspace association is proven. Existing ChatGPT login does not grant Platform tunnel access. Do not guess MFA, use a different identity, or reuse an existing controller key.
- Independent read-only MCP source is on main, PR #3157 at commit 03527fc0443becfa79807cb8c8a7f61330fa1b92. Real Marina ephemeral unit/security test passed, but the MCP is not installed, is not an app and is not currently linked to ChatGPT.
- Backend's existing scripts/setup-secure-mcp-tunnel-runtime.sh targets always-free-arm-1787907847-26 and explicitly requires backend root. Do NOT copy or invoke that installer as a workaround on Marina.

## Isolation and explicit provisioning prerequisites

1. Officially authorized Marina administrator provisions locked-down no-login UNIX service user shopvivaliz-marina-mcp, with home /var/lib/shopvivaliz-marina-mcp, NO sudo, and immutable separately reviewed server/client releases under /opt/shopvivaliz-marina-mcp. The existing RDC NoNewPrivileges denial is binding; do not use OCI, GitHub runners, SSH or another MCP to bypass an expressly refused privileged action.
2. The dedicated service account owns /var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp with mode 0700 (no symlink). Separate auth-token, auth-header, runtime-key and tunnel-profile.yaml files must each be non-symlink regular files owned by that user, mode 0600. Never place secrets in process args, Git, a CI secret output, chat, logs or screenshots.
3. Generate a new random dedicated MCP token 32-256 visible ASCII bytes only through the approved secret manager. The auth-header private file contains the exact ASCII prefix "Bearer " plus that SAME token. Provision and rotate both files atomically; do not reuse the controller's MCP/RDC/API tokens. The two secrets must agree, but the read-only preflight never reads them or claims parity.
4. A Platform organization owner or RBAC admin grants Tunnels Read+Manage for creation and Tunnels Read+Use for the restricted runtime key and app operator. The unique tunnel_id must be associated with the intended ShopVivaliz ChatGPT workspace AND the correct Platform organization. That linkage, role propagation, workspace permission and ChatGPT plugin registration are official platform operations, not accomplished by code in this repo.
5. Keep server bind at 127.0.0.1:5580. The OpenAI tunnel-client polls the OpenAI HTTPS control plane from Marina and forwards locally to http://127.0.0.1:5580/mcp. Port 8080 belongs to the Storefront and must not be repurposed. Proposed independent local health port: 127.0.0.1:5590, but first check port availability.
6. The tunnel runtime API key authenticates Marina -> OpenAI. A separate local static MCP-side header authenticates tunnel-client -> Marina read-only MCP. Per official tunnel-client configuration, static header values can use file:/path; mcp.extra_headers and mcp.discovery_extra_headers must both inject Authorization from the local auth-header file. Connector-forwarded Authorization can OVERRIDE a static header; review the app auth settings and verify effective negative/positive authentication before use.
7. The staged, NOT INSTALLED unit templates are marina-direct-mcp/systemd/shopvivaliz-marina-mcp.service and marina-direct-mcp/systemd/shopvivaliz-marina-mcp-tunnel.service. The dedicated units must both run as shopvivaliz-marina-mcp with NoNewPrivileges=yes, capability escalation disabled, explicit immutable ExecStart paths, appropriate private directory ownership, restart policy and rollback. Their installation requires a separate officially authorized privileged maintenance channel; this document is not authorization.
8. The MCP provides only marina_host_health and allowlisted marina_service_status; no shell, Docker, sudo, task queue or write permission. A full mutating ShopVivaliz MCP requires its own review of ownership leases, fencing, CAS, idempotency and E2E.

## Illustrative private tunnel-profile.yaml for the approved operator

The following is a TEMPLATE with an invalid placeholder, not a production config. The operator uses the true tunnel ID from Platform in the protected private file. Replace the placeholder locally without publishing the ID or file.

    config_version: 1
    control_plane:
      tunnel_id: REPLACE_WITH_OFFICIAL_PLATFORM_TUNNEL_ID
      api_key: file:/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/runtime-key
    mcp:
      server_urls:
        - channel: main
          url: http://127.0.0.1:5580/mcp
      extra_headers:
        Authorization: file:/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/auth-header
      discovery_extra_headers:
        Authorization: file:/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/auth-header
      startup_wait_timeout: 60s
    health:
      listen_addr: 127.0.0.1:5590
    admin_ui:
      open_browser: false

As the dedicated service user, the officially authorized operator runs the installed, verified client with:

    /opt/shopvivaliz-marina-mcp/bin/tunnel-client doctor --config /var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/tunnel-profile.yaml --explain
    /opt/shopvivaliz-marina-mcp/bin/tunnel-client run --config /var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/tunnel-profile.yaml

Run the second command under the dedicated systemd tunnel unit only after approval. Do not put literal runtime keys in CLI flags or the profile. Use an appropriately signed/provenance-verified official ARM64 release; never assume the ephemeral compatibility test installed the package.

## Read-only readiness preflight

From the immutable approved code release, as the dedicated unprivileged service user:

    python3 marina-direct-mcp/operator_preflight.py
    python3 marina-direct-mcp/operator_preflight.py --require-local-ready

The first command reports sanitized metadata. The strict command exits 3 if local prerequisites fail. Neither command opens/reads secrets, starts the tunnel, writes config or invokes privileged operations. It fails on wrong host, wrong account, unsafe/symlinked secret directory/file, non-0600 mode, unreviewed client metadata, wildcard/IPv6 listener, missing inactive or disabled units and missing NoNewPrivileges. Even if local_preflight_candidate_only=true, tunnel_authenticated and chatgpt_mcp_connected remain NOT_TESTED. Never infer successful installation, legitimate ownership, or a live ChatGPT tool call from a preflight.

## Acceptance / rollback

1. In the official OpenAI Platform UI, authenticate as the intended Platform principal, provision the distinct tunnel and runtime key, and confirm the Platform org and ChatGPT workspace association; approvals are genuine, without MFA bypass.
2. Via the officially authorized Marina operator, deploy the isolated immutable release, isolated service identity, private credentials and two sandboxed systemd units. No inherited tokens, routes or processes from the controller. Keep workers and source VM unchanged.
3. Check 401 without bearer, 200 with valid private bearer, MCP initialize, tools/list and both fixed read-only tools; unsupported names and malformed requests must fail closed. Ensure only 127.0.0.1:5580 listens.
4. Run the official tunnel-client doctor and inspect 127.0.0.1:5590/healthz and /readyz in the authorized local session. Select the workspace-linked Tunnel when adding the custom MCP server in ChatGPT Plugins, then call a tool through the actual ChatGPT connector, confirm host identity, and verify reconnection/restart without central controller.
5. Migration gates remain separate: Pricing database durable volumes/backup/firewall/worker, Supabase data-transfer restrictions, source/target deltas, SAFE-T ownership, UI journey tests, certificates, Oracle cost/quotas, reboot and rollback. Do not cut DNS, restart origin writers or delete source until E2E certified.
6. If provisioning fails, stop only independently owned new MCP/tunnel services through their authorized owner, revoke dedicated credentials as appropriate, and preserve source backup, fencing, existing workloads and rollback path. NO_CUT/NAO_APTO.

Official references:
- https://developers.openai.com/api/docs/guides/secure-mcp-tunnels
- https://github.com/openai/tunnel-client/blob/master/docs/configuration.md
- https://github.com/openai/tunnel-client/releases/latest
