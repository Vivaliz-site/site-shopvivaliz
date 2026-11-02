<!-- MARINA_CLOUDFLARE_EXISTING_TUNNEL_ROUTE_20261102 -->
> **Instrucao atualizada do proprietario (2026-11-02, prevalece sobre instrucoes anteriores de OpenAI Secure MCP Tunnel para Marina):** o **MCP independente de `marina-a1-4c24g` usara o Cloudflare Tunnel JA EXISTENTE nesta VM**, `shopvivaliz-oci-marina-admin` / `shopvivaliz-cloudflared-admin.service`, com **published application/hostname separado** `mcp-marina.shopvivaliz.com.br` encaminhando o caminho `/mcp` a `http://127.0.0.1:5580/mcp`. Reutilizar o mesmo tunnel ID e conector; **nao criar outro Cloudflare Tunnel e nao depender de `OPENAI_ADMIN_KEY`, `runtime-key`, `tunnel-profile.yaml` nem do tunnel-client OpenAI para esta rota**. Nao alterar `admin-marina.shopvivaliz.com.br`, outros ingress, tokens, site, DB ou workers.
> **Gate de exposicao:** a decisao de transporte nao e concessao de acesso. Antes de publicar DNS/ingress, confirmar tunnel ID/account e configuracao existente pelo painel/API oficial; criar **Access application e politica proprias** deny-by-default para MCP (isoladas do admin); usar OAuth compativel com ChatGPT e validar JWT de `Cf-Access-Jwt-Assertion` (issuer/audience/assinatura/expiracao/permissoes) em componente de origem revisado. **O bearer local ja provisionado nao pode ser enviado como token ChatGPT, nem substituido por token do cloudflared**. Se Access Managed OAuth for usado, o origin deve verificar seu JWT; sem protecao provada, NAO PUBLICAR. Ampliacao de superficie de seguranca exige grant PR humana especifica, distinta da PR #3514 de escrita local; nao solicitar nova aprovacao da #3514. Preservar RBAC, CAS, lease, `0600`, readback, negativacoes e rollback.
> **Estado consultado 2026-11-02:** na Marina o `shopvivaliz-cloudflared-admin.service` e o `shopvivaliz-marina-mcp.service` estavam ativos com `NoNewPrivileges=yes`; MCP sem bearer retornou HTTP 401; `mcp-marina.shopvivaliz.com.br` nao resolvia no DNS. Assim, **CHATGPT_DIRECT_E2E=NAO_APTO**, nao existe ainda ingress MCP publicado/certificado. Historicos abaixo sao snapshots do caminho OpenAI anterior e NAO instrucoes para criar tunel novo. Plano e evidencias: `docs/ops/marina-mcp-existing-cloudflare-tunnel-2026-11-02.md` e issue #3152.
<!-- /MARINA_CLOUDFLARE_EXISTING_TUNNEL_ROUTE_20261102 -->
<!-- RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->
**AVISO OPERACIONAL (2026-10-29):** a VM de origem `shopvivaliz-free-a1` foi excluída conforme confirmação do proprietário. As etapas históricas abaixo que requerem **manter, acessar, monitorar, validar, interromper ou recriar** essa VM (incluindo `NO_DECOMMISSION` e critérios dos "quatro hosts") **não são instruções executáveis atuais**. A produção web está em `marina-a1-4c24g`, o backend/controller em `always-free-arm-1787907847-26`. Não substituir aliases/SSH/tenancy da origem por Marina. Preservar backups e evidência acessível, rever gates ainda pertinentes e seguir `docs/knowledge/current-topology-and-access-2026-10-29.md`. A exclusão não prova `APTO`, integração de MCP novo, paridade de dados ou passagem de E2E; `GLOBAL_MIGRATION_STATUS=NAO_APTO` enquanto houver gates pendentes.
<!-- /RETIRED_SOURCE_SUPERSEDES_HISTORICAL_GATES_20261029 -->

# Marina independent MCP (local read-only + owner-granted private write)

**Verified local state 2026-11-02:** dedicated MCP daemon installed and running on
`marina-a1-4c24g`, non-root `shopvivaliz-marina-mcp`, loopback port 5580,
immutable release `6b77b74f5589b6afd7100126433851e70765adcc`.
HTTP 401/200 local authorization, six read-only tools and one owner-approved
bounded private-workspace write are validated. Positive write/readback and
negative replay/stale-CAS E2E passed in the host's private workspace.
The independent **OpenAI Platform runtime key / tunnel profile / ChatGPT E2E are
still NOT PROVISIONED**. No blanket root or production write capability,
and the GitHub API's unauthenticated 60/hour limit can temporarily refuse new
writes safely. Historical staging-only text below is superseded by this block.
Tracking: Vivaliz-site/site-shopvivaliz issues #3152 and #3156. The working direct
ChatGPT route remains the separately connected Remote Desktop Commander.


## Escrita controlada (codigo preparado, NAO HABILITADO)

O modulo `scoped_write.py` prepara `marina_workspace_file_write` para criar ou
substituir via SHA-256/CAS arquivos `.txt` e `.json` de ate 4 KiB **somente** em
`/var/lib/shopvivaliz-marina-mcp/workspace`, diretorio privado da identidade
`shopvivaliz-marina-mcp`. Os nomes nao admitem barras, caminhos relativos,
scripts, symlinks ou arquivos de producao. A operacao exige grant humano
`mcp:marina` + `file_write` + `marina_workspace_file_write` e repositorio exato,
permissao efetiva do host, lease/fencing idempotente, auditoria preparada e
confirmada, escrita atomica e leitura de verificacao.

**Estado:** a release de codigo nao configura o operador protegido nem altera
`ReadWritePaths` da unidade. O servidor so anuncia a ferramenta quando um
`write_operator` **host-owned** e verificado for instalado no bootstrap oficial;
sem ele, `tools/list` continua com as ferramentas de leitura e as chamadas de
escrita sao rejeitadas. Os testes usam operador falso apenas em ambiente isolado:
**nao representam concessao real nem E2E ChatGPT**. A ativacao precisa de PR de
seguranca independente aprovada e integrada por `shopvivaliz-human`, verificacao
de grant no runtime, auditoria/lease persistentes, permissao minima para esse
workspace e tunel OpenAI proprio, seguidos de teste positivo e negativo real.
Operacoes de servicos, deploy, banco, jobs e outros arquivos exigem executores
adicionais de escopo especifico; nunca converter o daemon publico em root ou
usar comandos fornecidos pelo solicitante.


### GitHub authorization availability (2026-11-02)

The writer must verify **current** owner reviews and the exact unchanged grant
file on GitHub `main` immediately before **every** write. A revoked, edited,
expired or unverified grant always fails closed. The process may memoize only
immutable PR/merge/blob-at-merge evidence (three read-only API requests at
bootstrap) to avoid consuming GitHub API quota on every call. The two
mutable pieces — human review status and live grant blob — are never cached.
Without a GitHub credential, GitHub's shared-IP unauthenticated rate limit
may return HTTP 403, in which case **writes are denied and read-only tools stay
available**, without bypassing that provider limit.

An independently approved GitHub **fine-grained read-only credential** can
optionally be installed by the official host operator as the dedicated service
account at
`/var/lib/shopvivaliz-marina-mcp/.config/shopvivaliz-marina-mcp/github-read-token`.
It must be a non-symlink regular file owned by the MCP service account,
`0600`, beneath its `0700` configuration directory. Restrict the token to
`Vivaliz-site/site-shopvivaliz`, only **Contents: Read** and
**Pull requests: Read**, with an expiration and revocation process. It must
be newly and officially provisioned, never copied from a personal CLI,
browser, backend, RDC, worker or OpenAI tunnel key. The MCP never prints the
token, and refuses unsafe present files; an absent file uses GitHub's
ordinary unauthenticated API and still fails closed on throttling.

This GitHub read credential is **not** an OpenAI Secure MCP Tunnel runtime
key and does not link a ChatGPT workspace. The direct Marina connection
remains a separate Platform admin task.

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
* Supports MCP initialize, ping, discovery and six strictly read-only
  tools: host identity; fixed systemd service status; bounded process ID/name
  listing; aggregate system usage; individually approved public Linux kernel
  metrics; and a bounded batch of those public metrics. No shell, process
  arguments, arbitrary filesystem read, sudo, Docker access, SQL changes,
  writes, service actions or durable tasks.
* Rejects unauthenticated callers, client-supplied Origin, unknown tools,
  unexpected services, oversized requests and host identity mismatch.
* Security is intentionally fail-closed. This staging server is NOT a replacement
  for the full ShopVivaliz Remote Control MCP; it does not offer state mutation,
  ownership leases, CAS or fencing because it has no mutation surface.

## RDC parity progress (2026-11-01)

The independent MCP now provides real, unprivileged equivalents to parts of
`list_processes`, `get_usage_stats`, `read_file` (only fixed public kernel
metrics), and `read_multiple_files` (same fixed sources), alongside identity
and service status. Those six tools have tested Streamable HTTP behavior.
**This does not certify the 29 RDC methods:** interactive process control,
filesystem changes, unrestricted commands, provider config and service
mutations require separate protected operators, host RBAC and owner-PR grants.
The staging unit remains disabled until the dedicated OpenAI Secure MCP Tunnel
and local secrets are officially provisioned. Staged code is not an installed
or ChatGPT-connected MCP.

## Validation without deployment

```sh
python3 -m py_compile marina-direct-mcp/server.py tests/test_marina_direct_mcp.py marina-direct-mcp/read_only_telemetry.py tests/test_marina_read_only_telemetry.py
python3 tests/test_marina_read_only_telemetry.py -v
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

## Cliente administrativo local e broker protegido

O cliente `broker_client.py` conecta o processo MCP sem root ao socket fixo
`/run/shopvivaliz-marina-broker/mcp-root.sock`. A opção
`--enable-approved-admin-broker` é desligada por padrão. Quando habilitada,
exige metadados protegidos em
`/etc/shopvivaliz/marina-root-broker/client-grant.json`, peer root comprovado
por `SO_PEERCRED` e preflight atual do grant exato. O documento contém apenas
`operation`, `pr_number`, `head_sha` e `merge_sha`; sua árvore é root-owned,
sem escrita por grupo/outros, e o arquivo é regular e somente leitura pelo
serviço. Nenhuma credencial entra nos argumentos MCP.

As ferramentas `marina_olist_browser_restart_prepare` e
`marina_olist_browser_restart` aparecem somente com esse cliente validado e
autenticação pelo bearer local protegido. JWT do Cloudflare Access permanece
limitado à leitura. O broker emite um ticket de 60 segundos; o execute exige
o fence mais recente, grant idêntico e ticket ainda não utilizado. Perda da
resposta do execute resulta em estado indeterminado, sem repetição automática.

O upgrade inclui código e templates `shopvivaliz-marina-root-broker.service`
e `.socket` como arquivos imutáveis da release. Esses templates não são
instalados, habilitados nem iniciados pelo upgrade. O bootstrap
`operator_stage_service.sh` continua restrito ao MCP inicial. A instalação
do broker exige operador oficial e novo grant humano específico para
`olist_browser_restart` no repositório `Vivaliz-site/amazon-returns-safet`;
#3514 e #3521 não concedem essa operação.

O processo root aceita somente o descritor AF_UNIX entregue pelo systemd.
Seu sandbox permite AF_INET/AF_INET6 para a consulta HTTPS do verificador ao
GitHub; restringir as famílias a AF_UNIX bloqueia também os subprocessos e
inviabiliza a autorização real. O teste
`tests/test_marina_root_broker_sandbox.py` executa um subprocesso HTTP contra
fixture loopback dentro de um serviço systemd temporário com as mesmas
restrições. O teste não usa credenciais, não altera serviços da aplicação
e não certifica a ativação em Marina.

Falha no bootstrap administrativo mantém o MCP disponível para leitura;
`server.py --check --enable-approved-admin-broker` retorna erro. A ativação
operacional ainda depende do grant, instalação protegida, token de leitura
dedicado, testes negativos e validação E2E na Marina.
