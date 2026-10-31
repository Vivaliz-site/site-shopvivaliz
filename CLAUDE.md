## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Verificacao posterior, prevalente (2026-10-29):** o MCP central do backend foi corrigido e implantado (PRs #3391/#3392): `hosts_list` agora divulga **somente backend, Fred-Win e KOCEPSV**; `shopvivaliz-free-a1` foi excluida e rejeitada. Marina segue fora da allowlist MCP central; seu MCP proprio OpenAI esta inativo, embora haja RDC direto, SSH privado verificado e tunel SSH do **MEI** ativo para Marina (HTTP 200). O PR #3395 bloqueou 20 jobs legados na origem, sem certificar as funcoes substitutas. O controlador 24x7 ainda exige reconciliacao sob lease valido e o runner `mei-ci-a1` permanece sem registro. **Toda afirmacao mais antiga de "MCP central ainda inclui a origem" neste arquivo e um snapshot anterior, agora superado.** Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **INSTRUÇÃO OPERACIONAL ATUAL PARA TODOS OS AGENTES (2026-10-29):** antes de qualquer execução ou `Retome`, ler no `main` `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md` e o runbook do módulo; registrar SHA/checkpoint e validar identidade e autorização reais. **Produção web/DB/workers = `marina-a1-4c24g` (OCI ESPOSA), via RDC direto comprovado. Backend/controller/browser/MCP central = `always-free-arm-1787907847-26` (OCI PRINCIPAL). `shopvivaliz-free-a1` foi excluída e NÃO é destino válido; nunca reconectar, recriar ou substituir seu alias por Marina.** O MCP central ainda contém inventário antigo e não alcança Marina; seu MCP independente/túnel ainda não está ativo/registrado. SSH privado, RDC 2, sudo e OCI exigem autorização/prova por canal. Workflows/scripts antigos que apontem para a origem não devem ser executados, mesmo que relatórios históricos os recomendem; corrigir de forma revisada, testar e validar antes do uso.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

<!-- OCI_DUAL_ACCOUNT_CANONICAL_V1 -->
## OCI: duas contas Oracle Cloud (PRINCIPAL / ESPOSA)

Antes de executar operacoes Oracle Cloud, leia obrigatoriamente [docs/ops/oci-dual-account-access.md](docs/ops/oci-dual-account-access.md). Essa e a referencia canonica de arquitetura, configuracao, nomes dos GitHub Actions secrets, comandos permitidos, validacao e limites operacionais. Ambas as contas foram autenticadas de forma independente via GitHub Actions (run 38908995411): OCI_PRINCIPAL_AUTH=PASS e OCI_ESPOSA_AUTH=PASS. Nao confundir esse resultado com acesso OCI nativo pelo MCP do backend, ainda nao comprovado. Nao copiar, imprimir, registrar ou publicar chaves privadas. Preservar isolamento de tenancies, IAM e aprovacoes para mudancas de recursos.
<!-- /OCI_DUAL_ACCOUNT_CANONICAL_V1 -->

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


<!-- CREDENTIAL_REPROMPT_GATE_V1 -->
## Credencial ChatGPT ja provisionada: preflight obrigatorio antes de solicitar senha

Para `atendimento@shopvivaliz.com.br` ou `dev@shopvivaliz.com.br`, o agente
(GPT, Claude, Codex, Gemini, subagentes e controladores) deve, **antes de
solicitar ao usuario senha, OTP ou novo provisionamento**, confirmar a sessao
correta e executar na VM backend, via MCP autorizado, a verificacao somente
de metadados:

`python3 scripts/chatgpt_credential_preflight.py --session atendimento`
(ou `--session dev` para o perfil Dev).

- `saved_entry=PRESENT` / `DENY_EXISTING_CREDENTIAL`: a senha ja possui
  referencia salva no perfil dedicado. **Nao solicitar senha novamente**;
  primeiro reparar a integracao navegador/GNOME Keyring e tentar o
  preenchimento **nativo e autorizado**, sem ler ou exportar o segredo.
- `saved_entry=NOT_VERIFIED` / `DENY_ABSENCE_NOT_PROVEN`: perfil
  inacessivel, banco ocupado, erro de probe ou ausencia nos locais consultados
  **nao** prova que a credencial nao existe. Recuperar transporte/MCP,
  consultar as demais fontes protegidas previstas no runbook e continuar.
- O probe jamais concede permissao para pedir senha: nova intervencao humana
  somente apos evidencia independente de credencial ausente/revogada/invalida,
  ou desbloqueio/consentimento/MFA humano obrigatorio pelo fluxo oficial.
- Um registro salvo **nao prova** que a senha e valida ou que o ChatGPT esta
  autenticado. Validar cada sessao pelo email exato em `/api/auth/session`.
- Nunca copiar senha do iPhone ou navegador para terminal, Git, chat, logs,
  processo ou outro perfil. Nunca tentar descriptografar/mostrar a credencial.

Evidencia obrigatoria do preflight: apenas status, sessao verificada, timestamp
e acao permitida, **sem** qualquer valor secreto. Se a VM nao estiver
acessivel, registrar `SOURCE_NOT_VERIFIED`; nao converter isso em
`CREDENTIAL_ABSENT` nem pedir novamente a mesma senha.
<!-- /CREDENTIAL_REPROMPT_GATE_V1 -->

<!-- CREDENTIAL_REPROMPT_FAST_MCP_V1 -->
### Execucao rapida do preflight pelo Claude/MCP (evitar fila e timeout)

Na VM backend, executar o preflight pelo **ShopVivaliz Remote Control**
`admin_command_run` com os argumentos explicitos abaixo. O comando e
somente leitura e retorna em chamada inline, sem `task_submit`, `Monitor`,
`task_status` repetido ou `Bash` local:

- `host="always-free-arm-1787907847-26"`
- `command="python3 /home/ubuntu/shopvivaliz-claude-workspace/site-shopvivaliz/scripts/chatgpt_credential_preflight.py --session atendimento"`
- `timeout=5`
- `durable=false`

Para Dev, trocar exclusivamente `--session atendimento` por `--session dev`.
O timeout do adaptador pode transformar a solicitacao em tarefa duravel se
esses parametros forem omitidos; **nao** fazer polling longo de credencial.
Se a chamada inline nao for possivel, a fonte fica `NOT_VERIFIED`, nao
`ABSENT`, e continua proibido pedir novamente a senha sem prova.
<!-- /CREDENTIAL_REPROMPT_FAST_MCP_V1 -->


<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA:** também é corretiva. Se houver autorização de escrita, corrigir + testar + reauditar. Se o auditor for somente leitura/independente, o relatório não é estado terminal: encaminhar os achados a executor autorizado e manter a tarefa aberta até correção e revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canonico aos hosts ShopVivaliz — revisao 2026-10-29

**Antes de operar:** ler `docs/knowledge/host-access.md` e `docs/knowledge/current-topology-and-access-2026-10-29.md`; registrar revisao e confirmar host/identidade/permissoes ao vivo.

- **Producao web/deploy/servicos do site:** `marina-a1-4c24g` (OCI ESPOSA, privado documentado `10.80.1.116`; Tailscale observado `100.118.170.46`). RDC 1/2 direto para operacoes autorizadas; runner Marina para deploys versionados.
- **Backend/controller/browser:** `always-free-arm-1787907847-26` (OCI PRINCIPAL, privado `10.0.1.38`; Tailscale observado `100.66.174.74`). MCP central so para hosts presentes e validos em sua allowlist; navegador corporativo Dev/Atendimento permanece no backend.
- **Origem web `shopvivaliz-free-a1` aposentada/excluida conforme proprietario em 2026-10-29**; `10.0.1.112:22` sem rota. Proibido acionar, publicar ou recriar como fallback. Confirmacao `TERMINATED` na OCI exige inventario de lifecycle.
- **MCP central DESATUALIZADO:** `hosts_list` ainda inclui origem excluida e omite Marina. Nao renomear o host legado nem pressupor que `admin_command_run` do central opera na Marina.
- **MCP independente Marina NAO APTO:** `shopvivaliz-marina-mcp.service` e `shopvivaliz-marina-mcp-tunnel.service` inativos; Cloudflare admin `active` nao comprova registro de conector OpenAI. Seguir `marina-direct-mcp/README.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md` antes de anunciar disponibilidade.
- Usar SSH privado/Tailscale somente apos comprovar autenticacao/host key; OCI/GitHub control plane para bootstrap/recovery autorizado; GUI real quando exigida; Windows sao apoio, nao producao.
- Identidade de host, `sudo`, RBAC, lease/fencing, CI e releases imutaveis devem ser validados; nunca publicar secrets, copiar perfis ou contornar recusa de privilegio.
<!-- /SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->

<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto obrigatorio em toda tarefa
Acione o plugin Gepeto junto com Superpowers em toda tarefa e retomada, sem nova mencao do usuario. Leia e cumpra `GEPETO-POLICY.md`. Se o runtime nao expuser o plugin, registre `GEPETO_UNAVAILABLE`, informe a limitacao e continue o trabalho autorizado sem simular participacao. Aplicar um plugin nao comprova delegacao nem revisao independente.
<!-- /GEPETO_EVERY_TASK_V1 -->

<!-- CLAUDE_READ_ROOT_AGENTS_FIRST_V1 -->
> **BOOTSTRAP OBRIGATÓRIO DO CLAUDE:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Em particular, leia `docs/AGENT-REMOTE-ACCESS.md` antes de qualquer operação em host/VM/runtime/browser.

@docs/knowledge/claude-vm-bootstrap.md
@docs/AGENTS.md
<!-- audit-refs: AUDIT_POLICY.md docs/quality/EXTREME_AUDIT_PROTOCOL.md docs/quality/AUDIT_RUNTIME_PARITY_V1.md docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md docs/quality/AUDIT_SELF_TEST_V1.md docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md -->
<!-- gate: FINAL_RESPONSE_DEPLOY_GATE_V1 — resposta final só após validação pós-deploy completa -->


<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **AUDITORIA CORRETIVA OBRIGATÓRIA:** para qualquer auditoria, não apenas extrema, siga `AGENTS.md` e `REGRAS-AGENTES-CENTRALIZADAS.md`: investigar causa raiz, corrigir achados materiais seguros/autorizados, prevenir recorrência quando pertinente, testar e reauditar. Não encerrar em relatório de erros. APTO/CONCLUIDO exige evidência fresca pós-correção/E2E aplicável.
<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> **DIAGNÓSTICO CORRETIVO GLOBAL:** em qualquer tarefa, diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`. Enquanto houver ação executável, permaneça `RUNNING`; só `BLOCKED_EXTERNAL` comprovado permite encerrar sem corrigir. Siga `AGENTS.md` e `REGRAS-AGENTES-CENTRALIZADAS.md`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
<!-- AUDIT_ABSOLUTE_V5_ENTRYPOINT -->
Leia primeiro `AGENTS.md`. Para auditoria/aptidão, cumpra `AUDIT_ABSOLUTE_GATE_V1.md`, `AUDIT_BROWSER_E2E_REAL_V1.md`, `AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `AUDIT_PROJECT_REQUIREMENTS_V1.md` e o manifesto local de requisitos. Somente o certifier pode autorizar APTO.


<!-- AUDIT_MERGE_ENFORCEMENT_V1 -->
Leia `docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md`; preserve o governance bridge e o Absolute Audit Main Guard. Somente o certifier absoluto autoriza APTO.

<!-- TASK_CONTINUITY_ENFORCEMENT_V3 -->
## Continuidade obrigatória até estado terminal

- Estados finais permitidos para uma tarefa iniciada: `CONCLUIDO` com validação fresca, ou `BLOCKED_EXTERNAL` com impedimento externo objetivo e intransponível após esgotar alternativas seguras.
- Pedido explícito do usuário para executar, implementar, corrigir, auditar, resolver ou continuar até conclusão, somado à autorização operacional vigente, já satisfaz gates genéricos de aprovação de plano/design para ações reversíveis e dentro do escopo. Não parar apenas para pedir "posso continuar?" ou aprovação intermediária equivalente.
- Essa autorização não elimina confirmações obrigatórias de segurança/plataforma para ação destrutiva ou irreversível não autorizada exatamente, cobrança real, segredo, login/CAPTCHA/recovery ou ampliação material de escopo.
- Falha de ferramenta, plugin, CLI, API, navegador, sessão, runner, workflow, timeout ou rota primária é estado `RUNNING`, nunca terminal enquanto existir fallback seguro ou próxima ação executável.
- Tarefa simples segue a mesma regra: progresso parcial, diagnóstico ou erro recuperável não autorizam encerrar.
- Quando o runtime tiver acesso ao repositório, persistir o estado em `scripts/agent_task_state.py`; antes de alegar término, o gate `terminal --task <id>` deve aceitar o estado.
- Contrato detalhado: `docs/knowledge/task-continuity.md`; protocolo canônico de conclusão: `AI-TO-CLI-PROTOCOL.md`.
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


<!-- FREE_PROVIDER_FALLBACK_V2 -->
## Rotinas persistentes: IA gratuita obrigatoria e ordem de fallback
- Camada primaria: Gemini Flash elegivel ao nivel gratuito e Ollama local;
  ambos sao os unicos modelos autorizados nas tentativas autonomas de background.
- Gemini Flash requer que a credencial/projeto seja elegivel ao nivel gratuito;
  nome do modelo por si so nao prova que a conta Google nao tenha faturamento.
- Primeira alternativa fora da camada gratuita: ChatGPT normal na sessao
  ja autenticada, com lease/checkpoint. ChatGPT Work pode preceder CLI.
- Codex e Claude so podem ser acionados como ultimas alternativas para tarefa
  interativa, finita, com aprovacao humana explicita e controle de cota.
- Cron, controller, worker e bridges nao podem promover fallback pago por
  variavel de ambiente legada, healthcheck ou indisponibilidade do Gemini.
- Em caso de cota gratis esgotada: registrar motivo seguro, preservar RUNNING
  e pedir a proxima retomada autorizada, sem cobrar silenciosamente.
- Prevenir recorrencia: instalador escreve paid fallback=0; execucao background
  ignora paid fallback=1 e bridges pagos negam acesso por padrao.
<!-- /FREE_PROVIDER_FALLBACK_V2 -->
