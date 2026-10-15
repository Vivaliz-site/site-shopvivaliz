# Auditoria extrema V5 — controlador de persistência (2026-10-08)

## Escopo e método

Controle canônico: `always-free-arm-1787907847-26`; serviços
`shopvivaliz-gemini-24x7-controller.service` e
`shopvivaliz-chatgpt-continuity.service`; estado durável
`/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state`.
Fontes: `systemctl status/show`, comandos remotos auditados, estado JSON
sanitizado, código e testes do repositório, CI e promoção imutável.
Sem login novo, sem adivinhar conversa, sem modificar credenciais e sem
enviar turnos de teste a conversas reais.

## Evidências iniciais

1. Às 20:05 UTC, o controlador constava `failed` desde 04:14:45 UTC,
   apesar de habilitado. O bridge ChatGPT continuava `active`.
   Journals antigos estavam rotacionados; a exceção específica do crash
   **não foi demonstrada**.
2. Às 20:07:05 UTC o serviço foi encontrado `active` em uma versão antiga
   `c6fea8f6f9506cf63359920c485ceda63755d8f9`. A auditoria **não**
   reivindica ter efetuado esse restart.
3. Primeiro ciclo: `completion_sweep.scanned=77`,
   `active=9`, `bound_conversations=0`, `unbound_active=9`,
   `watchdog.eligible=9`, `watchdog.dispatched=0`,
   `chatgpt_nudge.dispatched=0`, `dispatcher.launched=0`,
   `completion_sweep.completed=0`.
   Mesmo assim, `continuity_ready=true` antes da correção: **falso verde**.
4. `task_resume_dispatcher.py` adiava checkpoints sem `conversation_id`;
   `chatgpt_continuity_nudge_dispatcher.py` também. Esses adiamentos eram
   omitidos do resumo de saúde. `browser_session=dev` era aceito pelo
   estado mas não pelo dispatcher; o parser CLI aceitava `fred` em vez de
   `dev`, divergindo da função interna.
5. O unit original tinha `StartLimitIntervalSec=10min` e
   `StartLimitBurst=5`, com `RestartSec=20`, permitindo
   bloqueio permanente após falhas rápidas.
6. Bridge observou repetidamente `latest_unavailable`,
   `http_status=200`, `candidate_count=0`, `project_count=0`.
   Isso não prova ausência de conversas: pode ser discovery/API/sidebar.
7. Existem 340 arquivos antigos `_chatgpt-continuity-monitor-state.json.tmp.*`
   (contagem remota) no estado durável. **Resíduo a tratar** com retenção/
   limpeza que preserve escritor ativo; não apagar sem validação.
8. No checkout de trabalho `ubuntu`, um `git fetch` reportou falha de
   auto-GC por permissão em `.git/worktrees/shopvivaliz-pr2794/HEAD.lock`.
   Fetch retornou código 0; ainda assim o erro merece auditoria separada
   de posse de worktrees. Não alterar checkout ocupado por outros agentes.

## Correção V5-1 — PR #2810

Commit de merge `2393722dcb2bf454067de646c47073aeeeb96aeb`.

- Readiness falha fechado para `unbound_active`, `skipped_unbound`,
  `deferred_unbound` e `deferred_unbound_session` com handoff durável.
- Modo de rollback sem handoff durável continua permitindo fallback legado.
- Dispatcher aceita sessão corporativa `dev`.
- Parser CLI do estado admite `dev` e `atendimento`.
- Systemd não esgota o limite de restart; novo intervalo 60 s.
- Testes de regressão adicionados para falso verde, Dev e rollback.

Quatro workflows GitHub concluíram `success`. Promoção canônica
`controller_promote(expected_sha=2393722d...)` confirmou exit 0,
`service_active=true`, `active_sha=origin_main_sha=2393722d...`.

**Prova pós-implantação** em 20:25:47 UTC:
`continuity_ready=false`, `degraded_reasons`:
`active_checkpoint_unbound`,
`chatgpt_resume_unbound_conversation`,
`dispatcher_unbound_conversation`;
`chatgpt_nudge.skipped_unbound=9`,
`dispatcher.deferred_unbound=9`.
O sistema parou de mascarar o bloqueio, mas as 9 tarefas **não** retomaram.

## Correção V5-2 — PR #2813

- Instalador requer `CHATGPT_CONTINUITY_MONITOR_REQUIRED=1` por padrão,
  ao contrário do `=0` observado após a primeira promoção.
- Discovery HTTP 200 sem candidatos pode recorrer à **sidebar sincronizada
  localmente**, somente com prova de contexto seguro (home ou aba única/
  consenso previamente confirmado). Abas ambíguas permanecem isoladas.
- Testes positivos e negativos de seleção de aba e contrato do instalador.
- Estado desta etapa deve ser atualizado **somente** após merge, CI e prova
  pós-promocional.

## Correção V5-3 — parser Bash do probe de autenticação

No `chatgpt-browser-guardian.sh`, a chamada `node --input-type=module -e`
possui corpo JavaScript delimitado por aspas simples do Bash. O seletor de
perfil `document.querySelector('[aria-label="Open profile menu"]')`
também usava aspas simples; o shell as removia antes de passar o código ao
Node. A expressão de `Runtime.evaluate` tornava-se inválida,
gerando `SyntaxError` mascarado como `browser expression failed`. O guardian
podia registrar `UNKNOWN`/`UNREACHABLE` apesar de CDP responder.

A correção evita aspas simples dentro do script JavaScript embutido,
preserva a checagem por atributo acessível e adiciona teste que usa
`shlex.split` para verificar o argumento efetivamente recebido pelo Node.
Reiniciar o Chromium isoladamente não corrige esse defeito no guardian.

Até demonstrar autenticação Dev real e `PROGRESS_CONFIRMED` em conversa
explicitamente vinculada, permanecer `continuity_ready=false` é correto.

## Correção V5-4 — sonda OAuth paralela e identidade estrita

A checagem de menu de perfil da V5-3 foi substituida pelo endurecimento
de identidade do PR #2824: somente um `session.user.email` correspondente
a `dev@shopvivaliz.com.br` pode atestar login. Resposta HTTP 200 sem
identidade **nao** indica autenticacao. Nunca aceitar menu/compositor
como substitutos da identidade confirmada.

A auditoria posterior encontrou varias abas antigas de `auth.openai.com` e
`accounts.google.com` no perfil Dev; a sonda do guardian percorria essas
abas sequencialmente, com ate 5 s cada, apesar do timeout global de 15 s.
Sob paginas travadas, a sonda podia terminar em `UNKNOWN` sem sequer
alcançar a verificacao do ChatGPT.

O processamento OAuth agora usa `Promise.allSettled` de no maximo 8 abas
concorrentes, com ate 1200 ms para abrir websocket e 1500 ms para consultar
cada aba. Assim resta tempo para a sonda da sessao principal (10500 ms).
O criterio `AUTHENTICATED` continua estritamente ligado ao e-mail esperado.
Teste de regressao protege esse orcamento.

A autorizacao por codigo de e-mail continua pertencendo ao fluxo oficial
do usuario: sem MFA aprovado e identidade exata, `continuity_ready=false`
e um estado correto, nao uma falha a mascarar.

## Critérios de certificação ainda pendentes

Aprovar `CONTINUITY_E2E_PASS` somente com:

1. Checkpoint `RUNNING` real associado ao `conversation_id` exato e
   `browser_session` corporativo correto; não inferir por título/tempo.
2. Lease e fencing bloqueando escritores concorrentes e checkpoint
   imutável preservado após restart.
3. Interrupção natural (não forçar turno sintético em conversa de negócio).
4. Mesmo chat retoma com resultado `PROGRESS_CONFIRMED`, e a tarefa original
   chega a `CONCLUIDO` após verificações fixadas ou permanece
   `BLOCKED_EXTERNAL` com evidência suficiente.
5. Auditoria de todas as conversas exige instrumentação de entrada:
   apenas checkpoints existentes são alcançados pelo watchdog, e a
   política atual limita o lookback a 10 dias. Sem rastreamento de criação e
   binding de cada conversa, é incorreto declarar cobertura total.

**Classificação V5-1:** correção de diagnóstico e roteamento validada
em produção; execução E2E de conversas não certificada. Não promover estado
`continuity_ready=true` manualmente e não inventar bindings para os
9 checkpoints órfãos.
