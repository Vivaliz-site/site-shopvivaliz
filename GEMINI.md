<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canônico aos hosts ShopVivaliz

Antes de qualquer operação em host, VM, runtime, navegador, serviço, deploy, logs ou recuperação, leia `docs/HOST-ACCESS.md`. No repositório principal, a fonte central detalhada permanece `Vivaliz-site/site-shopvivaliz:docs/knowledge/host-access.md`.

Regras obrigatórias:
- produção web/deploy: `shopvivaliz-free-a1`, privado `10.0.1.112`;
- backend/controller/browser: `always-free-arm-1787907847-26`, privado `10.0.1.38`;
- navegador de agente roda somente no backend/controller; não usar navegador operacional em Fred-Win ou KOCEPSV;
- shell Linux: preferir SSH privado/Tailscale com identidade dedicada; SSH público, senha interativa e root público são proibidos;
- sem rota privada: OCI Bastion/control plane auditável é bootstrap/recovery; RustDesk self-hosted é GUI; Desktop Commander é apenas contingência;
- Windows via backend: Fred-Win = `127.0.0.1:2222`; KOCEPSV = `127.0.0.1:2223`; os relays legados `5557/5558` são apenas bootstrap/recovery;
- evidência fresca obrigatória antes de operar: `hostname`, identidade (`whoami`/`id`), diretório e estado Git quando aplicável;
- nunca registrar em Git, docs, logs ou chat o conteúdo de chaves, senhas, tokens, cookies, OTP/TOTP ou secrets.
<!-- /SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->
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

