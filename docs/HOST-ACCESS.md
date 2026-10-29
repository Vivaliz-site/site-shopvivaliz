# Acesso canônico aos hosts ShopVivaliz

Este arquivo é um runbook local e **não contém segredos**. A fonte central mais detalhada é `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`. Se houver divergência, validar ao vivo e corrigir a documentação; nunca assumir IP/rota histórica como verdadeira.

## Mapa de hosts

| Host | Endereço privado/rota | Papel |
|---|---|---|
| `marina-a1-4c24g` (OCI ESPOSA) | `10.80.1.116` documentado; Tailscale observado `100.118.170.46` | **producao web/deploy**, banco, workers, runner; RDC 1/2 direto online em 2026-10-29 |
| `always-free-arm-1787907847-26` (OCI PRINCIPAL) | `10.0.1.38`; Tailscale observado `100.66.174.74` | backend, controller do MCP central, navegador, MEI/M365 |
| Fred-Win / `LAPTOP-NIG4IFUU` | reverse SSH no backend `127.0.0.1:2222` | host Windows auxiliar |
| KOCEPSV / `DESKTOP-KOCEPSV` | reverse SSH no backend `127.0.0.1:2223` | host Windows auxiliar |

## Estado operacional (2026-10-29; evidencias anteriores abaixo sao historicas)

- Origem `shopvivaliz-free-a1` **excluida segundo proprietario**, `10.0.1.112:22` sem rota; nao e alvo de producao. Nao inferir `TERMINATED` na OCI sem consulta.
- `marina-a1-4c24g` online via RDC 1/2; `shopvivaliz-cloudflared-admin.service` ativo; runner web ativo. MCP direto Marina e tunnel-client independentes **inativos**, sem app OpenAI autenticado.
- MCP central do backend responde, porem seu inventario ainda inclui a origem e nao inclui Marina. Nao tentar operar Marina via esse MCP. Ver `knowledge/current-topology-and-access-2026-10-29.md`.

- Backend/controller: bootstrap verificado via MCP. Marina e o alvo web, mas **nao** e executavel pelo MCP central.
- Fred-Win `127.0.0.1:2222`: evidência recente de PASS.
- KOCEPSV `127.0.0.1:2223`: **rota canônica, ainda não comprovada operacionalmente**. Tratar como indisponível até um teste fresco comprovar a porta e a identidade administrativa.
- Etapas posteriores do Remote Control MCP (E2E 4 hosts, tarefa durável independente de GitHub e integração final com ChatGPT) não devem ser declaradas concluídas sem evidência nova.

## Ordem de acesso e shell Linux

1. Usar o **Remote Control MCP** como rota operacional primária para ações allowlisted e estado durável.
2. Usar rede privada/VCN/Tailscale com identidade dedicada `shopvivaliz-agent` quando a operação exigir shell direto que o MCP não exponha.
3. GitHub Actions administrativos e OCI Bastion ficam para bootstrap/recovery/reparo quando o Remote Control MCP ou a rota privada não estiverem disponíveis.
4. Antes de alterar qualquer coisa, provar:
   - `hostname`
   - `whoami` e/ou `id`
   - `pwd`
   - `git status --porcelain` quando houver checkout
5. Produção usa releases imutáveis. Nunca editar `/home/ubuntu/shopvivaliz-deploy/current/` nem a release ativa.

SSH público direto, senha interativa, root público e credencial reutilizável exposta são proibidos.

## Windows

A rota operacional desejada é reverse SSH terminando no backend:

- Fred-Win: `127.0.0.1:2222 -> 127.0.0.1:22`
- KOCEPSV: `127.0.0.1:2223 -> 127.0.0.1:22`

Os relays MCP legados:

- Fred-Win: `5557`
- KOCEPSV: `5558`

são **somente bootstrap/recovery**, nunca o transporte normal de runtime.

Ao validar Windows, comprovar `hostname`, `whoami` e que a sessão administrativa é realmente elevada. Não inferir Administrator apenas porque a conexão SSH abriu.

## Navegador e GUI

- Navegador corporativo de agente ShopVivaliz: somente `always-free-arm-1787907847-26`, enquanto perfil autenticado e isolamento estiverem confirmados.
- Não abrir navegador operacional em Fred-Win ou KOCEPSV.
- GUI: RustDesk self-hosted é o caminho principal.
- O browser de agentes continua restrito à backend; não mover navegação para Windows como fallback.
- Sessões headless/invisíveis devem ser identificadas por tarefa/agente/máquina, com PID/perfil quando aplicável e TTL de 2h renovável; encerrar ao concluir.

## Remote Control MCP

Esta é a rota operacional prioritária para agentes. Se a ação necessária não existir no MCP, usar o próximo método autorizado em vez de abrir uma rota pública ou improvisar transporte.

Arquitetura alvo:

- controller: `always-free-arm-1787907847-26` (MCP central; inclui inventario legado, nao a Marina)
- MCP loopback: `127.0.0.1:5580` no backend; nao confundir com a porta proposta do MCP Marina, ainda inativo
- Linux: privilégios administrativos somente pelo canal privado dedicado e auditado
- Windows: Administrator somente após validação real
- endpoint de controle nunca público
- tarefas duráveis em SQLite WAL e auditoria sem conteúdo secreto
- GitHub não pode ser queue/heartbeat/transporte normal em runtime

## Credenciais e secrets

Documentar apenas **nomes de secrets, localizações seguras e procedimentos**. Nunca copiar valores de:
- chaves privadas;
- senhas;
- tokens/API keys;
- cookies/sessões;
- OTP/TOTP/seeds;
- conteúdo de arquivos protegidos.

## Gate antes de declarar acesso funcional

Acesso só pode ser chamado de funcional quando houver evidência fresca da rota e da identidade correta. Porta aberta, workflow verde, `configured=true` ou arquivo presente não bastam.
