# Instrucoes de agentes ShopVivaliz (indice executivo)

<!-- OWNER_CREDENTIAL_ROUTE_DISCOVERY_20261107 -->
**REGRA DO PROPRIETARIO: MCP PRIMEIRO.** Em todas as tarefas nos hosts, identificar o schema e executar chamada real autorizada do MCP antes de alegar falta de acesso; conferir grants, task_id, readback e limites. Fonte: docs/ops/mcp-first-agent-execution-2026-11-05.md. A checagem read-only do workflow shopvivaliz-remote-access.yml (run 42429462524, CLOUDFLARE_ACCESS_APPS_READ=true) nao prova poder de escrita nem dispensa autorizacao de outro provedor.
<!-- /OWNER_CREDENTIAL_ROUTE_DISCOVERY_20261107 -->


<!-- CONTEXT_MINIMIZATION_V1_20261113 -->
Este arquivo AGENTS.md e seu espelho byte-a-byte CLAUDE.md sao o ponto de entrada ENXUTO. Regras detalhadas vigentes permanecem no repositorio; apenas se remove repeticao e historico da janela automatica. **Nao desativa controles, nao cria grants e nao declara testes aprovados.** Snapshot anterior recuperavel pelo historico Git. Nao importar indiscriminadamente documentos grandes: consultar a fonte exigida por tarefa. Alteracoes em arquivos de governanca seguem PR, gates e regras vigentes.
<!-- /CONTEXT_MINIMIZATION_V1_20261113 -->

## Leitura canonica, precedencia e escopo

**Antes de tarefas ShopVivaliz e em retomadas**, no main vigente de Vivaliz-site/site-shopvivaliz, ler nesta ordem e registrar os SHAs: (1) docs/knowledge/atendimento-chatgpt-bootstrap.md; (2) docs/knowledge/host-access.md; (3) docs/knowledge/README.md; (4) docs/knowledge/agent-rules.md. Depois ler as secoes pertinentes de AGENTS.md, AUDIT_POLICY.md, REGRAS-AGENTES-CENTRALIZADAS.md, docs/AGENTS.md, docs/knowledge/project.md, runbooks e regras especificas do projeto. Documento historico nao substitui fonte viva; ao detectar conflito, seguir instrucao canonicamente mais recente e preservar protecoes. O preflight e a prova rules_loaded/AGENT_DOCS_PREFLIGHT_V1 seguem conforme docs/AGENT-DOCS-PREFLIGHT.md. Este indice nao dispensa o preflight exigido por operacoes.

Referencias historicas da interface Claude, **citadas como texto e nao importadas automaticamente**: `@docs/knowledge/claude-vm-bootstrap.md` e `@docs/AGENTS.md`. Imports literais sem demanda consomem a mesma janela de contexto; carregar arquivos especificos somente quando necessarios.

## Regra do proprietario: bloqueios e protecao
<!-- OWNER_ONLY_RESTRICTIONS_20261110 -->
<!-- OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105 -->
Somente o proprio proprietario pode **incluir ou agravar novas restricoes**, gates, bloqueios ou regras impeditivas. Nenhum agente pode implementa-las nem criar PR que as acrescente, mesmo com aprovacao generica. Excecao historica de 10/11 nao vale para futuras restricoes. Agentes podem, por instrucoes vigentes de 12/11, apenas restaurar o baseline protetivo ja aprovado quando provado, sem ampliar abrangencia e apos integracao formal quando exigida; isso nao autoriza novas acoes externas. MFA, RBAC, recusas do provedor, grants, P2, leases/fencing, branch protection e aprovacao humana formal permanecem exigiveis. Fonte integral: docs/knowledge/owner-only-restrictions-2026-11-10.md.
<!-- /OWNER_ONLY_RESTRICTIONS_20261110 -->
<!-- /OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105 -->

## Topologia e caminho de execucao
<!-- MCP_ROUTE_CANONICAL_V5_20261106 -->
<!-- ACTIVE_MARINA_ROUTING_20261029 -->
Apenas VMs atuais: marina-a1-4c24g = producao web/deploy/banco/workers; always-free-arm-1787907847-26 = backend, controller, navegador corporativo. Windows: Fred-Win e KOCEPSV sao hosts auxiliares. shopvivaliz-free-a1 esta excluida e NUNCA e destino; nomes e IPs legados nao autorizam acesso. Ver docs/knowledge/current-topology-and-access-2026-10-29.md, host-access.md e docs/knowledge/host-routes-matrix.md. Nao editar /home/ubuntu/shopvivaliz-deploy/current nem releases ativas.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->
<!-- OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->
MCP central shopvivaliz-remote-control e Orquestrador primeiro para os quatro hosts, verificando schema, identidade, grant e lease atuais e **executando canario real apropriado** antes de alegar bloqueio. Catalogo/status nao equivalem a execucao. Verificar MCP/Remote Control e GitHub CLI/API por rotas independentemente autorizadas, preservar lease/fencing e nao contornar MFA, CAPTCHA ou RBAC. Registrar remote_access_attempts e audit_id/task_id. MCP Marina independente e separado, quando efetivamente autorizado; SSH privado/Tailscale e rotas de recuperacao so em sua finalidade ja aprovada. RDC aposentado como meta, nao obrigatorio. Nao contornar 401/403, MFA, Cloudflare, IAM, RBAC nem recusa expressa por outra rota.
<!-- /OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->

<!-- MCP_ADMIN_CAPABILITY_BEFORE_BLOCK_20261114 -->
**MCP admin antes de declarar impedimento (diretriz do proprietario, 14/11/2026):** erro de GUI, redirecionamento de `/admin/` para `/auth/login.php` ou sessao web anonima NAO significam falta de acesso administrativo ao host. Descobrir e executar as ferramentas MCP da sessao (`admin_command_run`, `task_submit`/`task_status` e, na Marina, `marina_admin_shell` com `as_user=ubuntu` se autorizado), usando host, grant e lease vigentes; consultar a operacao oficial ja existente que resolve a tarefa e prosseguir ate readback. Catalogo de ferramentas sozinho nao e execucao.
**Diagnostico separado:** registrar evidencia para acesso MCP ao host, autenticacao da aplicacao, autorizacao comercial, fila/journal e validacao externa, sem fundir esses estados em "sem acesso". Na Amazon, `commercial_request: empty` do worker comprova fila vazia, NAO MCP inoperante. Antes de transferir a tarefa ao dono, esgotar as operacoes MCP/admin e os fluxos legitimamente autorizados; se faltar sessao da aplicacao ou mecanismo oficial de enfileiramento, informar precisamente essa dependencia. MCP admin NAO autoriza simular login, CSRF, usuario, gravar diretamente em banco ou enviar PUT/PATCH fora do publisher com journal; preservar MFA, RBAC, grants P2, leases e recusas 401/403.
**Procedimento e evidencia:** `docs/ops/mcp-first-agent-execution-2026-11-05.md#exemplo-real-marina-painel-admin-e-fila-comercial-14112026`.
<!-- /MCP_ADMIN_CAPABILITY_BEFORE_BLOCK_20261114 -->

## Continuidade, qualidade e UI
### Regra universal de continuidade e esgotamento de rotas
Aplicavel a Claude, GPT, Codex, Gemini, Ollama, MCP e automacoes: diagnosticar -> corrigir -> prevenir -> testar -> validar; nao encerrar por erro transitorio, timeout, falta de ferramenta ou PR aberta. Executar as proximas etapas legitimamente autorizadas; registrar checkpoint de estado, evidencias, next_action e impedimentos reais. Somente reportar APTO ou CONCLUIDO apos testes, merge/deploy e validacao fresca; se houver impedimento externo real, especificar BLOCKED_EXTERNAL e acao remanescente sem prometer execucao inexistente. Respeitar ownership, isolamento de sessao CLI, provas de UI E2E quando sistema tem interface, idempotencia e integridade. Proibido executar compras reais apenas para testar. Fonte: AI-TO-CLI-PROTOCOL.md, docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md e docs/knowledge/agent-rules.md.

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
O checklist formal e opcional; sua ausencia nao impede registrar progresso em P0/P1. Checklist ja iniciado e controles independentes seguem exigiveis para conclusao. Nao criar novo gate universal. Fonte: docs/knowledge/agent-rules.md e scripts/agent_task_state.py.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Governanca proporcional e referencias de auditoria
<!-- SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1 -->
- P0 (somente leitura/preparacao): executar diagnosticos e validacoes sem nova aprovacao.
- P1 (mudanca tecnica reversivel): executar pelo grant ja existente, com testes e PR tecnico quando aplicavel, sem aprovacoes ou preflights duplicados.
- P2 (novo poder/efeito protegido): exige aprovacao formal efetiva do owner shopvivaliz-human quando a protecao vigente determinar, sem agente simular a identidade humana.
- NoNewPrivileges=yes em determinado processo nao equivale a poder administrativo. Falhas de seguranca, escopo desconhecido e recusas devem permanecer fail-closed, sem contornar MFA/RBAC.
<!-- /SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1 -->

**Auditoria V5:** validar o contrato detalhado de docs/quality/AUDIT_ABSOLUTE_GATE_V1.md, docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md, docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md e docs/quality/AUDIT_MERGE_ENFORCEMENT_V1; resultado APTO exige evidencias reais, nao apenas marcadores ou CI.

**Referencias adicionais de auditoria (sob demanda):** docs/quality/AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md; docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md; docs/quality/AUDIT_PROJECT_REQUIREMENTS_V1.md; scripts/absolute-audit-governance-validate.sh; Absolute Audit Main Guard; AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1; AUDIT_EXTERNAL_REMEDIATION_V1; DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1; EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1; AUDIT_UI_EVIDENCE_INTEGRITY_V2. Nenhum marcador substitui executar o ciclo de evidencia.


## Auditoria extrema V5 e integridade de evidencias
A politica AUDIT_POLICY.md determina requisitos por superficie e impede conclusao por simples relatorio. Consultar:
- docs/quality/AUDIT_ABSOLUTE_GATE_V1.md (certificacao de aptidao); docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md (ciclo de reparacao);
- docs/quality/AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md (verificar credenciais sem expor segredos); docs/quality/AUDIT_PROJECT_REQUIREMENTS_V1.md (requisitos por projeto);
- docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md (verificar merge); docs/quality/AUDIT_UI_EVIDENCE_INTEGRITY_V2.md (evidencia UI real e persistencia);
- scripts/absolute-audit-governance-validate.sh e o workflow Absolute Audit Main Guard antes de declarar APTO na branch/release efetiva.

<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->Todo defeito verificavel exige correcao, prevencao, testes e readback antes de encerramento.<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->Quando a acao externa for legitimamente negada, preservar o bloqueio e a evidencia, sem contornar permissoes.<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->
<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->Diagnostico isolado nao equivale a correcao; validar ciclo inteiro no runtime pertinente.<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->Em sistema com interface, auditoria exige interacao no navegador permitido; screenshot ou API isolados nao validam UI.<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- FINAL_RESPONSE_DEPLOY_GATE_V1 -->
Antes da resposta final, confirmar revisao, testes e efeito persistido. Mudanca versionada so conclui apos integracao e validacao pos-merge; quando ha deploy, verificar ambiente e revisao realmente implantada, executar saude e testes pós-deploy. Falha de deploy ou evidencia pendente exige retomar a correcao, nunca anunciar sucesso sem prova.
<!-- /FINAL_RESPONSE_DEPLOY_GATE_V1 -->

## Retomada e registro de progresso

<!-- GLOBAL_TASK_CONTINUITY_V8 -->
<!-- CHECKPOINT_FIRST_V9 -->
**antes da primeira** etapa material de uma tarefa, registrar checkpoint com `scripts/agent_task_state.py start`, ref/SHA, identidade, objetivo e fonte das regras. Durante a execucao, usar `scripts/agent_task_state.py progress` com evidencias e proxima acao, sem perder trabalho ja realizado. O registro de estado nao substitui a conclusao real nem cria checklist obrigatorio.
<!-- /CHECKPOINT_FIRST_V9 -->
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->

<!-- TASK_CONTINUITY_ENFORCEMENT_V3 -->
<!-- RESUME_EXECUTION_GATE_V1 -->
Ao receber **Retome**, Continue, Prossiga ou Siga, fazer preflight `rules_loaded` e executar os proximos passos autorizados, evitando reiniciar do zero, duplicar agentes, reduzir protecoes ou confundir historico com runtime. Somente concluir com `CONCLUIDO` validado; impedimento externo comprovado permanece `BLOCKED_EXTERNAL` com proxima acao precisa.
<!-- /RESUME_EXECUTION_GATE_V1 -->
<!-- /TASK_CONTINUITY_ENFORCEMENT_V3 -->

<!-- CODEX_LAST_RESORT_V1 -->
<!-- CHATGPT_RESUME_ORDER_V5 -->
Ordem de retomada para a mesma tarefa: ChatGPT comum, ChatGPT Work quando elegivel e necessario; CLI/Codex como ultimo recurso. Em rotinas persistentes usar somente provedores gratuitos aprovados, sem consumo recorrente de IA paga.
<!-- /CHATGPT_RESUME_ORDER_V5 -->
<!-- /CODEX_LAST_RESORT_V1 -->

## Continuidade, checkpoint e disciplina de custo
<!-- GLOBAL_TASK_CONTINUITY_V8 -->
<!-- CHECKPOINT_FIRST_V9 -->
<!-- TASK_CONTINUITY_ENFORCEMENT_V3 -->
<!-- RESUME_EXECUTION_GATE_V1 -->
Em "Retome", "Continue", "Siga" ou "Prossiga": consultar regras atuais e registrar rules_loaded. Antes da primeira etapa material, executar agent_task_state.py start na sessao propria e agent_task_state.py progress conforme se executa; preservar identificador, next_action, evidencias e estado da tarefa. A regra e um contrato documental: nao alegar verificacao do primeiro tool call sem observacao real. Nenhum timeout invalida trabalho sem antes investigar e usar rotas autorizadas. Estado CONCLUIDO exige evidencia real; bloqueios externos permanecem BLOCKED_EXTERNAL.
<!-- /RESUME_EXECUTION_GATE_V1 -->
<!-- /TASK_CONTINUITY_ENFORCEMENT_V3 -->
<!-- /CHECKPOINT_FIRST_V9 -->
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->
<!-- CHATGPT_RESUME_ORDER_V5 -->
Retomada humana: ChatGPT comum primeiro, Work quando autorizado, CLI em ultimo caso; estrategia chatgpt_common_then_work_then_cli. Rotinas persistentes devem preferir alternativas gratuitas comprovadas, sem custo de tokens pagos ciclicos.
<!-- /CHATGPT_RESUME_ORDER_V5 -->
<!-- CODEX_LAST_RESORT_V1 -->
Codex e recurso de ultima instancia quando alternativas adequadas nao bastarem. Nunca duplicar agente ativo nem prometer execucao automatica sem executor comprovado.
<!-- /CODEX_LAST_RESORT_V1 -->

<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
**Regra absoluta: todo erro identificado exige correcao real e validacao.** Observar RED, investigar causa raiz, corrigir e prevenir, executar GREEN e regressao, validar persistencia e UI/E2E quando aplicavel. Se nao houver autorizacao ou evidencia, manter RUNNING/NAO_APTO e next_action rastreavel. Em operacao SOMENTE LEITURA, jamais modificar o alvo: registrar para rota autorizada. **Sem bypass** de MFA, RBAC, grants, recusas ou protecoes. Nao mascarar status por CI verde ou erro de tela.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->
Para deploy comercial, confirmar paridade dos precos e custos efetivos no ERP, inclusive Amazon, mediante workflow postdeploy-erp-price-parity.yml e readback; publicacao concluida exige execucao real.
<!-- /DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->

## Credenciais e perfis
<!-- CREDENTIAL_REPROMPT_GATE_V1 -->
Antes de pedir novamente credenciais ChatGPT Dev/Atendimento, conferir sessao exata e rodar preflight de metadados via MCP, sem ler senha nem exibir secrets: scripts/chatgpt_credential_preflight.py --session dev (ou atendimento). Credencial armazenada nao prova login valido; fluxo oficial deve respeitar MFA/CAPTCHA e identidades separadas. Nunca copiar cookies ou OTP entre perfis.
<!-- /CREDENTIAL_REPROMPT_GATE_V1 -->
<!-- CREDENTIAL_REPROMPT_FAST_MCP_V1 -->
Na backend, executar chatgpt_credential_preflight.py por admin_command_run com timeout=5 e durable=false, conforme docs/knowledge/atendimento-chatgpt-bootstrap.md. Um erro de leitura representa NOT_VERIFIED, nunca ABSENT por inferencia.
<!-- /CREDENTIAL_REPROMPT_FAST_MCP_V1 -->
<!-- CHATGPT_LOGIN_TOOLS_20261113 -->
**Login ChatGPT Dev/Atendimento sem lease (decisao do proprietario, 13/11/2026; PRs #3947/#3948/#3949):** `browser_auth_open`, `browser_auth_action` (`fill_email`, `fill_password`, `fill_saved_password`, `fill_gnome_totp`, `continue`) e `browser_profile_home_open` NAO exigem lease de manutencao (se informado, ainda e validado). Foram removidos os gates `_assert_runtime_mutation` e `_assert_conversation_mutation`, o e-mail fixo e a checagem de pagina oficial, que o dono nao aprovou. Senhas ja provisionadas na backend em `/home/ubuntu/.shopvivaliz/secrets/CHATGPT_DEV_PASSWORD` e `CHATGPT_ATENDIMENTO_PASSWORD` (0600, dono ubuntu): usar `fill_saved_password` (o servidor le o arquivo; o agente nao precisa do valor) e `fill_gnome_totp` (TOTP no GNOME Keyring do `fredrdp`). Atendimento NAO usa Google: mesmo processo do dev (senha + TOTP). Nunca imprimir, commitar ou colar valores. Fluxo: `browser_auth_open` -> `continue` -> `fill_email` -> `continue` -> `fill_saved_password` -> `continue` -> `fill_gnome_totp` -> `continue` -> `browser_auth_status` = AUTHENTICATED com `identity_match`. Aba de login antiga em "Session ended" causa `auth_ui_preflight_failed`: fechar e abrir outra. Sessao sem cookies (contexto anonimo, navegador universal) recebe desafio Cloudflare "Just a moment...": nao contornar, exige acao humana; nunca fazer logout para testar. `login_email_watch` agora le o codigo do e-mail (requer OAuth Gmail readonly do backend, ainda nao provisionado). Amazon Seller Central e ERP Olist: credenciais na Marina em `/home/ubuntu/amazon-returns-deploy/shared/` (`seller-central-browser.env`, `olist-erp-browser-login.env`); logar com `amazon-returns-seller-central-auth-check.service`; ERP via navegador persistente (CDP 9226). Procedimento completo, armadilhas e deploy do MCP: `docs/knowledge/chatgpt-login-sem-lease-20261113.md`.
<!-- /CHATGPT_LOGIN_TOOLS_20261113 -->


<!-- FINAL_RESPONSE_DEPLOY_GATE_V1 -->
**Gate da resposta final:** nunca declarar entrega concluida se uma etapa executavel ainda estiver pendente. Alteracoes versionadas exigem branch, testes, PR, checks, merge e **pós-deploy** com evidencia de SHA e ambiente de destino quando houver deploy aplicavel. A **resposta final** deve identificar explicitamente bloqueios reais e proximas acoes, sem confundir commit ou CI verde com entrega operacional. Nenhum teste ou otimizacao dispensa protecoes e revisao formal exigidas.
<!-- /FINAL_RESPONSE_DEPLOY_GATE_V1 -->

## GitHub, PR e custos de IA
TODA PR de agente em pt-BR, titulo atribuido ao executor REAL, como [GPT] ou [Claude], e observacoes com provas. PR tecnica comum segue gates proporcionais; aprovacao humana formal apenas quando realmente exigida pela classe P2 ou protecao existente. Nao forcar branch, nao escrever direto em main quando protegido, nao promover release sem testes. Antes de executar comandos versionados, preservar alteracoes alheias, revisar diff e estado Git. Superpowers e Gepeto quando disponiveis, sem simular execucao externa. Em rotinas repetitivas preferir provedores gratuitos autorizados; nao executar loops com IA paga nem gastar tokens para repetir logs ou reabrir arquivos completos. Carregar somente trechos de codigo e documentos pertinentes, preferir sinteses de checkpoints. A reducao de contexto NAO altera escopo administrativo ou politicas de seguranca.
