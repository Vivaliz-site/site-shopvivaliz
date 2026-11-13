<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->
> **Fonte operacional prevalente (revalidada em 06/11/2026, Auditoria Externa V5):** o MCP central ShopVivaliz Remote Control e o Orquestrador expoem backend (always-free-arm-1787907847-26), Fred-Win, KOCEPSV e Marina (marina-a1-4c24g) por schemas publicados. A Marina foi testada por admin_command_run/task_submit com exit_code=0; conferir novamente schema e grants da sessao antes de cada operacao, sem inferir permissao somente de hosts_list. O MCP independente da Marina foi reportado localmente ativo em 04/11, mas seu acesso publico autenticado nao esta certificado neste escopo. A VM shopvivaliz-free-a1 foi excluida e nunca e destino. MFA, RBAC, branch protection, leases, revisao humana e recusas explicitas da plataforma permanecem validos. Documentos de 29/10 e 01/11 com restricoes anteriores sao HISTORICOS, NAO OPERACIONAIS. Registro historico de referencia para o preflight: docs/knowledge/current-topology-and-access-2026-10-29.md, sem forca de autoridade sobre os schemas atuais. Fonte: docs/ops/mcp-first-agent-execution-2026-11-05.md.
<!-- /MCP_ROUTE_CANONICAL_V5_20261106 -->

<!-- CHECKPOINT_FIRST_V9 -->
## Checkpoint obrigatorio antes de executar
Toda tarefa longa, delegacao, investigacao material ou mutacao deve executar `scripts/agent_task_state.py start` antes da primeira etapa material. Apos cada etapa, executar `scripts/agent_task_state.py progress` com evidencia e `next_action`. Na retomada, ler o checkpoint existente e preservar ownership e gates. Se o registro duravel nao estiver acessivel, falhar fechado para tarefas dependentes de retomada automatica e usar rota autorizada auditavel. Nao declarar conclusao sem validacao e `terminal`.
<!-- /CHECKPOINT_FIRST_V9 -->

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist proporcional: sem bloqueio global para tarefas rotineiras

**Revisao por determinacao do proprietario (06/11/2026):** foi revogada a obrigatoriedade universal de planejar e certificar um checklist a cada etapa, introduzida em 31/10/2026. A ausencia de `plan-checklist` nao pode impedir `record_progress`, retomada, correcao, teste ou conclusao de tarefas P0/P1 que ja possuam autorizacao. Um checkpoint duravel e uma proxima acao concreta continuam suficientes para registrar o trabalho; nao criar processo de aprovacao por subcomando.

`scripts/agent_task_state.py` deixa de injetar automaticamente um checklist exigivel em novos checkpoints do runtime canonico. Checkpoints anteriores com lista **vazia** nao ficam presos por um plano nunca iniciado. Para trabalhos que precisam de checklist formal, o executor pode ativar `SHOPVIVALIZ_REQUIRE_AGENT_CHECKLIST=1` no escopo do fluxo e usar `plan-checklist`, `next-step`, `check-step` e `add-checklist-step`. Se ja existem etapas planejadas, suas evidencias e integridade continuam obrigatorias para encerrar; nao apagar nem certificar etapas sem comprovacao.

Essa desburocratizacao nao autoriza falso sucesso nem remove controles independentes: identidade, MFA, RBAC, segredos, lease/fencing, aprovacoes humanas formalmente necessarias, branch protection, gates de operacoes comerciais, CI e validacao funcional/E2E pertinente. Nenhum controle novo deve ser imposto sem aprovacao do proprietario.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

**Obrigatoria para toda execucao e todo agente, independentemente do fornecedor, modelo ou interface:** ChatGPT, Codex, Claude, Gemini, agentes autonomos, bots, workers, scripts, GitHub Actions, ferramentas MCP e futuros agentes. Aplica-se a todos os repositorios e organizacoes GitHub sob gestao da ShopVivaliz, nao apenas a este repositorio.

**Nao abandonar tarefas por um bloqueio tecnico isolado.** Investigar a causa raiz, corrigir o que for seguro e autorizado, testar novamente e inventariar sistematicamente todas as rotas REALMENTE provisionadas e independentemente autorizadas antes de alegar bloqueio: MCP/Remote Control, conectores, Cloudflare/cloudflared e tuneis, GitHub CLI/API com identidade correta e credenciais protegidas, SSH privado/Tailscale, RDC 1/2, navegadores de agentes no host permitido, VMs ativas, runners/Actions, OCI, Superpowers quando disponivel e outros recursos identificados na documentacao vigente. Nao assumir disponibilidade, permissao ou sucesso sem teste e evidencia. Nao expor segredos nem criar credenciais paralelas.

Para cada rota registrar disponibilidade, identidade, escopo de permissao, lease/fencing quando aplicavel, tentativa, resultado, causa da falha, reparo e proxima alternativa. Nao repetir indefinidamente a mesma tentativa. Prosseguir nas etapas independentes, preservar checkpoints duraveis e retomada automatica autorizada. A tarefa so termina com correcao, prevencao, testes, validacao runtime/E2E real e merge apos gates quando aplicavel, ou bloqueio externo comprovado apos esgotamento de todas as rotas legitimas. Nenhum status RUNNING antigo comprova execucao ativa.

**Limites absolutos:** nao contornar MFA, CAPTCHA, RBAC, 401/403, recusa expressa, branch protection, aprovacoes, leases, isolamento de hosts ou politicas do provedor. Rota alternativa nunca pode ser usada para burlar uma negativa. Manter pendencias abertas com evidencia e proxima acao quando existir impedimento legitimo.

**Propagacao:** manter esta regra nos arquivos de instrucoes de agentes de cada repositorio ativo (AGENTS.md, CLAUDE.md, GEMINI.md, instrucoes Codex e equivalentes existentes), respeitando a governanca local; verificar aplicacao por inventario e testes, nao por presuncao.

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Registro de 29/10/2026 (HISTORICO SUPERADO em 06/11/2026):** este bloco preserva a proveniencia temporal da migracao, mas nao determina hosts, grants ou ordem de acesso atual. A rota operacional atual tem quatro hosts no MCP central e Orquestrador, incluindo marina-a1-4c24g quando o schema e o grant reais permitirem. O MCP independente da Marina estava ativo localmente em 04/11; autenticacao publica independente exige sua propria prova E2E. A VM excluida shopvivaliz-free-a1 nao e destino. Ver docs/ops/mcp-first-agent-execution-2026-11-05.md e MCP_ROUTE_CANONICAL_V5_20261106.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Registro de 29/10/2026 (HISTORICO SUPERADO em 06/11/2026):** este bloco preserva a proveniencia temporal da migracao, mas nao determina hosts, grants ou ordem de acesso atual. A rota operacional atual tem quatro hosts no MCP central e Orquestrador, incluindo marina-a1-4c24g quando o schema e o grant reais permitirem. O MCP independente da Marina estava ativo localmente em 04/11; autenticacao publica independente exige sua propria prova E2E. A VM excluida shopvivaliz-free-a1 nao e destino. Ver docs/ops/mcp-first-agent-execution-2026-11-05.md e MCP_ROUTE_CANONICAL_V5_20261106.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

<!-- RESUME_EXECUTION_GATE_V1 -->
## Execução obrigatória ao receber Retome / Continue / Prossiga / Siga

Para qualquer executor, subagente ou ferramenta neste projeto: carregue `AGENTS.md` e a documentação aplicável do `main`; persista prova `rules_loaded` (SHA/ref/data). `Retome` significa **executar agora** a próxima ação do checkpoint, não só relatar status nem pedir aprovação genérica já fornecida. Preserve sessões e ownership; corrija falhas, tente rotas seguras alternativas, teste e valide o objetivo original antes de encerrar. Continue como `RUNNING` com `next_action` específico enquanto existir caminho autorizado; `CONCLUIDO` exige evidência E2E fresca, e `BLOCKED_EXTERNAL` exige causa externa, alternativas exauridas e condição exata para retorno. Quando existir, use `agent_task_state.py terminal --task <task_id>` antes de declarar conclusão; ACK, erro transitório, PR, merge, `Pensando` e mensagem intermediária não são término. Não contorne MFA, CI, approvals ou controles. Não prometa continuidade fora de sessão sem checkpoint/controlador real. O agente delegado segue esta mesma regra.
<!-- /RESUME_EXECUTION_GATE_V1 -->

# Agent completion protocol
Read and obey `AI-TO-CLI-PROTOCOL.md` before executing repository work. The section `PROTOCOLO OBRIGATORIO DE CONCLUSAO DE TAREFAS` is binding: do not stop at partial progress or a recoverable tool failure; continue until CONCLUIDO or a genuine external BLOQUEADO state.

<!-- TASK_CONTINUITY_ENFORCEMENT_V3 -->
## Continuidade obrigatória até estado terminal

- Estados finais permitidos para uma tarefa iniciada: `CONCLUIDO` com validação fresca, ou `BLOCKED_EXTERNAL` com impedimento externo objetivo e intransponível após esgotar alternativas seguras.
- Pedido explícito do usuário para executar, implementar, corrigir, auditar, resolver ou continuar até conclusão, somado à autorização operacional vigente, já satisfaz gates genéricos de aprovação de plano/design para ações reversíveis e dentro do escopo. Não parar apenas para pedir "posso continuar?" ou aprovação intermediária equivalente.
- Essa autorização não elimina confirmações obrigatórias de segurança/plataforma para ação destrutiva ou irreversível não autorizada exatamente, cobrança real, segredo, login/CAPTCHA/recovery ou ampliação material de escopo.
- Falha de ferramenta, plugin, CLI, API, navegador, sessão, runner, workflow, timeout ou rota primária é estado `RUNNING`, nunca terminal enquanto existir fallback seguro ou próxima ação executável.
- Tarefa simples segue a mesma regra: progresso parcial, diagnóstico ou erro recuperável não autorizam encerrar.
- Quando o runtime tiver acesso ao repositório, persistir o estado em `scripts/agent_task_state.py`; antes de alegar término, o gate `terminal --task <id>` deve aceitar o estado.
- Contrato detalhado: `docs/knowledge/task-continuity.md`.
<!-- /TASK_CONTINUITY_ENFORCEMENT_V3 -->

<!-- CODEX_LAST_RESORT_V1 -->
## Codex como última opção de execução
Preservar a cota do Codex. Para continuidade de tarefas, priorizar rotas determinísticas/controle remoto auditável e executores alternativos autenticados adequados ao trabalho; usar Codex somente como último recurso finito. Falta de cota, rate limit ou indisponibilidade do Codex mantém a tarefa `RUNNING` e nunca constitui, isoladamente, `BLOCKED_EXTERNAL`.
<!-- /CODEX_LAST_RESORT_V1 -->

<!-- CHATGPT_RESUME_ORDER_V5 -->
## Ordem obrigatória de retomada

Policy: `CHATGPT_RESUME_ORDER_V5`.

Para qualquer tarefa abandonada, interrompida ou com checkpoint `RUNNING`, a ordem é fixa:

1. **ChatGPT comum** — primeira opção; retoma do checkpoint no próximo turno disponível.
2. **ChatGPT Work** — segunda opção, quando a tarefa exige continuidade persistente/multi-etapas.
3. **CLI** — terceira e última opção, somente depois das duas camadas ChatGPT anteriores terem sido tentadas ou comprovadamente indisponíveis/inadequadas.

Roteamento persistido: `chatgpt_common_then_work_then_cli`.
Ordem serializada: `["chatgpt_common", "chatgpt_work", "cli"]`.

Interrupção de streaming não autoriza pular para CLI. O watchdog não chama CLI nem IA paga; ele cria o pedido de retomada. O worker roteia `auto_resume` para `gpt`/ChatGPT comum. A camada CLI exige `SHOPVIVALIZ_RESUME_STAGE=cli_last`; sem isso, falha fechada com exit 75 e mantém o checkpoint `RUNNING`.
<!-- /CHATGPT_RESUME_ORDER_V5 -->

> **Executar como `ubuntu` na Marina e root do backend no MCP Marina (2026-11-12):** `marina_admin_shell` ganha o argumento opcional `as_user="ubuntu"` (sudo -n -u ubuntu, auditado por hash com a marca `as_user`; nao combina com `as_root`; qualquer outro usuario e recusado). Dependencia: grant de PR #3918 (`security/owner-approvals/3918.json`) aprovado por `shopvivaliz-human` no HEAD exato e release ativado pelo operador; enquanto o schema publicado da ferramenta nao listar `as_user`, ou o retorno for `ubuntu_grant_unavailable`, tratar como NAO ATIVADO e usar `as_root` ou a conta de servico, sem pedir senha de sudo do `ubuntu` nem alterar sudoers na VM. No backend, o usuario `root` tambem tem `shopvivaliz-marina-admin` e `shopvivaliz-remote-control` em `/root/.claude.json` e a credencial do Marina MCP em `/root/.shopvivaliz/secrets/` (0600, dono root; provisionada por copia local em 2026-11-12, sem expor valores). Nunca copiar essas credenciais para outro usuario ou host por conta propria. Detalhes: `docs/knowledge/marina-mcp-admin-agent-access.md`.
<!-- CHATGPT_LOGIN_TOOLS_20261113 -->
**Login ChatGPT Dev/Atendimento sem lease (decisao do proprietario, 13/11/2026; PRs #3947/#3948/#3949):** `browser_auth_open`, `browser_auth_action` (`fill_email`, `fill_password`, `fill_saved_password`, `fill_gnome_totp`, `continue`) e `browser_profile_home_open` NAO exigem lease de manutencao (se informado, ainda e validado). Foram removidos os gates `_assert_runtime_mutation` e `_assert_conversation_mutation`, o e-mail fixo e a checagem de pagina oficial, que o dono nao aprovou. Senhas ja provisionadas na backend em `/home/ubuntu/.shopvivaliz/secrets/CHATGPT_DEV_PASSWORD` e `CHATGPT_ATENDIMENTO_PASSWORD` (0600, dono ubuntu): usar `fill_saved_password` (o servidor le o arquivo; o agente nao precisa do valor) e `fill_gnome_totp` (TOTP no GNOME Keyring do `fredrdp`). Atendimento NAO usa Google: mesmo processo do dev (senha + TOTP). Nunca imprimir, commitar ou colar valores. Fluxo: `browser_auth_open` -> `continue` -> `fill_email` -> `continue` -> `fill_saved_password` -> `continue` -> `fill_gnome_totp` -> `continue` -> `browser_auth_status` = AUTHENTICATED com `identity_match`. Aba de login antiga em "Session ended" causa `auth_ui_preflight_failed`: fechar e abrir outra. Sessao sem cookies (contexto anonimo, navegador universal) recebe desafio Cloudflare "Just a moment...": nao contornar, exige acao humana; nunca fazer logout para testar. `login_email_watch` agora le o codigo do e-mail (requer OAuth Gmail readonly do backend, ainda nao provisionado). Amazon Seller Central e ERP Olist: credenciais na Marina em `/home/ubuntu/amazon-returns-deploy/shared/` (`seller-central-browser.env`, `olist-erp-browser-login.env`); logar com `amazon-returns-seller-central-auth-check.service`; ERP via navegador persistente (CDP 9226). Procedimento completo, armadilhas e deploy do MCP: `docs/knowledge/chatgpt-login-sem-lease-20261113.md`.
<!-- /CHATGPT_LOGIN_TOOLS_20261113 -->

