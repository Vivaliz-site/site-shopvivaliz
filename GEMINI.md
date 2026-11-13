<!-- OWNER_ONLY_RESTRICTIONS_20261110 -->
## Exclusividade do proprietário para novas restrições — revisão de 12/11/2026

- Somente o proprietário, atuando pessoalmente, pode **introduzir novas restrições ou agravar restrições existentes** (bloqueios, gates, regras impeditivas, exigências de aprovação, limitações de acesso/CI/merge/deploy). Nenhum agente pode criar ou preparar PR que acrescente essas restrições; uma aprovação verbal ou de PR não transfere esse poder.
- **Restauração de baseline protetivo já aprovado não é uma nova restrição.** Pela orientação expressa de 12/11/2026, agente técnico autenticado pode corrigir regressão e restaurar exclusivamente o estado de segurança anterior comprovado, pelas rotas autorizadas e controles de ownership, lease/fencing, auditoria, teste e deploy já vigentes. Não pode aumentar o alcance nem inventar uma proteção diferente.
- **Amazon Returns:** a restauração do kill switch global ON e das sete ações externas OFF, se comprovadamente correspondente ao baseline previamente aprovado, é correção de regressão permitida após integração formal desta revisão. Não abre SAFE-T, não autoriza recursos/suporte/ERP, não executa outbox e não substitui o grant ou a elegibilidade oficial.
- O grant #3846 (renovação de #3556, uso único) continua sujeito ao escopo aprovado, readback, autorização real do operador, idempotência e regras da Amazon. Não ampliar allowlist, operar perfil de sete ações habilitadas nem criar grant novo por inferência.
- MFA, RBAC, revisões humanas formalmente exigidas, branch protection, proteções de terceiros, leases/fencing, CI e segurança existente permanecem válidos. A exceção histórica para implantação inicial da regra em 10/11 não autoriza novas restrições.
- Correções técnicas, ajustes não restritivos e redução de travas indevidas continuam no fluxo normal. PRs e comunicações de agentes devem ser redigidas em português brasileiro e identificar o agente real.
- Fonte canônica: `docs/knowledge/owner-only-restrictions-2026-11-10.md`.
<!-- /OWNER_ONLY_RESTRICTIONS_20261110 -->

<!-- AGENT_PR_TITLE_IDENTITY_V1_20261108 -->
> **Regra do proprietario (08/11/2026) — identidade da IA no titulo de TODA PR de agente:** na criacao de uma PR ShopVivaliz, prefixar seu titulo em pt-BR com o agente executor REAL: `[GPT]`, `[Claude]`, `[Gemini]`, `[Codex]`, `[Copilot]`, `[Ollama]`, `[Auto-Healer]` ou `[Agente: Nome]`. A conta GitHub `fredmourao-ai` nao identifica qual modelo executou o trabalho. Na descricao, distinguir agente criador, contribuidores/revisores e identidade GitHub, sem inventar proveniencia. Em PRs antigas com origem desconhecida, informar `[Agente não identificado]`; nao aceitar esse marcador para PRs novas. A regra de titulo nao concede privilegio comercial e nao exige nova revisao humana por si so. Fonte: `docs/knowledge/agent-pr-attribution.md`.
<!-- /AGENT_PR_TITLE_IDENTITY_V1_20261108 -->

<!-- MCP PRIMEIRO: executar chamada MCP autorizada antes de rotas alternativas; respeitar MFA, RBAC, grants e leases; consultar docs/ops/mcp-first-agent-execution-2026-11-05.md. -->
<!-- OWNER_PROVISIONING_SECURE_ROUTE_20261109 -->
> **Regra do proprietário (09/11/2026) — Criação, injeção e solicitação de credenciais por rota segura:**
> O agente pode criar, injetar ou solicitar valores de credenciais quando necessário para a operação, mas deve obrigatoriamente provisionar exclusivamente por rota segura (cofres protegidos, diretórios locais protegidos como `~/.shopvivaliz/secrets/` em modo restrito 0600/DPAPI, GitHub Actions secrets autenticados ou variáveis de ambiente de processo isolado).
> É estritamente proibido expor, imprimir, comitar ou trafegar valores secretos em texto puro em logs públicos, repositórios git, PRs abertas ou chats sem proteção.
<!-- /OWNER_PROVISIONING_SECURE_ROUTE_20261109 -->

<!-- OWNER_CLOUDFLARE_CREDENTIAL_ROUTE_PREFLIGHT_20261107 -->
> **Gate de credenciais e rotas (owner, 07/11/2026):** antes de pedir novo token, senha, login ou aprovacao para tarefas Cloudflare/Marina, EXECUTAR `python3 scripts/agent_cloudflare_preflight.py --required-secret CLOUDFLARE_API_TOKEN` (usar `CLOUDFLARE_DNS_EDIT_TOKEN` ou `CLOUDFLARE_MARINA_TUNNEL_TOKEN` como argumento conforme a operacao) na identidade GitHub CLI ja autenticada (no backend `ubuntu`, nao `root`) e conferir os demais cofres/perfis reais. Em 07/11, GitHub Actions de `Vivaliz-site/site-shopvivaliz` confirmou **nomes** `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_DNS_EDIT_TOKEN` e `CLOUDFLARE_MARINA_TUNNEL_TOKEN`; Chrome no Fred-Win estava autenticado na aplicacao Cloudflare Access da Marina. O GitHub Secret **nao** esta automaticamente disponivel ao processo da VM, nem o nome prova escopo `Access Apps: Edit`: usar workflow oficial com secret injetado ou UI autenticada/grant apropriado, sem expor/copiar valor. Se fonte inacessivel, classificar `not_verified`, jamais `absent` por inferencia. Quando houver rota ja provisionada, **NAO pedir nova credencial ao operador**. Nao contornar recusas explicitas, MFA, RBAC, owner grants, leases, branch protection ou politica da plataforma.
> **Fonte de verdade e testes:** `docs/knowledge/agent-credential-availability-2026-11-02.md`, `docs/knowledge/host-routes-matrix.md`, `tests/test_agent_cloudflare_preflight.py`. O preflight consulta somente metadados de nomes/presenca, nao o conteudo secreto. Persistir estado/identidade/escopo/tentativa/resultado nao secreto antes de marcar bloqueio.
<!-- /OWNER_CLOUDFLARE_CREDENTIAL_ROUTE_PREFLIGHT_20261107 -->

<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->
> **Fonte operacional prevalente (revalidada em 06/11/2026, Auditoria Externa V5):** o MCP central ShopVivaliz Remote Control e o Orquestrador expoem backend (always-free-arm-1787907847-26), Fred-Win, KOCEPSV e Marina (marina-a1-4c24g) por schemas publicados. A Marina foi testada por admin_command_run/task_submit com exit_code=0; conferir novamente schema e grants da sessao antes de cada operacao, sem inferir permissao somente de hosts_list. O MCP independente da Marina foi reportado localmente ativo em 04/11, mas seu acesso publico autenticado nao esta certificado neste escopo. A VM shopvivaliz-free-a1 foi excluida e nunca e destino. MFA, RBAC, branch protection, leases, revisao humana e recusas explicitas da plataforma permanecem validos. Documentos de 29/10 e 01/11 com restricoes anteriores sao HISTORICOS, NAO OPERACIONAIS. Registro historico de referencia para o preflight: docs/knowledge/current-topology-and-access-2026-10-29.md, sem forca de autoridade sobre os schemas atuais. Fonte: docs/ops/mcp-first-agent-execution-2026-11-05.md.
<!-- /MCP_ROUTE_CANONICAL_V5_20261106 -->

<!-- MCP_FOREGROUND_DURABLE_AGENT_GATE_20261106 -->
> **MCP FOREGROUND/DURAVEL (06/11/2026):** nunca encerrar tarefa por `foreground_budget_exceeded_use_task_submit` nem diagnosticar como falta de credenciais ou permissao. O contrato sincrono de `admin_command_run(durable=false)` so aceita timeout de ate **5 segundos**; para comandos mais longos/incertos usar **primeiro** `task_submit` com `request_id` estavel, `task_status` ate terminal, `exit_code=0` e readback especifico. Se a recusa foreground ocorreu **antes da aceitacao**, trocar para a fila MCP; se resposta indeterminada, reconciliar audit/task sem repetir efeitos. `queued` NAO e conclusao. **401/403, recusas expressas, MFA, RBAC e aprovacao humana nao sao contornaveis** pela fila. Nao confundir `Allow all tools` no Admin Console com modo de aprovacao do ChatGPT nem com limite de transporte. Fonte: [contrato MCP primeiro](docs/ops/mcp-first-agent-execution-2026-11-05.md).
<!-- /MCP_FOREGROUND_DURABLE_AGENT_GATE_20261106 -->

<!-- OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105 -->
> **Regra do proprietario (2026-11-05): nenhum agente cria restricao ou bloqueio sem aprovacao expressa do dono.** Isso vale para qualquer agente (Claude, GPT, Codex, Gemini, Copilot, workers e subagentes) e para qualquer lugar: instrucoes, documentacao, codigo, workflows, testes de contrato, configuracao de MCP, permissoes, politicas e grants. Nao adicionar, reintroduzir nem endurecer proibicao, trava ou "NAO USE" por conta propria; se achar que algo precisa de limite, propor ao dono em PR ou pergunta e esperar a aprovacao. Restricao que o dono diga que nao aprovou deve ser removida quando ele pedir. Protecoes que o dono ja aprovou continuam valendo, e o agente nao as enfraquece por conta propria. Contexto: a proibicao de SSH direto, RDC e Bastion foi colocada sem aprovacao e retirada em 2026-11-05.

<!-- OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->
> **ORDEM EXPRESSA DO PROPRIETARIO — MCP PRIMEIRO, TENTATIVA REAL OBRIGATORIA:** antes de acionar RDC, SSH, RustDesk ou alegar que uma tarefa remota ShopVivaliz esta bloqueada, identificar o MCP administrativo disponivel **na sessao atual** e **executar uma chamada MCP adequada e autorizada** para o host/operacao. `hosts_list`, `claude mcp list` e `codex mcp list` isolados NAO contam como tentativa de execucao. Quatro hosts ativos (backend, Fred-Win, KOCEPSV e `marina-a1-4c24g`): priorizar o MCP central ou Orquestrador quando o schema e o grant do host autorizarem. O MCP independente `shopvivaliz-marina-admin` complementa a rota central. Conferir `task_status` e jamais acessar a VM excluida. Um timeout de RDC/GUI NAO e motivo para ignorar MCP.
> **EVIDENCIA OBRIGATORIA:** registrar `remote_access_attempts` com host, ferramenta, horario, `request_id`/`task_id`, resultado e proxima acao. Diferenciar `MCP_ATTEMPTED_OK`, `MCP_ATTEMPTED_FAILED`, `MCP_NOT_EXPOSED`, `MCP_NOT_AUTHORIZED` e `MCP_NOT_APPLICABLE`. Sem chamada nao afirmar que tentou MCP. Falha MCP comprovada permite fallback independente e autorizado, sem contornar recusas, MFA, RBAC, lease ou grant; prosseguir ate validacao.
> **FONTE DE VERDADE:** [Contrato MCP primeiro](docs/ops/mcp-first-agent-execution-2026-11-05.md). Vale para ChatGPT dev/atendimento, Codex, Claude Code, Gemini e subagentes; sessoes abertas precisam carregar estas instrucoes ou verificar sua versao atual.
<!-- /OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->

> **PRIORIDADE MCP DO OWNER: tentar de verdade, NAO apenas listar.** Antes de recorrer a RDC, SSH ou declarar bloqueio de uma tarefa ShopVivaliz, chamar a ferramenta administrativa MCP disponivel e autorizada na sessao atual. Nos quatro hosts, inclusive Marina, tentar a rota administrativa MCP central quando explicitamente publicada e autorizada (`admin_command_run` ou `task_submit` e depois `task_status`); o MCP independente da Marina complementa a central. Registrar resultado real em `remote_access_attempts`; nao confundir `claude mcp list`/`codex mcp list` com uma tentativa. Se o MCP estiver ausente, registrar `MCP_NOT_EXPOSED`, investigar e seguir por alternativa legitimamente autorizada. Ver `docs/ops/mcp-first-agent-execution-2026-11-05.md`.

<!-- AGENT_NATIVE_MCP_ADMIN_ROUTE_V1 -->
## Preferencia operacional por MCP administrativo (Gemini e agentes autonomos)

Ao operar os quatro hosts ShopVivaliz, seguir o inventario atual de `AGENTS.md#rota-mcp-prioritaria-de-quatro-hosts`. Descobrir primeiro o MCP registrado e efetivamente autenticado no runtime do agente: central `shopvivaliz-remote-control` para backend, Fred-Win, KOCEPSV e Marina quando o grant e o schema efetivo permitirem, e MCP independente `shopvivaliz-marina-admin` para funcoes proprias da Marina; nenhum inventario estatico concede permissao por si so. Tentar a chamada administrativa minima permitida e validar identidade, permissao e resultado antes de declarar o MCP operacional. A presenca de um host em `hosts_list` nao comprova que `admin_command_run`, `service_action` ou `task_submit` estejam autorizados para ele.

Quando o runtime nao publicar o MCP esperado, verificar registro/configuracao/escopo e credenciais **ja provisionadas** sem expor ou transportar secrets; nao inventar ferramentas ou assumir root universal. Respeitar os grants de seguranca, MFA, controle de sessao e recusas do provedor. Usar SSH/RDC/OCI somente nas situacoes tecnicamente justificadas pelo runbook, preservando diagnosticos e correcoes independentes. Para Marina, o cliente nativo e o `headersHelper` documentados em `docs/knowledge/marina-mcp-admin-agent-access.md` sao caminhos existentes; nunca copiar tokens ao chat ou para outro host. Seguir `AGENTS.md` e `docs/knowledge/agent-rules.md` como fonte de verdade.
<!-- /AGENT_NATIVE_MCP_ADMIN_ROUTE_V1 -->

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

<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
## REGRA ABSOLUTA: todo erro identificado exige correcao real e validacao

Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, bloqueio tecnico, resultado incorreto ou problema de validacao detectado **nao pode ser ignorado, apenas documentado ou encerrado sem remediacao**.

Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

**Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicitos e gate de I/O. Se a tarefa/automacao for `SOMENTE LEITURA`, **nao executar mutacoes**: registrar e encaminhar cada defeito ao mecanismo de reparo autorizado ja existente. Proibido contornar recusas por conectores, shell ou agentes alternativos ou duplicar agentes, monitorias, automacoes, tarefas, bindings e PRs.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
> **AUDITORIA EXTREMA V5 = UI REAL OBRIGATORIA.** Antes de declarar `APTO` ou `CONCLUIDO`, o proprio agente percorre cada jornada material na interface grafica real (navegacao, cliques, preenchimento, resultado visivel, reload/revisita, persistencia e evidencia visual com ambiente/release). API, terminal, SQL, codigo, screenshot isolado, mocks e testes apenas headless nao substituem prova pela UI. A auditoria inclui investigacao, correcao e reauditoria visual apos reparo. Nao ocultar uma UI marcando `browser.required=false` ou `journey.ui=false`. Somente componentes comprovadamente sem UI podem usar excecao com inventario negativo documentado e evidencias. Em bloqueio de sessao, esgotar rotas autorizadas; nao burlar MFA/permissaos. Leia e obedeça a secao `EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1` de `AGENTS.md` antes da tarefa.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- RESUME_EXECUTION_GATE_V1 -->
## Execução obrigatória ao receber Retome / Continue / Prossiga / Siga

Para qualquer executor, subagente ou ferramenta neste projeto: carregue `AGENTS.md` e a documentação aplicável do `main`; persista prova `rules_loaded` (SHA/ref/data). `Retome` significa **executar agora** a próxima ação do checkpoint, não só relatar status nem pedir aprovação genérica já fornecida. Preserve sessões e ownership; corrija falhas, tente rotas seguras alternativas, teste e valide o objetivo original antes de encerrar. Continue como `RUNNING` com `next_action` específico enquanto existir caminho autorizado; `CONCLUIDO` exige evidência E2E fresca, e `BLOCKED_EXTERNAL` exige causa externa, alternativas exauridas e condição exata para retorno. Quando existir, use `agent_task_state.py terminal --task <task_id>` antes de declarar conclusão; ACK, erro transitório, PR, merge, `Pensando` e mensagem intermediária não são término. Não contorne MFA, CI, approvals ou controles. Não prometa continuidade fora de sessão sem checkpoint/controlador real. O agente delegado segue esta mesma regra.
<!-- /RESUME_EXECUTION_GATE_V1 -->

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA:** também é corretiva. Auditor com acesso autorizado corrige, testa e reaudita; auditor read-only não encerra em relatório, devendo encaminhar achados a executor autorizado até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canonico aos hosts ShopVivaliz — revisao 2026-10-29

**Antes de operar:** ler `docs/knowledge/host-access.md` e `docs/knowledge/current-topology-and-access-2026-10-29.md`; registrar revisao e confirmar host/identidade/permissoes ao vivo.

- **Producao web/deploy/servicos do site:** `marina-a1-4c24g` (OCI ESPOSA, privado documentado `10.80.1.116`; Tailscale observado `100.118.170.46`). RDC 1/2 direto para operacoes autorizadas; runner Marina para deploys versionados.
- **Backend/controller/browser:** `always-free-arm-1787907847-26` (OCI PRINCIPAL, privado `10.0.1.38`; Tailscale observado `100.66.174.74`). MCP central so para hosts presentes e validos em sua allowlist; navegador corporativo Dev/Atendimento permanece no backend.
- **Origem web `shopvivaliz-free-a1` aposentada/excluida conforme proprietario em 2026-10-29**; `10.0.1.112:22` sem rota. Proibido acionar, publicar ou recriar como fallback. Confirmacao `TERMINATED` na OCI exige inventario de lifecycle.
- **MCP central CORRIGIDO e testado em 06/11/2026:** schema do cliente e execucao administrativa na Marina foram validados com exit_code=0. Nao confundir acesso funcional com autorizacao irrestrita; consultar schema, grants e lease. Host legado excluido.
- **MCP independente da Marina:** reportado ativo localmente em 04/11/2026; autenticacao do endpoint publico e grant da sessao exigem prova E2E distinta. Nao inferir disponibilidade publica de systemctl active nem negar que o MCP central alcance Marina.
- Usar SSH privado/Tailscale somente apos comprovar autenticacao/host key; OCI/GitHub control plane para bootstrap/recovery autorizado; GUI real quando exigida; Windows sao apoio, nao producao.
- Identidade de host, `sudo`, RBAC, lease/fencing, CI e releases imutaveis devem ser validados; nunca publicar secrets, copiar perfis ou contornar recusa de privilegio.
<!-- /SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO CORRETIVO GLOBAL:** em qualquer tarefa, diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`. Enquanto houver ação executável, permaneça `RUNNING`; só `BLOCKED_EXTERNAL` comprovado permite encerrar sem corrigir. Siga `AGENTS.md` e `REGRAS-AGENTES-CENTRALIZADAS.md`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **BOOTSTRAP OBRIGATÓRIO DO GEMINI:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Releia em retomadas ou quando o arquivo mudar. Em conflito, `AGENTS.md` prevalece.

# Protocolo IA-to-CLI obrigatório

Antes de qualquer alteração, carregue e siga integralmente o protocolo canônico:

@./AI-TO-CLI-PROTOCOL.md

Ele complementa as regras específicas do projeto. Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.

## Auditoria Extrema — leitura obrigatória

Em qualquer Auditoria Extrema, leia primeiro `AUDIT_POLICY.md` e todo o conjunto em `docs/quality/`: `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, `AUDIT_UNIVERSAL_COVERAGE_V1.md`, `AUDIT_SELF_TEST_V1.md` quando aplicável e `AUDIT_OVERLAY.md`. A regra vale para investigação, correção, melhorias, reauditoria e caça a unknown unknowns.

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

