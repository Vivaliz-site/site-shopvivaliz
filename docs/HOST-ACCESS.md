# Acesso canônico aos hosts ShopVivaliz

Este arquivo é um runbook local e **não contém segredos**. A fonte central mais detalhada é `Vivaliz-site/site-shopvivaliz/docs/knowledge/host-access.md`. Se houver divergência, validar ao vivo e corrigir a documentação; nunca assumir IP/rota histórica como verdadeira.

## Mapa de hosts

| Host | Endereço privado/rota | Papel |
|---|---|---|
| `shopvivaliz-free-a1` | `10.0.1.112` | produção web e deploy |
| `always-free-arm-1787907847-26` | `10.0.1.38` | backend, controller do Remote Control MCP, navegador, MEI/M365 |
| Fred-Win / `LAPTOP-NIG4IFUU` | reverse SSH no backend `127.0.0.1:2222` | host Windows auxiliar |
| KOCEPSV / `DESKTOP-KOCEPSV` | reverse SSH no backend `127.0.0.1:2223` | host Windows auxiliar |

## Estado operacional conhecido em 2026-09-28

- Controller/backend e target Linux de produção: bootstrap comprovado.
- Fred-Win `127.0.0.1:2222`: evidência recente de PASS.
- KOCEPSV `127.0.0.1:2223`: **rota canônica, ainda não comprovada operacionalmente**. Tratar como indisponível até um teste fresco comprovar a porta e a identidade administrativa.
- Etapas posteriores do Remote Control MCP (E2E 4 hosts, tarefa durável independente de GitHub e integração final com ChatGPT) não devem ser declaradas concluídas sem evidência nova.

## Shell Linux

1. Preferir rede privada/VCN/Tailscale e identidade dedicada `shopvivaliz-agent`.
2. GitHub Actions administrativos usam runners privados autorizados; GitHub é fonte/bootstrap/recovery, não transporte normal do Remote Control MCP em runtime.
3. Quando não houver rota privada, usar OCI Bastion/control plane auditável.
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

- Navegador de agente ShopVivaliz: somente `always-free-arm-1787907847-26`.
- Não abrir navegador operacional em Fred-Win ou KOCEPSV.
- GUI: RustDesk self-hosted é o caminho principal.
- Desktop Commander: contingência somente.
- Sessões headless/invisíveis devem ser identificadas por tarefa/agente/máquina, com PID/perfil quando aplicável e TTL de 2h renovável; encerrar ao concluir.

## Remote Control MCP

Arquitetura alvo:

- controller: `always-free-arm-1787907847-26`
- MCP loopback: `127.0.0.1:5580`
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
