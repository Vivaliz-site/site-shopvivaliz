# Acesso canônico dos agentes aos hosts ShopVivaliz

**Revisão operacional: 11/11/2026.** A fonte de verdade é o `main` de `Vivaliz-site/site-shopvivaliz`, especialmente `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md` e `docs/ops/mcp-first-agent-execution-2026-11-05.md`. O protocolo usa MCP como rota operacional nativa e distingue disponibilidade do transporte, privilégio, lease e autenticação por operação.

## Infraestrutura válida

| Host | Função | Rota MCP |
| --- | --- | --- |
| `marina-a1-4c24g` | Web, deploy, MySQL, workers, runners e Cloudflare admin | ShopVivaliz Remote Control, mediante grant/lease efetivos |
| `always-free-arm-1787907847-26` | Backend, controlador 24x7, navegadores corporativos, MEI/M365 e MCP | ShopVivaliz Remote Control e Orquestrador |
| `Fred-Win` (`LAPTOP-NIG4IFUU`) | Estação de suporte | ShopVivaliz Remote Control, com identidade e autorização confirmadas |
| `KOCEPSV` | Estação auxiliar | ShopVivaliz Remote Control, condicionado a relay funcional |

A origem `shopvivaliz-free-a1` foi excluída. Nenhuma instrução deste documento autoriza reativar a VM anterior ou reutilizar seus aliases.

## Evidência objetiva de 11/11/2026

O MCP central executou `hostname -s; id -un` na Marina (resultado `marina-a1-4c24g`, `ubuntu`, exit 0) e no backend (hostname `always-free-arm-1787907847-26`, serviço controlador ativo); no Fred-Win, `hostname; whoami` retornou `LAPTOP-NIG4IFUU` e `fred`, exit 0. No KOCEPSV, o transporte atual retornou `ssh: connect to host 127.0.0.1 port 2223: Connection refused`; essa capacidade ainda requer reparo e readback, sem afirmar cobertura universal.

Esses testes não demonstram permissão universal de escrita nem certificam E2E gráfico nos quatro hosts. `continuity_ready` é verificado separadamente no controlador e nunca deve ser inferido da condição `active` do systemd.

## Fluxo operacional do agente

1. Ler a topologia e o schema do MCP da sessão, sem supor capacidades pelo inventário. Selecionar a ferramenta autorizada do host.
2. Para comandos curtos, `admin_command_run` (timeout de até 5 s). Para execuções longas, `task_submit` com `request_id` idempotente e `task_status` até `succeeded`, `exit_code=0` e readback.
3. Antes de mutações, confirmar identidade, permissões, ownership, lease/fencing, grants de operação, branch protection, validação e não concorrência. Preservar dados, regras comerciais e aprovação humana quando exigidos.
4. Para interface, utilizar as ferramentas MCP de navegador/desktop do perfil correto e validar clique, resposta, persistência e reload. Acesso shell ou resposta HTTP isolada não certifica fluxo visual.
5. Para manutenção privada, SSH/Tailscale/OCI continuam mecanismos independentes sujeitos a autenticação, host key e escopo próprio; não são meios de contornar recusa de segurança.
6. Em produção, operar apenas pelo deploy canônico e releases imutáveis; nunca editar `/home/ubuntu/shopvivaliz-deploy/current` ou a release ativa.

Segredos, cookies, chaves, senhas, OTPs e tokens não entram em repositórios, logs, capturas ou conversas. MFA, RBAC, grants e controles já vigentes permanecem preservados.

## Proveniência

A descrição de acesso de setembro/outubro de 2026, anterior ao cutover, permanece rastreável no histórico Git. Este documento não republica comandos antigos de execução contra a VM excluída.
