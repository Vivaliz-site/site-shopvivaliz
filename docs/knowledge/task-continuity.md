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
- Nenhum daemon/cron/watch deve consumir Codex automaticamente por padrão. Exceção explicitamente autorizada em 2026-10-01: o controlador Gemini 24/7 pode executar exatamente um fallback finito `codex-auto` por fingerprint elegível, somente depois de Gemini não produzir progresso, com lease/deduplicação/cooldown do dispatcher e `SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1`; Codex continua sendo a última opção e nunca transforma ACK/exit code em conclusão.
- No backend OCI atual, o fallback finito `codex-auto` usa `--sandbox danger-full-access --ask-for-approval never` porque o sandbox Linux da CLI depende de user namespaces indisponíveis nesse host. Isso **não** altera o critério de sucesso: a execução só é aceita quando o `task_state_signature` muda no mesmo fingerprint; resposta textual/exit 0 sem avanço continua `no_progress`.
<!-- /CODEX_LAST_RESORT_V1 -->

<!-- TASK_CONTINUITY_AUTO_RESUME_V4 -->
## Confirmação real da retomada do ChatGPT

Policy: `CHATGPT_PROGRESS_CONFIRMATION_V11`.

Para a reentrada da conversa ChatGPT, **enviar/clicar em `continue` não é
sucesso**. O worker deve distinguir:

- `PROGRESS_CONFIRMED`: surgiu progresso observável do assistente após o
  envio; este é o único resultado de sucesso da camada ChatGPT para o mesmo
  fingerprint;
- `SENT_UNCONFIRMED`: o envio foi aceito pela UI, mas não surgiu progresso
  observável dentro da janela de confirmação; continua retryable;
- `STALLED_NOT_CONFIRMED`, `CONVERSATION_NOT_FOUND` e `ERROR`: falhas
  retryable conforme cooldown.

Regras obrigatórias:

1. o dispatcher consulta o resultado real do worker antes de tratar um
   `bridge_ok=true` como sucesso;
2. `SENT` legado é ambíguo e nunca deve ser tratado como progresso
   confirmado;
3. no máximo duas tentativas Web são permitidas para o mesmo fingerprint
   inalterado; depois disso a conversa não recebe spam automático;
4. um envio sem progresso libera o executor desacoplado, em vez de bloquear a
   tarefa como se o ChatGPT tivesse retomado;
5. `PENDING`/`CLAIMED` preservam a primeira chance do ChatGPT enquanto a
   tentativa está realmente em voo;
6. mudança real no checkpoint gera novo fingerprint e reinicia legitimamente
   o ciclo de recuperação.

Objetivo: impedir falso-verde e garantir que uma falha do stream/conversa não
abandone a tarefa nem gere uma tempestade de mensagens de retomada.

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
- O dispatcher de background marca `SHOPVIVALIZ_RESUME_BACKGROUND=1`. `Gemini` é sempre a rota primária. Quando `SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1`, autorização explícita vigente desde 2026-10-01 permite um único fallback finito `codex-auto` por fingerprint elegível, somente depois de Gemini não produzir progresso; lease, deduplicação e cooldown continuam obrigatórios. Claude/GPT permanecem proibidos como fallback silencioso de daemon/cron.
- Em execução finita/interativa fora do background permanece a ordem
  `Gemini -> Claude -> Codex`; Codex continua sendo a última opção e usa
  login ChatGPT, sem `OPENAI_API_KEY`.
- Saída zero do executor **não** prova retomada. Só há sucesso se a máquina de
  estados durável mudar materialmente (status/next_action/evidência/verificação)
  ou chegar a `CONCLUIDO`/`BLOCKED_EXTERNAL`.
- Sem avanço, o fingerprint é registrado em `_resume-executions.jsonl`; o mesmo checkpoint pode ser tentado
  novamente após cooldown (900 s padrão, configurável por
  `SHOPVIVALIZ_RESUME_RETRY_AFTER_SECONDS`). Nunca há mais de uma tentativa por
  ciclo. A tarefa continua `RUNNING` até progresso real ou terminal válido.
- Nenhuma saída de provider, prompt ou segredo é publicada no ledger; somente
  metadados de execução e resultado.
- O diagnóstico persistido em `_resume-executions.jsonl` (chave `diagnostic`)
  é estritamente alocado por allowlist (`provider`, `provider_status`,
  `provider_attempt_exit_code`, `background_gemini_error`,
  `background_gemini_exit_code`, `background_paid_fallback_forbidden`,
  `background_codex_fallback_authorized`, `provider_output_bytes`,
  `provider_output_sha256`) — nunca prompt bruto,
  stdout/stderr bruto, tokens ou segredos.
- ACK de fila (`agent-operations-worker.py` reconhecendo um pedido
  `auto_resume` para o painel/timeline interno) **não é execução**. O evento
  correspondente usa `kind=auto-resume-queued`/`auto-resume-ack` e a mensagem
  deixa explícito que aquilo não comprova execução real. Só a cadeia real
  (watchdog → dispatcher → `autonomous-provider-failover.sh` → Gemini primário
  [→ `codex-auto` apenas se explicitamente autorizado e necessário] → mudança
  material do checkpoint) é evidência.
<!-- /DETACHED_CONTINUATION_EXECUTOR_V6 -->

<!-- GEMINI_24X7_CONTROLLER_V1 -->
## Controlador Gemini 24x7 no backend

O controlador `scripts/gemini_24x7_controller.py` supervisiona a pilha já
existente, sem substituí-la: watchdog determinístico → nudge de ChatGPT comum
→ dispatcher finito com Gemini primário e fallback `codex-auto` explicitamente autorizado. Ele mantém um lease atômico no runtime
compartilhado, registra apenas metadados sanitizados e recusa propriedade
duplicada enquanto o lease estiver vivo. Além do lease por ciclo, o modo
`--daemon` mantém um lock exclusivo durante toda a vida do processo; um segundo
daemon falha fechado antes de executar watchdog, nudge ou dispatcher. O nudge
ChatGPT possui um lock transacional próprio cobrindo leitura do ledger, decisão,
chamada ao bridge e persistência do resultado, impedindo duplo envio por TOCTOU.
Após interrupção/crash, lease vencido é recuperado e registrado antes de novo ciclo.

Os checkpoints em `agent_task_state.py` serializam toda transição
`load -> mutate -> atomic write` com um lock compartilhado entre processos.
A gravação faz `fsync` no arquivo, `os.replace` e `fsync` no diretório pai;
`start` repetido para a mesma identidade é idempotente e uma colisão de
`task_id` com objetivo/repositório diferentes falha fechado, sem sobrescrever
histórico. O estado-resumo do controlador continua sendo atualizado a cada ciclo,
mas o ledger de eventos só cresce quando existe atividade material, recuperação
de lease ou anomalia, evitando crescimento ocioso a cada 30 segundos.

A unidade canônica é `shopvivaliz-gemini-24x7-controller.service` no backend
`always-free-arm-1787907847-26`; ela deve estar `enabled` e `active`. Ela usa
`KillMode=control-group`, backoff limitado e nunca conclui checkpoint por ACK,
PID, exit code ou resposta HTTP. O daemon mantém
`_gemini-24x7-controller-daemon.lock` durante toda a vida do processo, portanto
dois daemons não podem alternar ownership entre ciclos. O nudge ChatGPT e o
fallback detached compartilham `_continuity-execution.lock`: enquanto um efeito
externo de retomada estiver em voo, o outro tier fica suprimido. O lease durável
registra PID, boot id e start ticks; se o processo proprietário morrer, o restart
recupera o lease imediatamente, sem aguardar o TTL. Leases legados sem identidade
continuam fail-closed pelo TTL. A instalação só pode partir de uma release
imutável já publicada; nunca editar `current/` ou a release ativa.
<!-- /GEMINI_24X7_CONTROLLER_V1 -->

<!-- DETACHED_TASK_RECOVERY_E2E_V7 -->
## Prova E2E da retomada na mesma conversa ChatGPT

Policy: `DETACHED_TASK_RECOVERY_E2E_V7`.

A recuperação detached continua sendo o fallback legítimo para tarefas normais.
Ela, porém, **não pode certificar a retomada do browser na mesma conversa**. O
probe sintético `continuity-e2e-*` existe exclusivamente para provar a cadeia
watchdog → fila → bridge/worker → conversa ChatGPT explicitamente vinculada.

- `scripts/task_continuity_e2e.py` cria exatamente um checkpoint sintético
  `RUNNING`, vincula um `conversation_id` explícito e grava como `next_action`
  somente os dois comandos allowlisted `agent_task_state.py ready` e
  `agent_task_state.py complete`. Depois disso o probe apenas observa o estado
  e os ledgers; ele nunca invoca watchdog/dispatcher diretamente.
- O dispatcher reconhece estritamente esse contrato e **não libera fallback
  detached/Gemini/CLI para `continuity-e2e-*`**. Esse isolamento é restrito ao
  probe de browser e não desabilita a retomada automática das tarefas reais.
- PASS exige o mesmo `task_id`, fingerprint e `conversation_id` no request e no
  nudge, `worker_status=PROGRESS_CONFIRMED`, checkpoint final
  `CONCLUIDO`, `verification=continuity_e2e_pass`, histórico contendo
  `ready_to_complete` e `completed` sem `resume_request_id`, e ausência de
  qualquer execução correspondente em `_resume-executions.jsonl`.
- `scripts/chatgpt_continuity_proof_certifier.py` repete essas verificações de
  provenance de forma fail-closed; `ok=true` é obrigatório antes de aceitar a
  evidência como prova da mesma conversa.
- Falha/timeout preserva evidência e quarentena o checkpoint sintético fora da
  raiz ativa. Se o arquivo histórico preferencial não puder ser usado, a
  quarentena cai em `agent-task-state/_e2e-failures/`, subdiretório que não é
  varrido pelo watchdog. Nunca se deixa um JSON sintético `RUNNING` residual na
  raiz ativa.
- O workflow `.github/workflows/task-continuity-production-e2e.yml` roda no
  runner `[self-hosted, Linux, ARM64, shopvivaliz-backend-browser]`, porque a
  prova depende do browser canônico autenticado no backend. Browser de
  Fred-Win/KOCEPSV e conversas reais de negócio são proibidos para probes.
- A conversa de teste deve ser dedicada e fornecida explicitamente ao workflow;
  a automação nunca seleciona conversa por atividade recente, título ou fallback
  ambíguo.

Um terminal criado por Gemini/Codex/CLI, ainda que contenha exatamente
`continuity_e2e_pass`, é falso-verde para esta prova e deve ser rejeitado.
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

<!-- CHATGPT_AUTO_RESUME_AUTHORIZED_V11 -->
## Retomada automática autorizada permanece ativa

A retomada da mesma conversa após interrupção foi explicitamente solicitada pelo usuário e não deve ser globalmente desativada apenas porque existe investigação de suporte em andamento. A segurança dessa rota é feita por controles concretos: checkpoint RUNNING atual, fingerprint correspondente, deduplicação, cooldown de falha de transporte, não envio durante stream realmente ativo e uso exclusivo do navegador canônico já autenticado.

Probes sintéticos, login automatizado, criação de chats de diagnóstico e testes repetitivos de turno continuam fora desta autorização e devem permanecer separados.

Codex não é fallback automático desta rota.
<!-- /CHATGPT_AUTO_RESUME_AUTHORIZED_V11 -->

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
   reforço só atua quando existe checkpoint não terminal (`RUNNING` ou
   `READY_TO_COMPLETE`) **e** o health do browser canônico está recente e
   `AUTHENTICATED`; sem tarefa ativa não faz discovery da conta, e estados
   `AUTH_FLOW`, `AUTH_TERMINAL`, `LOGGED_OUT`, `UNKNOWN` ou health stale ficam
   em quiescência fail-closed;
6. a mensagem de plataforma de **verificações adicionais** (`additional_checks`)
   é um estado de deferimento: não autoriza reload, novo `continue` nem troca
   automática para modelo mais rápido. O reinforcement entra em cooldown de
   cinco minutos antes de nova tentativa;
7. interrupções realmente recuperáveis continuam exigindo confirmação de
   progresso do assistente para sair da degradação.

O monitor grava heartbeat durável em
`agent-task-state/_chatgpt-continuity-monitor-state.json`. Em idle ou durante
autenticação ele mantém somente o heartbeat local; sweep/discovery de
conversas é permitido apenas com checkpoint ativo e sessão autenticada. Falhas
ficam latched até recuperação confirmada; um ciclo neutro não pode apagar
degradação anterior. O controlador trata heartbeat ausente ou stale como
`chatgpt_browser_monitor_stale` e falha fechado em `continuity_ready=false`.
O instalador do worker fixa `SHOPVIVALIZ_AGENT_TASK_STATE_DIR` e libera
explicitamente esse diretório no sandbox do systemd; depender apenas do path
default do código é proibido.

O instalador canônico é
`scripts/install-chatgpt-continuity-backend-bridge.sh`. Existe um único owner
de supervisão do Chrome: `shopvivaliz-chatgpt-browser-guardian.timer`. O
instalador deve desabilitar/remover o antigo
`shopvivaliz-browser-healthcheck.timer`/`.service` e seu script para impedir
restarts concorrentes do mesmo perfil autenticado. A implementação Windows
permanece somente como legado/fallback e não é a rota operacional padrão. O
worker nunca deve imprimir token/cookie/storage de sessão e nunca deve iniciar
outro perfil do navegador.

Não executar probes sintéticos repetitivos para “testar” a conta. A prova
operacional preferida é: heartbeat autenticado do bridge + serviço backend
ativo + CDP 9555 alcançável + um nudge real correlacionado a uma interrupção
natural chegando a `PROGRESS_CONFIRMED` na mesma conversa. `SENT` ou
`SENT_UNCONFIRMED` não certificam retomada. Enquanto faltar a última
evidência, declarar a mitigação instalada/armada, não “continuidade E2E
comprovada”.
<!-- /CHATGPT_SESSION_REENTRY_V10 -->

<!-- CLAUDE_REMOTE_CONTROL_SESSION_DURABILITY_V1 -->
## Durabilidade das sessoes Claude Remote Control

O servidor `shopvivaliz-claude-remote-control.service` deve manter capacidade
de reanexar as sessoes servidas depois de uma saida/restart do processo. O
`ExecStart` canônico usa `claude remote-control --spawn worktree` e **nao pode**
usar `--no-create-session-in-dir`, pois essa flag arquiva as sessoes do servidor
quando ele para e impede a retomada pelo novo processo. `Restart=always` e
mantido para recuperar saidas limpas causadas por falha prolongada de rede ou
do ambiente remoto.

`StandardOutput` e `StandardError` devem ir para o journal para que uma saida
limpa nao vire falso-verde sem causa observavel. O comando `status` do instalador
falha fechado com `service_session_recovery_disabled` se o unit instalado
reintroduzir a flag que descarta retomada. Servico `active` sem executor de
sessao nao comprova continuidade do chat remoto.
<!-- /CLAUDE_REMOTE_CONTROL_SESSION_DURABILITY_V1 -->


### Resiliência de quota Gemini no background

O recovery automático usa **Gemini como primário**. O modelo padrão é o alias estável `gemini-flash-latest`; em `quota_exhausted` ou
`model_unavailable`, o wrapper protegido pode tentar
`gemini-flash-lite-latest` e, se o runtime possuir mais de uma credencial
Gemini distinta autorizada, rotacioná-las sem registrar o valor. Em
2026-09-27 um probe funcional sanitizado no A1 confirmou
`gemini-2.5-flash=model_unavailable` e confirmou sucesso real dos dois aliases
`*-latest`. Claude/GPT continuam proibidos como fallback silencioso. O `codex-auto` só é permitido na exceção explícita e finita do controlador 24/7 (`SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1`), sempre depois de Gemini falhar em produzir progresso.

Falhas de policy, trust ou tool registration não são mascaradas por troca de
modelo: continuam fail-closed e exigem correção da causa raiz.

O certificador global grava o relatório final do probe em arquivo JSON separado
do stdout intermediário e acumula PASS/FAIL dos nove repositórios antes de
encerrar. Uma falha individual não pode esconder o estado dos repositórios
seguintes.

<!-- /GLOBAL_TASK_CONTINUITY_V8 -->


<!-- RESUME_QUEUE_CERTIFICATION_V12 -->
### Certificacao e compactacao da fila de retomada

`_resume-requests.jsonl` e a **fila operacional ativa**, nao o historico completo.
Cada ciclo do watchdog certifica a fila contra os checkpoints duraveis atuais.
Somente uma linha `queued` unica cujo `task_id`, repositorio,
`checkpoint_updated_at`, `next_action` e fingerprint coincidam com um
checkpoint `RUNNING` atual permanece acionavel.

Linhas de checkpoint terminal, superseded/mismatched, orfas, duplicadas,
malformadas ou com status nao operacional saem da fila ativa e sao preservadas
em `_resume-requests-archive.jsonl`. A compactacao usa
`_resume-queue.lock`, append com fsync no arquivo de auditoria e replace
atomico + fsync para a fila ativa. Assim, historico nao e contado como
`pending` nem percorrido pelos dispatchers em cada tick.

`scripts/task_resume_queue.py` fornece duas operacoes deterministicas:
`certify_queue` (somente contagens agregadas, sem payloads) e
`compact_queue` (preserva auditoria e mantem apenas trabalho atual unico).
O watchdog executa essa manutencao antes de emitir uma nova solicitacao e
recertifica depois do append. Uma fila grande de requests correntes continua
visivel como `oversized=true`; nunca e truncada apenas por tamanho.

Mudancas na superficie de continuidade exigem o `Task Continuity Fast Gate`.
O `Mandatory Validation Gate` tambem executa a regressao Node do bridge para
impedir que um teste de continuidade vermelho seja mesclado como falso-verde.


<!-- CONTINUITY_QUEUE_CERTIFICATION_V13 -->
### Certificacao das tres filas de continuidade

As filas de continuidade tem semanticas diferentes e nunca devem ser somadas
num unico numero de "pendencias":

1. **Resume requests**: `_resume-requests.jsonl` contem apenas requests
   atualmente acionaveis; historico terminal/superseded/orfao/duplicado fica em
   `_resume-requests-archive.jsonl`.
2. **Bridge nudges**: `pending-nudges.json` mantem apenas PENDING/CLAIMED e
   resultados resolvidos recentes. Resolvidos apos a janela de retencao saem
   do hot store para `pending-nudges-archive.jsonl`, mas `status(task_id)`
   continua consultando o ultimo resultado arquivado para compatibilidade.
   A operacao autenticada `queue_status` expoe somente contagens agregadas:
   active, pending, claimed, resolved_recent, invalid, archive_rows e certified.
3. **GitHub Actions**: o job `Task Continuity Actions Queue Hygiene` roda em
   GitHub-hosted runner e classifica runs `queued`. Cancelamento automatico e
   restrito a runs `pull_request` com mais de 6 horas cujo head nao corresponda
   a nenhum PR aberto no momento da certificacao. Runs de `issue_comment`,
   `workflow_run`, push, workflow_dispatch ou PR aberto nunca sao cancelados
   por essa rotina.

A limpeza de Actions revalida o run imediatamente antes do cancelamento para
evitar corrida de estado. O `GITHUB_TOKEN` e usado somente no proprio workflow,
nao e impresso e nao e persistido.

Mudancas em `api/chatgpt-continuity/**`,
`includes/chatgpt-continuity/**`, `task_resume_queue.py`, watchdog ou higiene
de Actions devem acionar o `Task Continuity Fast Gate`.
Se `main` avancar enquanto o PR de continuidade estiver em validacao, os gates devem ser reexecutados contra a nova base antes do merge; verde calculado apenas contra base anterior nao certifica a integracao final.
<!-- /CONTINUITY_QUEUE_CERTIFICATION_V13 -->

### Prova de conclusão e concorrência de retomadas

O dispatcher registra `SHOPVIVALIZ_RESUME_HISTORY_LENGTH` e a identidade do
request no histórico. Um executor de checkpoint antigo não pode certificar
`ready`/`complete` depois de avanço concorrente; ele deve reler o estado e
continuar a ação atual. A retomada automática permanece habilitada.

Tarefas críticas devem fixar verificações objetivas na criação, usando
`agent_task_state.py start --completion-check '["/usr/bin/test","-f","/caminho/artefato"]'`.
São aceitos apenas probes read-only limitados de arquivo, hash e serviços
canônicos (no máximo quatro; timeout de 10s por probe). As verificações usam
argv, sem shell, não podem conter secrets e executam de
novo tanto em `ready` quanto em `complete`. Saída não é registrada; recibos
contêm somente índice, hash do argv, timestamp e exit code. Uma frase PASS
não substitui essas verificações. Checkpoints com checks usam schema 2: clientes
antigos devem rejeitá-los em vez de ignorar o contrato de prova. Atualize o CLI
antes de retomá-los. Tarefas legadas sem checks mantêm contrato
compatível e sua conclusão textual não certifica aptidão por si só.

O worker registra `sent` somente quando o envio efetivo ocorreu; progresso
restaurado por reattach passivo e tentativa rejeitada não contam como envio.
