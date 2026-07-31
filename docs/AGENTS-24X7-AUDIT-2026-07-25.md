# Auditoria dos agentes 24/7 — 2026-07-25

## Escopo

Primeira passagem:

- `.github/workflows/agentes-listener.yml`
- `.github/workflows/agent-dual-validation.yml`
- `scripts/agentes-leitor.py`

Segunda passagem:

- `scripts/real-task-executor.py`
- `scripts/continuous-executor.py`
- `scripts/force-execution.py`
- `scripts/task-queue-processor.py`
- `.ai/agents.js`
- `scripts/system-health-check.py`

## Achados críticos — primeira passagem

1. O listener dizia executar tarefas e concluir issues, mas a função real apenas simulava a execução com `sleep(2)`.
2. Toda execução agendada podia comentar novamente nas mesmas issues, gerando spam e múltiplas falsas conclusões.
3. O endpoint de issues também retorna pull requests, mas o script não os filtrava.
4. O resumo chamado de diário era executado em todo disparo de schedule, ou seja, a cada cinco minutos.
5. O workflow de validação habilitava auto-merge no próprio PR, misturando validação, aprovação e merge sem revisão independente.
6. A primeira varredura de segredos apenas emitia aviso e ignorava documentação, permitindo credenciais em arquivos Markdown.
7. Os nomes “Peer Agent Review” e “Auto-Approval & Deploy” não correspondiam a uma revisão independente real nem a um deploy confirmado.

## Achados críticos — segunda passagem

8. `real-task-executor.py` se declarava executor real, mas apenas produzia textos fictícios de implementação, testes e deploy, depois marcava a tarefa como concluída.
9. `continuous-executor.py` simulava trabalho com `sleep(2)`, incrementava métricas de conclusão, alterava a fila e executava `git add -A`, `git commit` e `git push` sem revisão independente.
10. `force-execution.py` convertia todas as tarefas pendentes em concluídas mesmo quando APIs não estavam configuradas e nenhum trabalho havia sido executado.
11. `task-queue-processor.py` inventava tarefas padrão e as marcava como atribuídas, criando estado operacional sem fonte auditável.
12. `.ai/agents.js` retornava `success: true`, incrementava `tasks_completed` e registrava custo fictício sem chamar qualquer modelo ou ferramenta.
13. `system-health-check.py` considerava a mera existência desses scripts como sinal de saúde, portanto podia declarar saudável um conjunto de executores simulados.
14. Há múltiplos sistemas paralelos de fila, monitor e execução com conceitos de status incompatíveis; “completed”, “assigned” e “success” não têm uma definição única nem exigem evidência.

## Correções aplicadas

- substituição da execução simulada do listener por triagem segura e idempotente;
- marcador oculto para impedir comentários duplicados;
- filtro explícito de pull requests;
- remoção de labels falsas de “concluído” e “em progresso”;
- schedule de triagem a cada 15 minutos e resumo diário real às 09:00 UTC;
- remoção de auto-merge e de permissões de escrita desnecessárias;
- validação de credenciais também em Markdown, YAML, JSON e texto;
- bloqueio de comandos destrutivos novos em workflows;
- separação clara entre validação automática e autorização humana;
- desativação fail-closed dos executores que simulavam conclusão;
- preservação de compatibilidade em `.ai/agents.js`, mas com retorno explícito de falha até existir provedor real, autorização de ferramentas, testes e persistência revisada;
- proibição de avançar estado de fila sem evidência verificável.

## Critério mínimo para uma tarefa ser concluída

Uma tarefa somente pode receber estado `completed` quando houver, conforme o tipo de trabalho:

1. origem auditável, como issue ou solicitação registrada;
2. branch e commit identificáveis;
3. diff correspondente ao objetivo;
4. testes executados com resultado registrado;
5. artefatos ou logs quando aplicável;
6. revisão independente ou autorização humana;
7. merge ou implantação confirmada quando a tarefa declarar produção.

Mensagens, contadores, arquivos de log ou alterações isoladas em uma fila não constituem prova de execução.

## Limite operacional

GitHub Actions agendado não é um daemon em tempo real e pode sofrer atrasos. “24/7” aqui significa monitoramento recorrente e resiliente, não execução contínua garantida a cada segundo.

## Risco residual

O repositório ainda contém muitos scripts e documentos históricos relacionados a agentes autônomos. Nem todos são executados por workflows atuais. Eles devem ser classificados em três grupos antes de nova ativação: `ativo e comprovado`, `experimental`, ou `arquivado/desativado`.

## Resultado esperado

O sistema passa a monitorar solicitações continuamente sem afirmar que executou trabalho que não executou, sem aprovar o próprio código, sem repetir comentários indefinidamente e sem transformar estado de fila em prova de conclusão.
