<!-- TWO_VM_TOPOLOGY_GATE_20261031 -->
> **Topologia operacional obrigatoria (confirmacao do proprietario em 2026-10-31): existem somente DUAS VMs OCI em operacao.** `marina-a1-4c24g` (conta ESPOSA/Marina) hospeda web, deploy, banco e workers; `always-free-arm-1787907847-26` (conta PRINCIPAL) hospeda backend, MCP central, controller, browser de agentes e integracoes. **`shopvivaliz-free-a1` foi encerrada: host RETIRADO, nao consultar, procurar, reconectar, restaurar, monitorar, acionar por workflow, nem recriar.** Referencias abaixo a essa VM, a seus IPs, a `hosts_list` antigos ou a allowlists antigas sao **historicas e nao autorizam nenhuma operacao**. Nao mapear o alias antigo para Marina. A confirmacao de encerramento e do proprietario; nao constitui verificacao independente de lifecycle OCI. Nao confundir as duas VMs com PCs de apoio Fred-Win/KOCEPSV. Antes de qualquer operacao, verificar identidade, permissoes, leases e rota autorizada do host vigente.
<!-- /TWO_VM_TOPOLOGY_GATE_20261031 -->

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

Nesta ordem:

1. `docs/knowledge/host-access.md`;
2. `docs/knowledge/agent-rules.md`;
3. `docs/knowledge/project.md`;
4. `docs/knowledge/chatgpt-account-migration-curated.md` quando a sessão depender de contexto vindo de outra conta ou de histórico migrado;
5. `docs/knowledge/dev-agent-briefing.md` quando houver trabalho de engenharia;
6. documento específico da rotina;
7. este arquivo para o perfil e checklist da conta Atendimento.

## Mapa operacional de hosts

- `shopvivaliz-free-a1`: site/web/deploy de produção; origin `137.131.149.55`, privado `10.0.1.112`.
- `always-free-arm-1787907847-26`: backend, controller do Remote Control MCP, navegador, MEI, M365 e relay; privado `10.0.1.38`.
- `shopvivaliz-ai` / `137.131.156.17`: DEV legado/e-mail/testes; nunca assumir como produção web.
- Produção: `/home/ubuntu/shopvivaliz-deploy/`, com releases imutáveis. Nunca editar `current/` nem a release ativa diretamente.

## Rota de operação

1. ShopVivaliz Remote Control MCP é a rota primária para host, serviço, browser, arquivos e tarefas duráveis.
2. SSH privado/Tailscale somente quando a capacidade necessária não existir no MCP ou ele estiver comprovadamente indisponível.
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
- `fredmourao-ai/mei-mg-email` — automação MEI-MG.
- `fredmourao-ai/solange-rolla-consultorio` — repositório canônico Solange Rolla.
- `fredmourao-ai/solange-rolla` — legado/supersedido; não tratar como canônico.

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

- Reembolso proativo FBA até 50 dias.
- Após o prazo, recorrer via SAFE-T conforme elegibilidade.
- Encerramento real somente após crédito reconciliado (`CREDIT_CONFIRMED`).
- D+75 e auditoria de 100% dos casos elegíveis.
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

- Usar `fredmourao-ai/solange-rolla-consultorio` como canônico.
- Não retomar trabalho novo no repositório legado.

## Daybreak / uso defensivo

O uso do Daybreak deve permanecer exclusivamente defensivo e autorizado em ativos próprios da ShopVivaliz ou em ambientes com autorização explícita.

Casos de uso permitidos incluem revisão de segurança de código, análise de vulnerabilidades, threat modeling, investigação de incidentes, hardening, criação/revisão/validação de patches, segurança de infraestrutura e CI/CD, revisão de APIs e integrações e testes defensivos em e-commerce, marketplaces, automações e agentes.

Nunca incluir em formulários, chats ou evidências externas senhas, tokens, chaves, seeds TOTP, cookies ou conteúdo de secrets.

## Checklist E2E de onboarding da conta Atendimento

Uma configuração só é considerada validada quando, em uma conversa nova da conta `atendimento@shopvivaliz.com.br`, a própria conta consegue:

1. identificar corretamente os papéis de `shopvivaliz-free-a1` e `always-free-arm-1787907847-26`;
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
5. docs/knowledge/project.md
6. docs/knowledge/browser-sessions.md
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
