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

- controller: `always-free-arm-1787907847-26`
- endpoint MCP: `127.0.0.1:5580`, privado/loopback
- produção web/deploy: `shopvivaliz-free-a1`
- Fred-Win: alcançado pelo controller pela rota privada documentada
- KOCEPSV: alcançado pelo controller pela rota privada documentada quando houver evidência viva de disponibilidade
- GitHub não é queue, heartbeat nem transporte normal do runtime do MCP

## Ordem operacional

1. **Remote Control MCP** para ações allowlisted, observação, serviços, arquivos e tarefas duráveis.
2. **SSH privado/Tailscale** com identidade dedicada somente quando a operação necessária não estiver exposta pelo MCP ou quando o controller estiver comprovadamente indisponível.
3. **GitHub Actions/OCI Bastion** para bootstrap, recovery ou reparo do próprio control plane.
4. **RustDesk** para GUI/validação visual. Browser de agente continua na backend.

SSH público direto, root público, senha interativa e endpoint MCP público são proibidos.

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
