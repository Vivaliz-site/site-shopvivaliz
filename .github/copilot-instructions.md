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

