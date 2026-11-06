<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->
> **Acesso MCP em 06/11/2026, revisado pelo protocolo V5:** ShopVivaliz Remote Control e Orquestrador aceitam os quatro hosts (always-free-arm-1787907847-26, Fred-Win, KOCEPSV, marina-a1-4c24g) quando o schema e grants reais da sessao autorizarem, com exit_code=0 em canarios administrativos da Marina. O MCP independente da Marina esta ativo localmente desde 04/11, mas autenticacao publica independente exige teste E2E proprio. A origem shopvivaliz-free-a1 foi excluida e jamais e alvo. Preservar MFA, RBAC, leases, branch protection, revisao humana e recusas da plataforma. Fonte atual: docs/ops/mcp-first-agent-execution-2026-11-05.md; historia: docs/knowledge/current-topology-and-access-2026-10-29.md.
<!-- /MCP_ROUTE_CANONICAL_V5_20261106 -->

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Snapshot de 29/10/2026, SUPERADO pela validacao 06/11/2026:** os relatos anteriores de Marina fora da allowlist ou MCP independente inativo descrevem apenas a data de 29/10. Usar schema e grants atuais do MCP central/Orquestrador (quatro hosts), MCP independente com autenticacao propria e nunca a VM excluida.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Snapshot de 29/10/2026, SUPERADO pela validacao 06/11/2026:** os relatos anteriores de Marina fora da allowlist ou MCP independente inativo descrevem apenas a data de 29/10. Usar schema e grants atuais do MCP central/Orquestrador (quatro hosts), MCP independente com autenticacao propria e nunca a VM excluida.
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
- O MCP central e Orquestrador anunciam quatro hosts, incluindo Marina (marina-a1-4c24g), com autorizacao dependente do schema/grants reais da sessao; o MCP independente esta ativo localmente e seu E2E publico e distinto. Nunca acessar shopvivaliz-free-a1.
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

- O MCP central e Orquestrador anunciam quatro hosts, incluindo Marina (marina-a1-4c24g), com autorizacao dependente do schema/grants reais da sessao; o MCP independente esta ativo localmente e seu E2E publico e distinto. Nunca acessar shopvivaliz-free-a1.

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
