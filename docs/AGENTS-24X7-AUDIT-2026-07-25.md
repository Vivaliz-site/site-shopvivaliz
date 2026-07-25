# Auditoria dos agentes 24/7 — 2026-07-25

## Escopo

- `.github/workflows/agentes-listener.yml`
- `.github/workflows/agent-dual-validation.yml`
- `scripts/agentes-leitor.py`

## Achados críticos

1. O listener dizia executar tarefas e concluir issues, mas a função real apenas simulava a execução com `sleep(2)`.
2. Toda execução agendada podia comentar novamente nas mesmas issues, gerando spam e múltiplas falsas conclusões.
3. O endpoint de issues também retorna pull requests, mas o script não os filtrava.
4. O resumo chamado de diário era executado em todo disparo de schedule, ou seja, a cada cinco minutos.
5. O workflow de validação habilitava auto-merge no próprio PR, misturando validação, aprovação e merge sem revisão independente.
6. A primeira varredura de segredos apenas emitia aviso e ignorava documentação, permitindo credenciais em arquivos Markdown.
7. Os nomes “Peer Agent Review” e “Auto-Approval & Deploy” não correspondiam a uma revisão independente real nem a um deploy confirmado.

## Correções aplicadas

- substituição da execução simulada por triagem segura e idempotente;
- marcador oculto para impedir comentários duplicados;
- filtro explícito de pull requests;
- remoção de labels falsas de “concluído” e “em progresso”;
- schedule de triagem a cada 15 minutos e resumo diário real às 09:00 UTC;
- remoção de auto-merge e de permissões de escrita desnecessárias;
- validação de credenciais também em Markdown, YAML, JSON e texto;
- bloqueio de comandos destrutivos novos em workflows;
- separação clara entre validação automática e autorização humana.

## Limite operacional

GitHub Actions agendado não é um daemon em tempo real e pode sofrer atrasos. “24/7” aqui significa monitoramento recorrente e resiliente, não execução contínua garantida a cada segundo.

## Resultado esperado

O sistema passa a monitorar solicitações continuamente sem afirmar que executou trabalho que não executou, sem aprovar o próprio código e sem repetir comentários indefinidamente.
