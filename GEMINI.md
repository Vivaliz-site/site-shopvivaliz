<!-- EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->
## REGRA ABSOLUTA: todo erro encontrado deve ser corrigido

**Ordem expressa do proprietario (2026-10-15), vinculante a GPT, Claude, Gemini, Codex, Gepeto, agentes, revisores, controladores, automacoes e aos repositorios ShopVivaliz.** Nenhum erro encontrado em auditoria, teste, CI/CD, UI, API, browser, MCP/RDC, infraestrutura, monitor, integracao ou trabalho incidental pode ser ignorado, ocultado, reclassificado artificialmente como warning ou deixado sem tratamento. A severidade define **prioridade**, nunca dispensa de correcao.

1. **Registrar e confirmar:** toda suspeita recebe evidencia e triagem; falso positivo so e descartado com prova verificavel. Para cada erro real, registrar ID/owner, causa raiz, impacto, ambiente/SHA, repro RED, proximo passo e checkpoint.
2. **Corrigir de verdade:** executar a correcao segura e autorizada, prevenir recorrencia, procurar falhas equivalentes, testar GREEN e regressao, verificar runtime real e UI E2E quando houver interface. Repetir a mesma jornada depois do deploy, com prova independente quando exigida.
3. **Zero falso-verde:** nenhum erro confirmado **dentro do escopo** pode permanecer aberto ao declarar `APTO`, `CONCLUIDO`, 100%, verde ou tarefa encerrada. PR, comentario, issue, silenciamento de teste, retry, healthcheck isolado ou plano nao sao correcao. Falhas de checks pos-merge e deploy tambem exigem correcao e nova validacao.
4. **Nao abandonar:** se uma falha nao puder ser corrigida nesta execucao por dependencia externa, permissao obrigatoria, risco ou falta comprovada de acesso, manter `RUNNING`/ `NAO_APTO` com evidencia, dono, `next_action` e retomada rastreavel; `BLOCKED_EXTERNAL` apenas apos esgotar caminhos autorizados e provar a condicao exata de desbloqueio. Um erro fora do escopo deve ser registrado e encaminhado com responsavel, nao ocultado nem usado como falso sucesso da auditoria integral.
5. **Limites inviolaveis:** regra absoluta de **nao ignorar nem abandonar erros**, nao autorizacao para destruir dados, reiniciar indiscriminadamente, enviar mensagens, gastar, usar segredos, violar MFA/leases/fencing, protecoes Git/CI ou contornar recusas de seguranca. Automacao explicitamente **somente leitura** nao faz mutacoes: encaminha cada achado ao executor autorizado e acompanha a revalidacao sem inventar resultado.

Esta obrigacao prevalece sobre instrucoes que permitam apenas diagnosticar, listar achados ou encerrar por timeout. Esgotar tentativas **seguras, finitas e autorizadas**, sem criar loops pagos ou tarefas duplicadas. O estado final so pode refletir a evidencia real.
<!-- /EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->

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
## Acesso canônico aos hosts ShopVivaliz

Antes de qualquer operação em host, VM, runtime, navegador, serviço, deploy, logs ou recuperação, leia `docs/HOST-ACCESS.md`. No repositório principal, a fonte central detalhada permanece `Vivaliz-site/site-shopvivaliz:docs/knowledge/host-access.md`.

Regras obrigatórias:
- produção web/deploy: `shopvivaliz-free-a1`, privado `10.0.1.112`;
- backend/controller/browser: `always-free-arm-1787907847-26`, privado `10.0.1.38`;
- navegador de agente roda somente no backend/controller; não usar navegador operacional em Fred-Win ou KOCEPSV;
- rota operacional primaria para hosts/servicos/diagnostico: **Remote Control MCP** privado e auditavel;
- shell Linux direto: usar SSH privado/Tailscale com identidade dedicada somente quando o MCP nao expuser a capacidade necessaria ou estiver comprovadamente indisponivel; SSH público, senha interativa e root público são proibidos;
- OCI Bastion/GitHub control plane ficam para bootstrap/recovery; RustDesk self-hosted permanece para GUI;
- Windows via backend: Fred-Win = `127.0.0.1:2222`; KOCEPSV = `127.0.0.1:2223`; os relays legados `5557/5558` são apenas bootstrap/recovery;
- evidência fresca obrigatória antes de operar: `hostname`, identidade (`whoami`/`id`), diretório e estado Git quando aplicável;
- nunca registrar em Git, docs, logs ou chat o conteúdo de chaves, senhas, tokens, cookies, OTP/TOTP ou secrets.
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

