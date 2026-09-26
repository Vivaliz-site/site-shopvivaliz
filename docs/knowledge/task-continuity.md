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
