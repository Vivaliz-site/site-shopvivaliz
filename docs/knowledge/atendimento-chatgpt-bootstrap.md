<!-- OWNER_MCP_FIRST_REAL_ATTEMPT_20261105 -->
> **PRIORIDADE MCP ADMINISTRATIVO — ORDEM EXPRESSA DO PROPRIETARIO (2026-11-05).** Todo agente ShopVivaliz deve **descobrir e tentar uma chamada real** ao MCP autorizado do host antes de RDC, SSH ou concluir bloqueio. `hosts_list`/status de plugin e mero registro, nao sao teste de comando. Central `shopvivaliz-remote-control`: backend, Fred-Win, KOCEPSV; Marina: MCP independente e somente capacidades com grants validos. Executar comando/consulta MCP apropriado e registrar `remote_access_attempts` com host, ferramenta, hora, resultado e task_id. Sem ferramenta publicada, registrar `MCP_NOT_EXPOSED` e continuar apenas por fallback legitimamente autorizado; nunca alegar tentativa inexistente. Timeout do RDC NAO dispensa o MCP. Contrato completo: [MCP primeiro](../ops/mcp-first-agent-execution-2026-11-05.md).
<!-- /OWNER_MCP_FIRST_REAL_ATTEMPT_20261105 -->

<!-- OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->
> **ORDEM EXPRESSA DO PROPRIETARIO — MCP PRIMEIRO, TENTATIVA REAL OBRIGATORIA:** antes de acionar RDC, SSH, RustDesk ou alegar que uma tarefa remota ShopVivaliz esta bloqueada, identificar o MCP administrativo disponivel **na sessao atual** e **executar uma chamada MCP adequada e autorizada** para o host/operacao. `hosts_list`, `claude mcp list` e `codex mcp list` isolados NAO contam como tentativa de execucao. Backend/Fred-Win/KOCEPSV: `shopvivaliz-remote-control` (`admin_command_run` ou `task_submit` + `task_status`, quando expostos). Marina: MCP independente `shopvivaliz-marina-admin` e rota central somente conforme grants atuais. Um timeout de RDC/GUI NAO e motivo para ignorar MCP.
> **EVIDENCIA OBRIGATORIA:** registrar `remote_access_attempts` com host, ferramenta, horario, `request_id`/`task_id`, resultado e proxima acao. Diferenciar `MCP_ATTEMPTED_OK`, `MCP_ATTEMPTED_FAILED`, `MCP_NOT_EXPOSED`, `MCP_NOT_AUTHORIZED` e `MCP_NOT_APPLICABLE`. Sem chamada nao afirmar que tentou MCP. Falha MCP comprovada permite fallback independente e autorizado, sem contornar recusas, MFA, RBAC, lease ou grant; prosseguir ate validacao.
> **FONTE DE VERDADE:** [Contrato MCP primeiro](../ops/mcp-first-agent-execution-2026-11-05.md). Vale para ChatGPT dev/atendimento, Codex, Claude Code, Gemini e subagentes; sessoes abertas precisam carregar estas instrucoes ou verificar sua versao atual.
<!-- /OWNER_MCP_FIRST_EXECUTION_V2_20261105 -->

<!-- CURRENT_TWO_VM_AND_RECOVERY_GATE_20261031 -->
> **Regra vigente (31/10/2026, confirmada pelo proprietario):** existem apenas duas VMs OCI operacionais: `marina-a1-4c24g` (web/deploy/banco/workers) e `always-free-arm-1787907847-26` (backend/MCP/controller/browser). `shopvivaliz-free-a1` foi encerrada: NAO acessar, pesquisar como destino, monitorar, recuperar, recriar ou redirecionar aliases para ela. Referencias historicas abaixo nao representam destino ativo.
>
> **Continuidade obrigatoria contra bloqueios:** uma falha isolada de terminal, usuario de execucao, GitHub CLI, SSH, plugin, runner ou browser NAO comprova ausencia de acesso e NAO encerra a tarefa. Ler documentacao de acesso vigente, conferir usuario/contexto correto e fontes protegidas ja provisionadas, testar rotas alternativas independentemente autorizadas (MCP, RDC 1/2, SSH privado/Tailscale, GitHub via perfil autorizado, runner/OCI apenas para recuperacao), corrigir falhas tecnicas, preservar checkpoints e prosseguir ate teste, merge, deploy automatico aplicavel e validacao posterior. Exemplo confirmado: `gh` estava autenticado sob `ubuntu`, nao sob `root`; executar via identidade autorizada sem revelar tokens. Proibido contornar MFA, RBAC, recusas explicitas, controles de branch ou leases. Se todas as rotas legitimas falharem, registrar evidencia e proxima acao, sem declarar sucesso.
<!-- /CURRENT_TWO_VM_AND_RECOVERY_GATE_20261031 -->

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Estado de runtime posterior, prevalente (2026-10-29):** o MCP central do backend agora anuncia **somente backend, Fred-Win e KOCEPSV**; a origem `shopvivaliz-free-a1` foi removida da allowlist (PRs #3391/#3392) e nunca e alvo. Marina permanece fora do MCP central, mas RDC direto e SSH privado foram comprovados; o tunel SSH do MEI para Marina esta ativo (HTTP 200), separado do MCP independente Marina que continua nao instalado. O PR #3395 bloqueou 20 jobs antigos sem migrar automaticamente seus objetivos. Controlador 24x7 ainda precisa de lease valido e recuperacao de checkpoints; CI `mei-ci-a1` segue sem registro. Declaracoes de "MCP ainda inclui origem" abaixo sao apenas historicas. Referencia: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Obrigatório para todo agente em 2026-10-29:** ver `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`. **Marina (`marina-a1-4c24g`/OCI ESPOSA) = site/deploy/banco/workers, RDC direto comprovado; backend (`always-free-arm-1787907847-26`/OCI PRINCIPAL) = MCP central/controller/browser.** `shopvivaliz-free-a1` foi excluída: não é alvo e não deve ser recriada. A allowlist MCP central ainda inclui o nome antigo e não inclui Marina. O MCP independente Marina e seu túnel estão inativos; cloudflared ativo não equivale a MCP registrado. Não redirecionar aliases, não utilizar scripts/workflows legados contra origem; exigir identidade, direitos, lease e prova específicos antes de cada execução.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
## Auditoria Extrema V5: execucao obrigatoria na UI real (gate de aceite)

Auditorias extremas, completas, V5 ou de aptidao de qualquer sistema com interface exigem **execucao do proprio agente na UI real publicada**, percorrendo as jornadas e casos aplicaveis de ponta a ponta. Logs, API, SQL, codigo, mocks, simples HTTP 200, screenshot isolado ou execucao somente headless nao substituem interacao visual. Para cada fluxo: entrar na tela real, navegar, acionar controles, observar erros, gravar provas de antes/depois, recarregar/revisitar, confirmar persistencia e reconciliar efeitos. Investigar, corrigir, prevenir e reauditar pela UI apos cada correcao aplicavel. Sem evidencias reais: `NAO_VALIDADO_UI` e nunca `APTO`/`CONCLUIDO`. Proibido esconder UI existente em `browser.required=false` ou `journey.ui=false`; a ausencia real de UI requer inventario negativo documentado e evidencias. Usar hosts, perfis e leases autorizados, sem bypass de MFA ou acoes irreversiveis sem permissao exata. Ver `AGENTS.md`, `AUDIT_POLICY.md` e `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md`.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- MANDATORY_AGENT_RULES_READ_GATE_V1 -->
## Leitura comprovada antes de executar "Retome"

Qualquer comando curto `Retome`, `Continue`, `Prossiga` ou `Siga` dispara a retomada operacional integral. O agente deve consultar o `AGENTS.md` e esta base de conhecimento diretamente no `main` atual, incluindo `host-access.md`, `README.md`, `agent-rules.md` e as instrucoes especificas do modulo. Antes de alteracoes, registrar checkpoint nao secreto de leitura: caminhos, ref, SHA e instante; um simples reconhecimento textual nao vale como prova. Revalidar a revisao a cada nova sessao/retomada. Sem acesso de leitura, evitar mutacoes ate restaurar as fontes; manter apenas diagnosticos read-only seguros.
<!-- /MANDATORY_AGENT_RULES_READ_GATE_V1 -->

# Bootstrap da conta ChatGPT Atendimento

Este documento é o bootstrap canônico, **não secreto**, da conta corporativa `atendimento@shopvivaliz.com.br`. Ele existe para que uma nova sessão dessa conta consiga operar os projetos ShopVivaliz sem depender de memória informal, de outra conta ChatGPT ou de credenciais versionadas.

## Identidade e objetivo

- Conta operacional: `atendimento@shopvivaliz.com.br`.
- Papel: agente corporativo de atendimento e operação técnica ShopVivaliz.
- Fonte de verdade: repositórios e documentação canônica; nunca copiar memória privada de outra conta como substituto.
- Segredos, cookies, senhas, tokens, seeds TOTP e OTPs nunca devem aparecer neste arquivo, no Git, em logs ou no chat.

## Leitura obrigatória ao iniciar uma sessão

**Preflight canônico e prevalente, antes de qualquer alteração e em toda retomada**: ler no `main` de `Vivaliz-site/site-shopvivaliz`, nesta ordem: (1) este `docs/knowledge/atendimento-chatgpt-bootstrap.md`; (2) `docs/knowledge/host-access.md`; (3) `docs/knowledge/README.md`; (4) `docs/knowledge/agent-rules.md`; depois `AGENTS.md`, `AUDIT_POLICY.md`, instruções do agente e documentos do projeto. Registrar `rules_loaded` com revisão/hash e preflight aplicável. A ordem suplementar antiga abaixo foi substituída, **não** é uma segunda ordem concorrente.

Para o contexto complementar da conta Atendimento, consultar `docs/knowledge/project.md`, `docs/knowledge/chatgpt-account-migration-curated.md` quando houver migração, `docs/knowledge/dev-agent-briefing.md` para engenharia e o documento específico da rotina. Em conflito, respeitar controles de segurança e as instruções canônicas explícitas mais recentes; sem precedência demonstrada, não executar a ação contraditória até reconciliar os textos.

## Mapa operacional de hosts

- `marina-a1-4c24g` (OCI ESPOSA): **site/web/deploy de produção**, Apache/MySQL/workers/runner; IP privado documentado `10.80.1.116`, Tailscale observado `100.118.170.46`. Acesso direto RDC 1/2 confirmado em 2026-10-29.
- `always-free-arm-1787907847-26` (OCI PRINCIPAL): backend/controller do MCP central, navegador corporativo, MEI/M365 e relay; privado `10.0.1.38`, Tailscale observado `100.66.174.74`.
- `shopvivaliz-free-a1`: **origem excluida segundo proprietario** em 2026-10-29, SSH sem rota; proibido usar como destino. `shopvivaliz-ai` e E2 antigas sao historicas.
- Estado dos MCPs e alternativas: [`current-topology-and-access-2026-10-29.md`](current-topology-and-access-2026-10-29.md).
- Produção: `/home/ubuntu/shopvivaliz-deploy/`, com releases imutáveis. Nunca editar `current/` nem a release ativa diretamente.

## Rota de operação

1. Backend: ShopVivaliz Remote Control MCP para hosts **validos de sua allowlist**; a origem excluida ainda aparece, e Marina **nao** aparece nesse MCP.
2. Marina: RDC 1/2 direto comprovado em 2026-10-29 (descobrir o ID atual pelo nome), com identidade/privilegios verificados; SSH privado/Tailscale somente apos autenticacao comprovada. O MCP independente Marina permanece inativo/nao conectado. Nunca redirecionar comandos da origem para a Marina implicitamente.
3. GitHub Actions/OCI Bastion somente para bootstrap/recovery.
4. Navegador operacional roda na VM de backend; não usar navegador dos hosts Windows nem Opera Connector para tarefas ShopVivaliz.
5. GitHub é fonte/bootstrap/recovery, não transporte normal de runtime.

## Plugins e capacidades mínimas da conta

Obrigatórios quando disponíveis no workspace:

- ShopVivaliz Remote Control;
- GitHub;
- Superpowers;
- Data;
- Writing Style;
- Gmail, Google Drive, Google Calendar e Google Contacts quando o OAuth corporativo estiver concluído;
- pesquisa web para documentação e comportamento atual de APIs/frameworks;
- continuidade/checkpoints do runtime.

`@Superpowers` deve ser usado em cada etapa material. Gepeto também é obrigatório pela política do projeto; se não estiver exposto pelo runtime, registrar `GEPETO_UNAVAILABLE` e continuar sem simular participação.

## Credenciais e autenticação


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



- Reutilizar primeiro as fontes seguras já provisionadas no runtime/host.
- Não pedir novamente ao usuário senha, OTP ou outra credencial antes de provar que a fonte existente está ausente, revogada ou inválida.
- Nunca tentar revelar o conteúdo de um secret protegido.
- CAPTCHA, Turnstile, recovery ou consentimento que exija interação humana não deve ser contornado.
- Não conectar conta Google pessoal como substituto da identidade corporativa.

## Repositórios principais

- `Vivaliz-site/site-shopvivaliz` — site e base operacional principal.
- `Vivaliz-site/amazon-returns-safet` — Amazon Returns / SAFE-T.
- `Vivaliz-site/mercadolivre-returns-recovery` — devoluções Mercado Livre.
- `Vivaliz-site/-shopvivaliz-pipeline` — pipeline e automações.
- `Vivaliz-site/mei-mg-email` — automação MEI-MG.
- `Vivaliz-site/solange-rolla-consultorio` — repositório canônico Solange Rolla.
- `Vivaliz-site/solange-rolla` — legado/supersedido; não tratar como canônico.

**Alias GitHub após transferência (verificado em 03/11/2026):** os caminhos antigos `fredmourao-ai/{solange-rolla-consultorio,mei-mg-email,solange-rolla}` redirecionam para os três repositórios em `Vivaliz-site`. O endpoint de metadados (`get_repo`) resolve esses aliases, mas a busca de PRs com `repo:fredmourao-ai/...` responde HTTP 422. **Antes de inventariar PRs**, resolver e conferir `repository_full_name`/ID canônico no GitHub e executar a busca no `Vivaliz-site/...`, preservando paginação. Não interpretar 422 do alias como bloqueio de permissão ou como PRs inexistentes.

## Regras operacionais críticas

### Auditoria e diagnóstico

Diagnóstico não encerra a tarefa. O ciclo obrigatório é:

`diagnosticar -> corrigir -> prevenir -> testar -> validar`.

Não declarar verde por evidência apenas estrutural. Produção funcional exige os gates reais definidos em `agent-rules.md` e `testing.md`.

### Continuidade

- Estado final válido: `CONCLUIDO` com validação fresca, ou `BLOCKED_EXTERNAL` somente após esgotar alternativas seguras.
- Uma falha de ferramenta, navegador, sessão, runner, workflow ou timeout mantém a tarefa `RUNNING` enquanto existir próxima ação executável.
- Ordem de retomada: ChatGPT comum -> ChatGPT Work -> CLI por último.
- Não desabilitar retomada automática checkpoint-driven apenas por diagnóstico de risco.
- Estado de tarefas: `/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state`.
- Checkpoints desta conta devem fixar a conversa corporativa comprovada e `browser_session=atendimento`, pelo comando `bind-browser-session` do CLI de estado. Consultar `browser-sessions.md`. Nao reutilizar binding de conversa pessoal nem trocar a porta global do worker.
- O worker unico roteia esse binding para CDP9556 e verifica a identidade antes de qualquer recarga ou envio. Servico padrão em CDP9559 (Dev), sozinho, nao comprova cobertura desta conta; Atendimento permanece em CDP9556.

### Amazon Returns / SAFE-T

- **Regra operacional prevalente SHOPVIVALIZ D+45:** a primeira checagem e tentativa de abertura de SAFE-T ocorre em **D+45**, contado de `refund_at` (reembolso ao comprador). **D+60 não é checkpoint interno**. Auditar individualmente os casos de D+45 a D+89; casos com mais de 90 dias não devem gerar nova abertura, preservando histórico e protocolos existentes.
- D+45 **não garante elegibilidade externa** nem autoriza escrita automaticamente: confirmar na Amazon/Seller Central a janela e o programa aplicáveis. Em caso de bloqueio oficial, preservar resposta e data indicada, sem insistir por outra via.
- Antes da abertura, comprovar reembolso iniciado pela Amazon (origem `UNKNOWN` exige prova), zero `WAREHOUSE PHYSICAL_RECEIVED`, débito financeiro ainda não recuperado, inexistência de SAFE-T ou suporte equivalente e ausência de submissão duplicada. Outbox `PENDING` não demonstra ID de reivindicação aceito; exigir readback externo e idempotência.
- O acionamento externo requer principal/lease/fencing/grant efetivamente autorizados, kill switch com restauração segura, perfil restrito a `SAFE_T_SUBMIT` e canary exata dos casos aprovados; nunca habilitar os demais canais por simplesmente desligar o kill switch global.
- Encerramento real somente após crédito comprovado e reconciliado independentemente (`CREDIT_CONFIRMED`). Auditoria de 100% dos casos elegíveis, sem confundir saldo nominal com recuperação.
- Arquitetura alvo multi-tenant com credenciais e workers isolados por seller.

### Mercado Livre Returns

- Manter ingestão, reconciliação e projeção idempotentes.
- Distinguir bloqueio externo de Claims/Returns/Billing de falha local.
- Não declarar E2E aprovado se provider real não respondeu com autorização válida.

### MEI-MG

- Respeitar `sender_blocked.pause`.
- Não reiniciar worker enquanto o stop-the-line estiver ativo.
- Hard bounce acima do limite é falha operacional, não warning cosmético.

### Solange Rolla

- Usar `Vivaliz-site/solange-rolla-consultorio` como canônico.
- Não retomar trabalho novo no repositório legado.

## Daybreak / uso defensivo

O uso do Daybreak deve permanecer exclusivamente defensivo e autorizado em ativos próprios da ShopVivaliz ou em ambientes com autorização explícita.

Casos de uso permitidos incluem revisão de segurança de código, análise de vulnerabilidades, threat modeling, investigação de incidentes, hardening, criação/revisão/validação de patches, segurança de infraestrutura e CI/CD, revisão de APIs e integrações e testes defensivos em e-commerce, marketplaces, automações e agentes.

Nunca incluir em formulários, chats ou evidências externas senhas, tokens, chaves, seeds TOTP, cookies ou conteúdo de secrets.

## Checklist E2E de onboarding da conta Atendimento

Uma configuração só é considerada validada quando, em uma conversa nova da conta `atendimento@shopvivaliz.com.br`, a própria conta consegue:

1. identificar corretamente Marina como site/producao, backend como controller/browser, e `shopvivaliz-free-a1` como origem excluida;
2. localizar e ler este bootstrap e os documentos obrigatórios;
3. invocar ShopVivaliz Remote Control em uma ação reversível;
4. localizar o repositório principal pelo GitHub;
5. explicar a regra de releases imutáveis sem editar produção diretamente;
6. aplicar a regra “diagnosticar -> corrigir -> prevenir -> testar -> validar”;
7. localizar/continuar um checkpoint sem usar GitHub como transporte de runtime;
8. usar Superpowers na etapa material;
9. não pedir credenciais já provisionadas sem provar ausência/invalidez;
10. distinguir claramente plugin instalado de conector autenticado;
11. executar um teste remoto inofensivo e apresentar evidência fresca;
12. concluir como `CONCLUIDO` apenas se os testes acima forem reais.

## Prompt de inicialização recomendado

```text
Use o repositório Vivaliz-site/site-shopvivaliz (main) como fonte primária.
Leia primeiro:
1. docs/knowledge/atendimento-chatgpt-bootstrap.md
2. docs/knowledge/host-access.md
3. docs/knowledge/README.md
4. docs/knowledge/agent-rules.md
5. docs/knowledge/current-topology-and-access-2026-10-29.md
6. docs/knowledge/project.md
7. docs/knowledge/browser-sessions.md
7. docs/knowledge/chatgpt-account-migration-curated.md
8. docs/knowledge/dev-agent-briefing.md quando houver trabalho de engenharia.

Considere esse material como contexto operacional persistente da ShopVivaliz. Histórico migrado é contexto auxiliar e nunca prova estado atual: revalide no runtime, código ou documentação canônica. Não exponha secrets. Para qualquer tarefa, confirme estado real por evidência antes de concluir. Use o ShopVivaliz Remote Control como rota operacional primária para hosts/browser e mantenha o navegador na VM backend. Aplique o ciclo diagnosticar -> corrigir -> prevenir -> testar -> validar e mantenha continuidade até CONCLUIDO validado ou bloqueio externo real. Quando eu pedir algo relacionado a um projeto, leia também a documentação específica antes de agir.
```

## Estado de conectores

A presença de um plugin no diretório não prova OAuth concluído. Gmail, Drive, Calendar e Contacts só são considerados conectados quando o fluxo corporativo Google terminar e uma chamada real do conector passar. Se o Google exigir CAPTCHA/Turnstile ou verificação humana, registrar o ponto exato e continuar o restante do onboarding sem usar uma conta pessoal como atalho.

## Account access parity

Atendimento must have the same authorized apps, tools, workspace role and
effective permissions as Dev. Use its own provider grants, ChatGPT browser
profile and authentication, never Dev's credentials or session. Follow
[`chatgpt-access-parity.md`](chatgpt-access-parity.md) and prove an
independently authorized RDC 2 connection and `who_am_i` result before declaring parity.

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
**Obrigatorio na inicializacao/retomada:** leia `docs/knowledge/shopvivaliz-graphical-session-recovery.md` antes de interpretar a tela RustDesk ou a presenca de Dev/Atendimento no seletor da GUI como login autenticado nos perfis CDP. Preservar sessoes; a GUI `fredconsole/:0`, greeter `lightdm/:1` e os browsers `fredrdp/:99` sao contextos diferentes.
<!-- /SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
