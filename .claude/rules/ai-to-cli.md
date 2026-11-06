<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->
> **Fonte operacional prevalente (revalidada em 06/11/2026, Auditoria Externa V5):** o MCP central ShopVivaliz Remote Control e o Orquestrador expoem backend (always-free-arm-1787907847-26), Fred-Win, KOCEPSV e Marina (marina-a1-4c24g) por schemas publicados. A Marina foi testada por admin_command_run/task_submit com exit_code=0; conferir novamente schema e grants da sessao antes de cada operacao, sem inferir permissao somente de hosts_list. O MCP independente da Marina foi reportado localmente ativo em 04/11, mas seu acesso publico autenticado nao esta certificado neste escopo. A VM shopvivaliz-free-a1 foi excluida e nunca e destino. MFA, RBAC, branch protection, leases, revisao humana e recusas explicitas da plataforma permanecem validos. Documentos de 29/10 e 01/11 com restricoes anteriores sao HISTORICOS, NAO OPERACIONAIS. Fonte: docs/ops/mcp-first-agent-execution-2026-11-05.md.
<!-- /MCP_ROUTE_CANONICAL_V5_20261106 -->

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Registro de 29/10/2026 (HISTORICO SUPERADO em 06/11/2026):** este bloco preserva a proveniencia temporal da migracao, mas nao determina hosts, grants ou ordem de acesso atual. A rota operacional atual tem quatro hosts no MCP central e Orquestrador, incluindo marina-a1-4c24g quando o schema e o grant reais permitirem. O MCP independente da Marina estava ativo localmente em 04/11; autenticacao publica independente exige sua propria prova E2E. A VM excluida shopvivaliz-free-a1 nao e destino. Ver docs/ops/mcp-first-agent-execution-2026-11-05.md e MCP_ROUTE_CANONICAL_V5_20261106.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Registro de 29/10/2026 (HISTORICO SUPERADO em 06/11/2026):** este bloco preserva a proveniencia temporal da migracao, mas nao determina hosts, grants ou ordem de acesso atual. A rota operacional atual tem quatro hosts no MCP central e Orquestrador, incluindo marina-a1-4c24g quando o schema e o grant reais permitirem. O MCP independente da Marina estava ativo localmente em 04/11; autenticacao publica independente exige sua propria prova E2E. A VM excluida shopvivaliz-free-a1 nao e destino. Ver docs/ops/mcp-first-agent-execution-2026-11-05.md e MCP_ROUTE_CANONICAL_V5_20261106.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

# Protocolo IA-to-CLI obrigatório

Antes de qualquer alteração, leia e siga integralmente o protocolo canônico abaixo. Ele complementa, sem substituir, as regras específicas do projeto.

@../../AI-TO-CLI-PROTOCOL.md

Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.
