# Task Continuity Enforcement

**Policy:** `TASK_CONTINUITY_ENFORCEMENT_V3`

## Regra terminal

Uma tarefa iniciada deve permanecer ativa até exatamente um destes estados:

- `CONCLUIDO`: objetivo original realizado e validado com evidência fresca;
- `BLOCKED_EXTERNAL`: impedimento externo real, objetivo e intransponível com os acessos e ferramentas disponíveis, depois de alternativas seguras terem sido tentadas.

Diagnóstico, plano, mudança local, commit, PR, CI em andamento, deploy iniciado, erro de ferramenta, timeout recuperável, autenticação já provisionada que ainda pode ser recuperada, ou necessidade genérica de "aprovar o design" são estados intermediários.

## Autorização já existente x gates genéricos

Quando o usuário já pediu explicitamente para executar, implementar, corrigir, auditar, resolver ou continuar até conclusão, e a ação seguinte é reversível e está dentro do escopo autorizado, essa instrução já satisfaz gates genéricos de aprovação de plano/design usados por skills de processo.

O agente **não deve parar apenas para perguntar "posso continuar?", "aprova este plano?" ou equivalente** quando o resultado pretendido e os limites já estão claros.

Isso não elimina confirmações exigidas por segurança ou plataforma. Continuam exigindo aprovação/intervenção específica quando aplicável:

- ação destrutiva ou irreversível não autorizada de forma exata;
- cobrança, compra, pagamento ou compromisso financeiro real;
- exposição/alteração de segredo fora do fluxo seguro;
- login, CAPTCHA, recovery ou confirmação que tecnicamente exige ação humana;
- mudança de escopo material não contida no pedido original.

## Falha recuperável nunca é terminal

Falha de ferramenta, plugin, CLI, API, navegador, sessão, runner, workflow, timeout, conexão ou rota primária deve manter a tarefa em `RUNNING`.

O agente deve:

1. preservar a evidência do erro;
2. identificar a causa;
3. tentar novamente quando houver base para isso;
4. usar rota/ferramenta equivalente segura;
5. persistir a próxima ação concreta;
6. continuar o objetivo original.

Uma tarefa simples segue exatamente a mesma regra.

## Estado durável

Quando o runtime tem acesso ao repositório, use `scripts/agent_task_state.py`.

Exemplo:

```bash
python3 scripts/agent_task_state.py start --task <id> --goal "<objetivo>" --agent <agente>
python3 scripts/agent_task_state.py progress --task <id> --next-action "<próxima ação>" --evidence "<evidência>"
python3 scripts/agent_task_state.py ready --task <id> --evidence "<teste PASS>" --verification "<pedido original x estado final>"
python3 scripts/agent_task_state.py complete --task <id>
```

Para bloquear:

```bash
python3 scripts/agent_task_state.py block \
  --task <id> \
  --description "<impedimento externo>" \
  --evidence "<prova>" \
  --alternative "<rota segura tentada 1>" \
  --alternative "<rota segura tentada 2>" \
  --resume-condition "<condição exata para retomar>"
```

`BLOCKED_EXTERNAL` é rejeitado se o impedimento não for externo, se não houver evidência, se menos de duas alternativas distintas tiverem sido tentadas ou se não existir condição exata de retomada.

## Gate de resposta final

Antes de uma resposta que alegue término, o estado da tarefa deve ser terminal. Em runtime com o estado durável disponível:

```bash
python3 scripts/agent_task_state.py terminal --task <id>
```

Exit code diferente de zero significa que ainda há trabalho e a resposta deve ser apenas atualização de progresso, seguida da próxima ação executável — nunca encerramento.


## Economia de fan-out do CI

- Agrupe alterações relacionadas em um único commit/ref update quando a ferramenta permitir; um commit por arquivo multiplica eventos `synchronize`.
- Use TDD isolado durante a edição e publique uma unidade verificável; um RED remoto explícito basta antes do GREEN.
- Auditorias informativas, inventários e verificações horárias não pertencem ao hot path de todo PR.
- Não aplique `on.pull_request.paths` a workflow potencialmente obrigatório: o GitHub pode deixar o check esperado em `Pending`. Prefira workflow sempre disparado com jobs baratos/skipped, ou gate agregador.
- Dentro de Actions, autentique consultas GitHub com o `GITHUB_TOKEN` efêmero quando possível para evitar o limite baixo de chamadas REST anônimas.

<!-- CODEX_LAST_RESORT_V1 -->
## Codex como última opção de execução

- Preservar cota do Codex para tarefas que realmente precisem dela. A ordem padrão de continuidade é: **rota determinística/controle remoto auditável → executor alternativo autenticado (Gemini/Claude conforme a tarefa) → Codex por último**.
- Para operações de host, serviço, navegador e diagnóstico, preferir o control plane auditável já disponível (GitHub connector/Actions, SSH privado, browser na backend) em vez de consumir Codex.
- Esgotamento de tokens/cota, rate limit, indisponibilidade ou falha de autenticação do Codex **não é estado terminal**. A tarefa permanece `RUNNING`, preserva checkpoint e tenta as rotas anteriores/alternativas que ainda forem seguras.
- `BLOCKED_EXTERNAL` só é permitido depois de provar que todas as rotas autorizadas e adequadas ao objetivo estão indisponíveis/intransponíveis; "Codex sem tokens" isoladamente nunca satisfaz esse critério.
- Nenhum daemon/cron/watch deve consumir Codex automaticamente. Codex só pode ser acionado em tarefa finita, explicitamente autorizada e como último recurso.
<!-- /CODEX_LAST_RESORT_V1 -->

<!-- TASK_CONTINUITY_AUTO_RESUME_V4 -->
## Retomada automática de checkpoint

Policy: `TASK_CONTINUITY_AUTO_RESUME_V4`.

O estado durável V3 impede falso término. A camada V4 evita que um checkpoint `RUNNING`
fique esquecido quando o turno, streaming, CLI, sessão ou executor é interrompido.

### Watchdog determinístico

`scripts/task_continuation_watchdog.py` roda antes do
`scripts/agent-operations-worker.py` no loop autônomo.

Contrato padrão:

- um checkpoint `RUNNING` com `next_action` e sem atualização por **120 segundos**
  torna-se elegível para retomada;
- cada revisão do checkpoint gera no máximo um pedido persistente
  `auto_resume` em `_resume-requests.jsonl`;
- se o checkpoint avançar, uma nova revisão pode gerar nova retomada;
- checkpoint fresco, `CONCLUIDO` ou `BLOCKED_EXTERNAL` não dispara retomada;
- pedido antigo é ignorado se `updated_at` ou `next_action` já mudou;
- o worker converte o pedido válido em intervenção operacional
  `source=task-continuation-watchdog` e `kind=auto-resume`;
- o watchdog não chama provider de IA, navegador, rede ou shell e não cria loop pago.

O limiar pode ser alterado no runtime por
`SHOPVIVALIZ_TASK_STALE_SECONDS`, preservando 120 segundos como padrão.

### Interrupção do próprio ChatGPT

Uma queda de transmissão do aplicativo ChatGPT ocorre fora do processo do
repositório e não pode ser reaberta diretamente por código hospedado na VM.
Quando o runtime do ChatGPT oferecer automações, uma automação de segurança pode
consultar os checkpoints e iniciar uma nova execução de retomada. O checkpoint
do repositório continua sendo a fonte para descobrir objetivo, evidência e
`next_action`, evitando reconstrução manual da conversa.

### Gate

`scripts/validate-task-continuity-enforcement.py` deve falhar se o watchdog,
seu teste, a integração no governance ou os tokens obrigatórios desaparecerem.
<!-- /TASK_CONTINUITY_AUTO_RESUME_V4 -->

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


<!-- DETACHED_CONTINUATION_EXECUTOR_V6 -->
## Execução desacoplada após interrupção do ChatGPT

Policy: `DETACHED_CONTINUATION_EXECUTOR_V6`.

A camada V4 detecta checkpoint estagnado; a V6 garante que isso resulte em
**execução real**, e não apenas em um ACK/timeline.

- `scripts/task_resume_dispatcher.py` roda depois do watchdog e antes do worker.
- Cada fingerprint de checkpoint recebe no máximo **uma tentativa finita**.
- O dispatcher valida que o pedido ainda corresponde ao `RUNNING` atual; pedidos
  superseded/terminais são ignorados.
- A execução ocorre em clone efêmero isolado, nunca em `current/` nem na release
  ativa.
- O executor recebe `SHOPVIVALIZ_RESUME_STAGE=cli_last` somente porque o próprio
  turno ChatGPT comum já foi tentado e interrompido, e ChatGPT Work não é
  invocável pelo processo hospedado no repositório. Isso é recuperação de crash,
  não alteração da preferência interativa normal.
- O dispatcher de background marca `SHOPVIVALIZ_RESUME_BACKGROUND=1` e
  só pode usar provedores permitidos para automação recorrente; atualmente,
  `Gemini` é a rota automática. **Claude/GPT/Codex não podem ser fallback
  silencioso de daemon/cron.**
- Em execução finita/interativa fora do background permanece a ordem
  `Gemini -> Claude -> Codex`; Codex continua sendo a última opção e usa
  login ChatGPT, sem `OPENAI_API_KEY`.
- Saída zero do executor **não** prova retomada. Só há sucesso se a máquina de
  estados durável mudar materialmente (status/next_action/evidência/verificação)
  ou chegar a `CONCLUIDO`/`BLOCKED_EXTERNAL`.
- Sem avanço, o fingerprint é registrado em `_resume-executions.jsonl`; como
  o recovery de background não usa IA paga, o mesmo checkpoint pode ser tentado
  novamente após cooldown (900 s padrão, configurável por
  `SHOPVIVALIZ_RESUME_RETRY_AFTER_SECONDS`). Nunca há mais de uma tentativa por
  ciclo. A tarefa continua `RUNNING` até progresso real ou terminal válido.
- Nenhuma saída de provider, prompt ou segredo é publicada no ledger; somente
  metadados de execução e resultado.
- O diagnóstico persistido em `_resume-executions.jsonl` (chave `diagnostic`)
  é estritamente alocado por allowlist (`provider`, `provider_status`,
  `provider_attempt_exit_code`, `background_gemini_error`,
  `background_gemini_exit_code`, `background_paid_fallback_forbidden`,
  `provider_output_bytes`, `provider_output_sha256`) — nunca prompt bruto,
  stdout/stderr bruto, tokens ou segredos.
- ACK de fila (`agent-operations-worker.py` reconhecendo um pedido
  `auto_resume` para o painel/timeline interno) **não é execução**. O evento
  correspondente usa `kind=auto-resume-queued`/`auto-resume-ack` e a mensagem
  deixa explícito que aquilo não comprova execução real. Só a cadeia real
  (watchdog → dispatcher → `autonomous-provider-failover.sh` → Gemini →
  mudança material do checkpoint) é evidência.
<!-- /DETACHED_CONTINUATION_EXECUTOR_V6 -->

<!-- DETACHED_TASK_RECOVERY_E2E_V7 -->
## Prova de ponta a ponta da retomada desacoplada em produção

Policy: `DETACHED_TASK_RECOVERY_E2E_V7`.

A V6 garante que existe um executor real. A V7 garante que ninguém pode
certificar continuidade a partir de gates estáticos/ACK: exige prova real,
correlacionada, de que o daemon já em execução na produção detecta, enfileira,
executa e conclui uma tarefa sintética por conta própria.

- `scripts/task_continuity_e2e.py` cria exatamente uma tarefa sintética
  `RUNNING` (`continuity-e2e-<uuid>`) via `agent_task_state.py` e, a partir
  daí, **somente observa** arquivos de estado/ledger em disco. É proibido o
  probe importar ou chamar `task_continuation_watchdog`/
  `task_resume_dispatcher` ou invocar diretamente qualquer `run_once`; toda
  detecção/execução deve vir do daemon `shopvivaliz-agent.service` já
  rodando no host de produção, no ciclo dele.
- `next_action` da tarefa sintética usa apenas comandos já permitidos na
  política headless do executor (`agent_task_state.py ready`/`complete`),
  para que o resultado do probe nunca dependa de uma política de aprovação
  de ferramenta não relacionada.
- PASS exige, tudo correlacionado pelo mesmo `task_id`/`fingerprint`:
  pedido em `_resume-requests.jsonl`; linha correspondente em
  `_resume-executions.jsonl` com `result` igual a `progress` ou `terminal`
  (nunca `no_progress`); `diagnostic.background_paid_fallback_forbidden`
  igual a `true`; checkpoint final com `status=CONCLUIDO` e
  `verification=continuity_e2e_pass`.
- `.github/workflows/task-continuity-production-e2e.yml` roda manualmente
  (`workflow_dispatch`) no runner `shopvivaliz-a1-deploy`, único lugar onde
  o daemon real está ativo; não substitui os testes unitários do probe, que
  correm no `Task Continuity Fast Gate` de forma isolada (com estado
  fabricado, sem depender de produção).
- **Distinção obrigatória, sempre explícita:** retomada desacoplada
  (watchdog + dispatcher + Gemini continuando estado persistido) **não é**
  reabertura da mesma conversa do aplicativo ChatGPT. Desde
  `CHATGPT_SESSION_REENTRY_V10`, a reentrada da conversa existe como uma
  camada separada: o pedido `chatgpt_common` é enfileirado no bridge HTTPS e
  um worker da VM backend anexa via CDP `127.0.0.1:9555` ao navegador
  ChatGPT já autenticado do usuário. O worker nunca cria um browser/perfil
  paralelo e nunca usa Codex como fallback automático.
- Falha do E2E (qualquer motivo: nenhum pedido observado, dispatcher não
  executou, `no_progress`, checkpoint não terminal, verificação ausente,
  só ACK do worker, `task_id` não correlacionado, evidência antiga) nunca é
  motivo para declarar `NÃO APTO` e parar: é `RUNNING`. Levantar causa raiz,
  TDD (RED → GREEN), commit/PR/merge/deploy, e repetir o E2E.
<!-- /DETACHED_TASK_RECOVERY_E2E_V7 -->


<!-- GLOBAL_TASK_CONTINUITY_V8 -->
## Continuidade global multi-repositório

Policy: `GLOBAL_TASK_CONTINUITY_V8`.

A certificação V7 do `site-shopvivaliz` é o controlador canônico para todos os
repositórios governados. Não devem existir dez watchdogs concorrentes para a
mesma política: o A1 mantém um único runtime durável e cada checkpoint carrega
obrigatoriamente a identidade `repository=owner/name`.

Repositórios governados:

- `Vivaliz-site/site-shopvivaliz`
- `Vivaliz-site/-shopvivaliz-pipeline`
- `Vivaliz-site/amazon-returns-safet`
- `Vivaliz-site/ml-pricing-api`
- `Vivaliz-site/mercadolivre-returns-recovery`
- `Vivaliz-site/shopvivaliz-m365`
- `Vivaliz-site/buscador`
- `fredmourao-ai/mei-mg-email`
- `fredmourao-ai/solange-rolla-consultorio`

Contrato:

- `agent_task_state.py start` persiste a identidade do repositório.
- O watchdog copia essa identidade para o pedido de retomada e inclui o repo no
  fingerprint.
- O dispatcher rejeita repos fora da allowlist e rejeita request/checkpoint com
  repositórios divergentes.
- O clone efêmero usa `gh repo clone` com a autenticação nativa já existente no
  host, permitindo repos públicos e privados sem colocar token em prompt/log.
- O executor Gemini e a policy headless permanecem no controlador canônico;
  cada repo consumidor contém somente o adapter `scripts/agent_task_state.py`
  necessário para atualizar o mesmo checkpoint durável.
- O E2E de produção aceita `--repository owner/name`; APTO global exige
  `continuity_e2e_pass` real para cada repositório governado, nunca apenas
  presença estática dos adapters.
- Fora do host/control-plane canônico, o adapter deve falhar fechado se não
  conseguir alcançar o controlador; criar estado local sem watchdog seria um
  falso-verde e é proibido.

A certificação global V8 continua medindo **detached recovery**. A reentrada da
mesma conversa ChatGPT é uma camada adicional e independente, definida abaixo,
e não pode ser usada para falsificar PASS do E2E V8.

<!-- CHATGPT_SESSION_REENTRY_V10 -->
## Reentrada da conversa ChatGPT após interrupção

Policy: `CHATGPT_SESSION_REENTRY_V10`.

A rota canônica é **VM backend**, nunca Fred-Win/KOCEPSV como navegador
operacional:

1. o watchdog produz o pedido `preferred_executor=chatgpt_common`;
2. `scripts/chatgpt_continuity_nudge_dispatcher.py` envia o pedido, de forma
   deduplicada, para `api/chatgpt-continuity/bridge.php`;
3. o bridge usa fila durável em `storage/private/chatgpt-continuity` e
   autenticação Bearer; o token pode vir de env ou de arquivo protegido;
4. `shopvivaliz-chatgpt-continuity.service` roda em
   `always-free-arm-1787907847-26` e anexa ao browser canônico por
   `http://127.0.0.1:9555`;
5. o worker só envia `continue` quando não há geração ativa. O monitor de
   reforço só atua quando um banner explícito de interrupção persiste depois
   da janela de confirmação, evitando duplicar a tentativa de recuperação do
   próprio cliente.

O instalador canônico é
`scripts/install-chatgpt-continuity-backend-bridge.sh`. A implementação
Windows permanece somente como legado/fallback e não é a rota operacional
padrão. O worker nunca deve imprimir token/cookie/storage de sessão e nunca
deve iniciar outro perfil do navegador.

Não executar probes sintéticos repetitivos para “testar” a conta. A prova
operacional preferida é: heartbeat autenticado do bridge + serviço backend
ativo + CDP 9555 alcançável + um nudge real correlacionado a uma interrupção
natural chegando a `SENT`/status. Enquanto faltar a última evidência, declarar
a mitigação instalada/armada, não “continuidade E2E comprovada”.
<!-- /CHATGPT_SESSION_REENTRY_V10 -->


### Resiliência de quota Gemini no background

O recovery automático continua estritamente **Gemini-only**. O modelo padrão
é o alias estável `gemini-flash-latest`; em `quota_exhausted` ou
`model_unavailable`, o wrapper protegido pode tentar
`gemini-flash-lite-latest` e, se o runtime possuir mais de uma credencial
Gemini distinta autorizada, rotacioná-las sem registrar o valor. Em
2026-09-27 um probe funcional sanitizado no A1 confirmou
`gemini-2.5-flash=model_unavailable` e confirmou sucesso real dos dois aliases
`*-latest`. Isso não autoriza Claude, Codex ou qualquer fallback
pago/silencioso no daemon.

Falhas de policy, trust ou tool registration não são mascaradas por troca de
modelo: continuam fail-closed e exigem correção da causa raiz.

O certificador global grava o relatório final do probe em arquivo JSON separado
do stdout intermediário e acumula PASS/FAIL dos nove repositórios antes de
encerrar. Uma falha individual não pode esconder o estado dos repositórios
seguintes.

<!-- /GLOBAL_TASK_CONTINUITY_V8 -->
