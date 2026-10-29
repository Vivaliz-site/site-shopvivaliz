<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Runtime comprovado em 2026-10-29 (prevalece sobre snapshots anteriores):** MCP central sem `shopvivaliz-free-a1`; `hosts_list` = backend + Fred-Win + KOCEPSV, `host_health` confirmado. Marina nao esta no MCP central e seu MCP OpenAI independente segue inativo. O tunel privado de aplicacao MEI-MG para Marina esta ativo com health HTTP 200, nao equivale ao MCP OpenAI. PR #3395 bloqueou 20 jobs antigos. Controller 24x7 ainda `NAO_APTO` por checkpoints/lease, runner MEI `mei-ci-a1` ainda offline. Rotas atuais e evidencias: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Obrigatório para todo agente em 2026-10-29:** ver `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`. **Marina (`marina-a1-4c24g`/OCI ESPOSA) = site/deploy/banco/workers, RDC direto comprovado; backend (`always-free-arm-1787907847-26`/OCI PRINCIPAL) = MCP central/controller/browser.** `shopvivaliz-free-a1` foi excluída: não é alvo e não deve ser recriada. A allowlist MCP central ainda inclui o nome antigo e não inclui Marina. O MCP independente Marina e seu túnel estão inativos; cloudflared ativo não equivale a MCP registrado. Não redirecionar aliases, não utilizar scripts/workflows legados contra origem; exigir identidade, direitos, lease e prova específicos antes de cada execução.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

# Remote Control MCP para agentes

Este documento descreve a rota remota operacional canônica do ShopVivaliz. O **Remote Control MCP privado** é a primeira opção para agentes quando a tarefa exige estado real ou ação controlada em hosts.

## Fontes canônicas

Leia antes de operar:

- `docs/HOST-ACCESS.md`
- `docs/knowledge/host-access.md`
- `docs/knowledge/agent-rules.md`
- `remote-control-mcp/SPEC.md`
- `remote-control-mcp/CHECKPOINT.md` quando a tarefa do MCP estiver em andamento

## Arquitetura

- controller central: `always-free-arm-1787907847-26` (OCI PRINCIPAL)
- endpoint MCP: `127.0.0.1:5580`, privado/loopback
- **producao web/deploy atual: `marina-a1-4c24g` (OCI ESPOSA)**; nao esta presente na allowlist do MCP central em 2026-10-29.
- **origem aposentada:** `shopvivaliz-free-a1`; excluida segundo proprietario, SSH sem rota, jamais acionar via MCP.
- Fred-Win: alcançado pelo controller pela rota privada documentada
- KOCEPSV: alcançado pelo controller pela rota privada documentada quando houver evidência viva de disponibilidade
- GitHub não é queue, heartbeat nem transporte normal do runtime do MCP

## Ordem operacional

1. **Remote Control MCP** para backend e hosts allowlisted **ativos**; `hosts_list` ainda inclui origem excluida e nao inclui Marina. Na Marina, **RDC 1/2 direto** foi verificado em 2026-10-29; o MCP proprio Marina e tunnel-client OpenAI continuam inativos e sem conexao comprovada. `shopvivaliz-cloudflared-admin.service` ativo nao significa MCP pronto.
2. **SSH privado/Tailscale** com identidade dedicada somente quando a operação necessária não estiver exposta pelo MCP ou quando o controller estiver comprovadamente indisponível.
3. **GitHub Actions/OCI Bastion** para bootstrap, recovery ou reparo do próprio control plane.
4. **RustDesk** para GUI/validação visual. Browser de agente continua na backend.

SSH público direto, root público, senha interativa e endpoint MCP público são proibidos.

## Atualizacao obrigatoria da topologia

Consultar `docs/knowledge/current-topology-and-access-2026-10-29.md`, `marina-direct-mcp/README.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md`. Nao anunciar `admin_command_run` na Marina pela controladora: ferramenta nao a aceita como host. Nao reutilizar secrets, hostname ou credenciais da controladora no MCP independente.

## Evidência obrigatória

Antes de declarar acesso funcional, obtenha evidência fresca de:

- host correto;
- identidade correta;
- rota correta;
- ação executada com resultado verificável;
- ausência de fallback público improvisado.

Porta aberta ou serviço configurado isoladamente não prova acesso operacional.

## Segurança

Nunca imprimir, versionar ou copiar para documentação/chat:

- chaves privadas;
- senhas;
- tokens/API keys;
- cookies/sessões;
- OTP/TOTP/seeds;
- conteúdo de arquivos protegidos.

O MCP não amplia permissões do agente e não autoriza force-push, bypass de proteção ou alteração fora do escopo aprovado.

## Documentação histórica

Guias antigos de acesso remoto de terceiros ou device-flow podem permanecer no repositório apenas como evidência histórica. Eles **não são instruções operacionais** e não substituem esta hierarquia.
