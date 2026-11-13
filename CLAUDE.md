# Instrucoes de agentes ShopVivaliz (indice executivo)

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

## Continuidade, qualidade e UI
### Regra universal de continuidade e esgotamento de rotas
Aplicavel a Claude, GPT, Codex, Gemini, Ollama, MCP e automacoes: diagnosticar -> corrigir -> prevenir -> testar -> validar; nao encerrar por erro transitorio, timeout, falta de ferramenta ou PR aberta. Executar as proximas etapas legitimamente autorizadas; registrar checkpoint de estado, evidencias, next_action e impedimentos reais. Somente reportar APTO ou CONCLUIDO apos testes, merge/deploy e validacao fresca; se houver impedimento externo real, especificar BLOCKED_EXTERNAL e acao remanescente sem prometer execucao inexistente. Respeitar ownership, isolamento de sessao CLI, provas de UI E2E quando sistema tem interface, idempotencia e integridade. Proibido executar compras reais apenas para testar. Fonte: AI-TO-CLI-PROTOCOL.md, docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md e docs/knowledge/agent-rules.md.

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
O checklist formal e opcional; sua ausencia nao impede registrar progresso em P0/P1. Checklist ja iniciado e controles independentes seguem exigiveis para conclusao. Nao criar novo gate universal. Fonte: docs/knowledge/agent-rules.md e scripts/agent_task_state.py.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Credenciais e perfis
<!-- CREDENTIAL_REPROMPT_GATE_V1 -->
Antes de pedir novamente credenciais ChatGPT Dev/Atendimento, conferir sessao exata e rodar preflight de metadados via MCP, sem ler senha nem exibir secrets: scripts/chatgpt_credential_preflight.py --session dev (ou atendimento). Credencial armazenada nao prova login valido; fluxo oficial deve respeitar MFA/CAPTCHA e identidades separadas. Nunca copiar cookies ou OTP entre perfis.
<!-- /CREDENTIAL_REPROMPT_GATE_V1 -->
<!-- CREDENTIAL_REPROMPT_FAST_MCP_V1 -->
Na backend, executar chatgpt_credential_preflight.py por admin_command_run com timeout=5 e durable=false, conforme docs/knowledge/atendimento-chatgpt-bootstrap.md. Um erro de leitura representa NOT_VERIFIED, nunca ABSENT por inferencia.
<!-- /CREDENTIAL_REPROMPT_FAST_MCP_V1 -->

## GitHub, PR e custos de IA
TODA PR de agente em pt-BR, titulo atribuido ao executor REAL, como [GPT] ou [Claude], e observacoes com provas. PR tecnica comum segue gates proporcionais; aprovacao humana formal apenas quando realmente exigida pela classe P2 ou protecao existente. Nao forcar branch, nao escrever direto em main quando protegido, nao promover release sem testes. Antes de executar comandos versionados, preservar alteracoes alheias, revisar diff e estado Git. Superpowers e Gepeto quando disponiveis, sem simular execucao externa. Em rotinas repetitivas preferir provedores gratuitos autorizados; nao executar loops com IA paga nem gastar tokens para repetir logs ou reabrir arquivos completos. Carregar somente trechos de codigo e documentos pertinentes, preferir sinteses de checkpoints. A reducao de contexto NAO altera escopo administrativo ou politicas de seguranca.
