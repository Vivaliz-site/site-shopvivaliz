<!-- RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->
**AVISO OPERACIONAL (2026-10-29):** a VM de origem `shopvivaliz-free-a1` foi excluída conforme confirmação do proprietário. As etapas históricas abaixo que requerem **manter, acessar, monitorar, validar, interromper ou recriar** essa VM (incluindo `NO_DECOMMISSION` e critérios dos "quatro hosts") **não são instruções executáveis atuais**. A produção web está em `marina-a1-4c24g`, o backend/controller em `always-free-arm-1787907847-26`. Não substituir aliases/SSH/tenancy da origem por Marina. Preservar backups e evidência acessível, rever gates ainda pertinentes e seguir `docs/knowledge/current-topology-and-access-2026-10-29.md`. A exclusão não prova `APTO`, integração de MCP novo, paridade de dados ou passagem de E2E; `GLOBAL_MIGRATION_STATUS=NAO_APTO` enquanto houver gates pendentes.
<!-- /RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->

# Marina independent MCP (read-only staging only)

**Status: source/staging candidate only. NOT INSTALLED, NOT CONNECTED, NOT AUTHORIZED FOR PRODUCTION.**
Tracking: Vivaliz-site/site-shopvivaliz issues #3152 and #3156. The working direct
ChatGPT route remains the separately connected Remote Desktop Commander.


## Cobertura administrativa em todos os projetos (nova exigencia 2026-11-01)

O proprietario exige que a experiencia MCP recupere as **rotinas administrativas historicas de todos os 11 repositorios**, nao apenas o restart Olist. Ver especificacao `docs/superpowers/specs/2026-11-01-mcp-admin-cross-repository-parity.md`, plano e inventario `docs/ops/mcp-admin-repositories-2026-11-01.json`.

A auditoria GitHub `docs/ops/mcp-admin-workflow-source-audit-2026-11-01.json` registrou **345 caminhos de workflows com state active na API que nao estao na arvore main**, sem prova de execucao; nenhum e ativado automaticamente.


**Limite desta release:** este servidor permanece read-only, isolado em Marina e NAO esta conectado ao ChatGPT. O inventario lista candidatos, nao executores autorizados ou ferramentas disponiveis. A cobertura global usa MCPs separados por host e ChatGPT como superficie de orquestracao; nao usa backend/RDC como relay da Marina. Ferramentas administrativas so serao anunciadas e acionadas depois de grant especifico, autorizacao real do host e do provedor, lease/fence/auditoria e E2E.

## Security contract

* Runs **only on marina-a1-4c24g**; refuses a different hostname.
* Binds **127.0.0.1:5580 only**. Neither IPv4 nor IPv6 wildcard bindings, no public
  NSG/security-list opening, and no reuse of the existing ShopVivaliz controller
  as a command relay.
* Bearer token MUST be a dedicated Marina secret file owned by the service UID,
  a regular file with mode **0600**; no copy/reuse of central MCP, OCI, RDC,
  Cloudflare or SSH credentials. No token in logs, process arguments, Git or chat.
* Supports only MCP initialize, ping, discovery and two strictly read-only
  tools: host identity and a fixed systemd service status allowlist. No shell,
  sudo, Docker access, SQL changes, writes, service actions or durable tasks.
* Rejects unauthenticated callers, client-supplied Origin, unknown tools,
  unexpected services, oversized requests and host identity mismatch.
* Security is intentionally fail-closed. This staging server is NOT a replacement
  for the full ShopVivaliz Remote Control MCP; it does not offer state mutation,
  ownership leases, CAS or fencing because it has no mutation surface.

## Validation without deployment

```sh
python3 -m py_compile marina-direct-mcp/server.py tests/test_marina_direct_mcp.py
python3 tests/test_marina_direct_mcp.py
```

There is **no install or deploy action in CI**. The dedicated
`marina-direct-mcp-ci.yml` workflow runs only static/unit validation, and
these paths are intentionally outside the existing
`remote-control-mcp-bootstrap.yml` push filters to avoid the legacy
four-host bootstrap.

## Operator-only, isolated Secure MCP Tunnel readiness

See [the Marina independent tunnel runbook](../docs/ops/marina-independent-secure-mcp-tunnel.md)
for Platform organization/workspace association, independent runtime-key and local
MCP bearer header, verified ARM64 client distribution, the dedicated service
identity, fail-closed local preflight, ChatGPT connection, and rollback.

Run the non-mutating metadata probe with
`python3 marina-direct-mcp/operator_preflight.py`. A successful probe NEVER
certifies tunnel authentication, ChatGPT tool connectivity, Pricing readiness,
or migration cutover. Do not reuse the backend tunnel installer or credentials.

## Official future provisioning steps (operator-gated)

1. Review source/CI and obtain explicit, **effective** permission from the
   platform and the Marina host administration channel for installation.
   The existing RDC `NoNewPrivileges=yes` refusal is binding: do not use
   GitHub Actions, OCI Run Command, another SSH account, tunnel or MCP to evade it.
2. In that officially authorized channel, provision a **new per-Marina token**
   into an isolated file with mode `0600`, owned by a dedicated unprivileged
   service identity. Install a vetted release under a separate immutable path
   and unit with `NoNewPrivileges=yes`, no ambient capabilities and
   `ExecStart=python3 .../server.py --token-file ...`. Audit unit sandboxing,
   filesystem permissions and restart persistence, with rollback.
3. Connect `127.0.0.1:5580` **only** through its own outgoing authenticated
   HTTPS tunnel (e.g., a separately provisioned Cloudflare Zero Trust identity
   and Access policy). Require independent edge authorization in addition to
   the server bearer token. Do not publish port 5580 or repurpose website 443.
4. Register the **separate** remote MCP endpoint in the authorized ChatGPT
   workspace using its official configuration UI. Validate the actual app auth
   contract, tools discovery, negative tests without tokens, live host identity,
   reconnection after controlled restart, and no controller dependency.
5. Keep mutation/privileged tools **disabled** until individually authorized,
   implemented with audited ownership/leases/fencing, and certified E2E.
   Validate the migration's independent UI, DB/worker, reboot and rollback
   gates before any DNS cutover or deletion of the source VM.

Do **not** claim successful installation or ChatGPT connectivity from passing
the unit tests or from the working RDC connection. Pricing/SAFE-T ownership,
database volumes, backups, security groups and certificates remain independent
migration gates.


## Official operator-only staging helper (not deployment)

A fail-closed helper, `operator_stage_service.sh`, stages reviewed source and systemd unit files
only. It never downloads/installs the tunnel-client, generates or reads
credentials, registers a ChatGPT plugin, runs a tunnel, enables/starts a unit,
changes DNS, or modifies workers. It does not provide shell or sudo tools.

This is **not** permission to bypass a refused `NoNewPrivileges` or RBAC action.
An independently authorized Marina host administrator must first obtain the
legitimate privileged maintenance capability, then use a **root-owned clean
checkout of a reviewed SHA**, placed beneath an entirely root-owned, non-writable
ancestor chain (for example a protected root-owned staging tree). A checkout
under an ubuntu-owned `/home/ubuntu` parent is not safe for privileged staging
because that parent can rename the checked directory between verification and copy. The `.git` metadata MUST be a root-owned, non-group/other-writable directory (not an external worktree pointer); `.git/config` MUST be a root-owned, non-writable regular file. The helper runs Git with a clean environment, fixed executable path, and `core.fsmonitor=false`/hooks disabled, because `git status` could otherwise execute a repository-configured fsmonitor hook with operator privileges. These protections only authenticate local staging inputs; they do not substitute for a signed or reviewed release. Do not launch it via RDC, SSH, OCI Run Command,
GitHub Actions or another MCP to evade an express privilege denial.

On the *actual Marina* through that officially authorized operator context:

```sh
# Inspect is non-mutating and requires the Marina hostname.
bash marina-direct-mcp/operator_stage_service.sh --inspect

# Stage only from an approved, clean, root-owned checkout. No activation.
export SHOPVIVALIZ_MARINA_APPROVED_SHA=<reviewed-40-character-main-commit>
bash marina-direct-mcp/operator_stage_service.sh --stage
```

Staging refuses wrong host, wrong UID, `NoNewPrivs: 1`, unknown commit,
dirty/non-root-owned sources, unexpected symlinks, conflicting units or
another active release. It also refuses to shadow a preexisting disabled
vendor/runtime systemd unit and refuses any effective systemd drop-in. A
disabled unit is not an empty slot: after daemon-reload, an /etc unit could
silently replace it, and drop-ins could alter the approved service contract.
Idempotent restaging accepts only an exact matching /etc unit with no drop-ins;
otherwise an authorized host operator must reconcile the conflict separately.
It holds a host-local stage lock and fails closed
instead of restarting any running daemon. A successful output is
`MARINA_MCP_STAGE=CODE_AND_UNITS_ONLY`, **not** `APTO`.

The approved operator must still provision a separate service identity's
secrets and a verified official ARM64 tunnel-client; use
[the independent Secure MCP Tunnel runbook](../docs/ops/marina-independent-secure-mcp-tunnel.md)
for permissions, workspace association, protected runtime key, authentication
tests and actual ChatGPT connector verification. An admin-capable mutation
surface additionally requires RBAC, maintenance leases, fencing, CAS,
idempotency and real E2E validation. No `sudo` is granted by a tunnel.
