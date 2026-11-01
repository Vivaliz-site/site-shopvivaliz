<!-- CHECKPOINT_FIRST_V9 -->
## Checkpoint obrigatorio antes de executar
Toda tarefa longa, delegacao, investigacao material ou mutacao deve executar `scripts/agent_task_state.py start` antes da primeira etapa material. Apos cada etapa, executar `scripts/agent_task_state.py progress` com evidencia e `next_action`. Na retomada, ler o checkpoint existente e preservar ownership e gates. Se o registro duravel nao estiver acessivel, falhar fechado para tarefas dependentes de retomada automatica e usar rota autorizada auditavel. Nao declarar conclusao sem validacao e `terminal`.
<!-- /CHECKPOINT_FIRST_V9 -->

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist transacional obrigatorio: todos os agentes e todas as IAs

A cada tarefa material, consultar e atualizar o checklist duravel ANTES de agir, DEPOIS de cada etapa e ANTES de escolher a proxima acao. Esta regra vale para ChatGPT/Gepeto, Codex, Claude, Gemini, Copilot, workers e subagentes. A sessao ou o controlador nao substituem a responsabilidade de cada agente em executar a proxima etapa permitida.

Usar o registro canonico `scripts/agent_task_state.py`: iniciar o checkpoint com identidade/autorizacao reais; `plan-checklist --task ID --step "..." --step "..."` antes de qualquer progresso; `next-step --task ID` para consultar o item aberto; `check-step --task ID --step-id N --evidence "prova concreta" --verification "validacao independente"` ao completar CADA item; `add-checklist-step` para nova descoberta sem apagar etapas ja comprovadas. Somente passar ao proximo item depois de verificar a conclusao do anterior. `ready` e `complete` nao substituem testes finais, E2E/UI, gates, deploy e merge.

Checklists iniciados nao podem ser sobrescritos para apagar pendencias, pular etapas, inventar provas, ou transformar bloqueios em conclusoes. Nos novos checkpoints do runtime canonico a falta de plano ou de qualquer item comprovado bloqueia `READY_TO_COMPLETE` e `CONCLUIDO`. Checkpoints legados exigem migracao explicita, preservando historico, leases, ownership, next_action e prova existente; nao criar tarefa duplicada. Falhas de ferramenta exigem rota alternativa autorizada e estado aberto, nao falsificacao de check. Todos os repositorios/agentes devem adotar o mesmo contrato ou um mecanismo equivalente com testes negativos; documento presente sozinho nao certifica adesao operacional.

Protecoes do GitHub, MFA, RBAC, segredos, gates comerciais e aprovacoes humanas continuam vigentes. Registro de check nao prova, por si, o comportamento real: confirmar os resultados nos sistemas alvo e nos certificadores independentes aplicaveis.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

**Obrigatoria para toda execucao e todo agente, independentemente do fornecedor, modelo ou interface:** ChatGPT, Codex, Claude, Gemini, agentes autonomos, bots, workers, scripts, GitHub Actions, ferramentas MCP e futuros agentes. Aplica-se a todos os repositorios e organizacoes GitHub sob gestao da ShopVivaliz, nao apenas a este repositorio.

**Nao abandonar tarefas por um bloqueio tecnico isolado.** Investigar a causa raiz, corrigir o que for seguro e autorizado, testar novamente e inventariar sistematicamente todas as rotas REALMENTE provisionadas e independentemente autorizadas antes de alegar bloqueio: MCP/Remote Control, conectores, Cloudflare/cloudflared e tuneis, GitHub CLI/API com identidade correta e credenciais protegidas, SSH privado/Tailscale, RDC 1/2, navegadores de agentes no host permitido, VMs ativas, runners/Actions, OCI, Superpowers quando disponivel e outros recursos identificados na documentacao vigente. Nao assumir disponibilidade, permissao ou sucesso sem teste e evidencia. Nao expor segredos nem criar credenciais paralelas.

Para cada rota registrar disponibilidade, identidade, escopo de permissao, lease/fencing quando aplicavel, tentativa, resultado, causa da falha, reparo e proxima alternativa. Nao repetir indefinidamente a mesma tentativa. Prosseguir nas etapas independentes, preservar checkpoints duraveis e retomada automatica autorizada. A tarefa so termina com correcao, prevencao, testes, validacao runtime/E2E real e merge apos gates quando aplicavel, ou bloqueio externo comprovado apos esgotamento de todas as rotas legitimas. Nenhum status RUNNING antigo comprova execucao ativa.

**Limites absolutos:** nao contornar MFA, CAPTCHA, RBAC, 401/403, recusa expressa, branch protection, aprovacoes, leases, isolamento de hosts ou politicas do provedor. Rota alternativa nunca pode ser usada para burlar uma negativa. Manter pendencias abertas com evidencia e proxima acao quando existir impedimento legitimo.

**Propagacao:** manter esta regra nos arquivos de instrucoes de agentes de cada repositorio ativo (AGENTS.md, CLAUDE.md, GEMINI.md, instrucoes Codex e equivalentes existentes), respeitando a governanca local; verificar aplicacao por inventario e testes, nao por presuncao.

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Verificacao posterior, prevalente (2026-10-29):** o MCP central do backend foi corrigido e implantado (PRs #3391/#3392): `hosts_list` agora divulga **somente backend, Fred-Win e KOCEPSV**; `shopvivaliz-free-a1` foi excluida e rejeitada. Marina segue fora da allowlist MCP central; seu MCP proprio OpenAI esta inativo, embora haja RDC direto, SSH privado verificado e tunel SSH do **MEI** ativo para Marina (HTTP 200). O PR #3395 bloqueou 20 jobs legados na origem, sem certificar as funcoes substitutas. O controlador 24x7 ainda exige reconciliacao sob lease valido e o runner `mei-ci-a1` permanece sem registro. **Toda afirmacao mais antiga de "MCP central ainda inclui a origem" neste arquivo e um snapshot anterior, agora superado.** Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Regra vigente para todos os agentes (2026-10-29):** ler no `main` `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md` e `docs/knowledge/agent-rules.md` antes de qualquer tarefa/retomada; registrar SHA e comprovar identidade/autorizações. **Web/deploy/DB/workers na Marina (`marina-a1-4c24g`, OCI ESPOSA), RDC direto validado; backend/MCP/controller/browser em `always-free-arm-1787907847-26` (OCI PRINCIPAL). `shopvivaliz-free-a1` foi excluída: nunca acessar, testar, reativar ou recriar, nem reutilizar seu alias.** O MCP central ainda anuncia a origem e não roteia Marina; o MCP independente/túnel da Marina está inativo e sem app registrado. RDC 2, SSH privado, OCI e privilégios exigem validação própria. Scripts/workflows históricos com destino antigo ficam proibidos para execução até correção e teste, sem troca cega por Marina.
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

