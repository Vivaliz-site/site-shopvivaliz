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
- Dentro da camada CLI finita permanece a ordem `Gemini -> Claude -> Codex`;
  Codex continua sendo a última opção e usa login ChatGPT, sem
  `OPENAI_API_KEY`.
- Saída zero do executor **não** prova retomada. Só há sucesso se a máquina de
  estados durável mudar materialmente (status/next_action/evidência/verificação)
  ou chegar a `CONCLUIDO`/`BLOCKED_EXTERNAL`.
- Sem avanço, o fingerprint é registrado em `_resume-executions.jsonl` e não é
  repetido automaticamente, evitando loop pago. A tarefa continua `RUNNING`.
- Nenhuma saída de provider, prompt ou segredo é publicada no ledger; somente
  metadados de execução e resultado.
<!-- /DETACHED_CONTINUATION_EXECUTOR_V6 -->
