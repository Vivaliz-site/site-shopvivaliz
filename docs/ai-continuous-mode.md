# AI Continuous Mode

## Objetivo
- Manter o ciclo autonomo em execucao sem aguardar novas instrucoes humanas entre tarefas seguras.
- Sempre encadear: documentacao, autoauditoria, consulta ao backlog, consulta ao roadmap por fases e selecao da proxima tarefa elegivel.
- O ciclo tambem deve manter o runner `scripts/tri-environment-sync.js` ativo para PC, cloud/GitHub e Oracle.

## Fonte de verdade
- Backlog canonico: `tasks-queue.json`
- Roadmap por fases: `docs/ai-phase-execution.md`
- Prioridades do Diretor: `config/ai-orchestrator.json` e `api/orchestrator/director.php`
- Diretriz global de coordenacao: `docs/ai-global-coordination.md`

## Regras de selecao
- Nunca escolher tarefas que:
  - alterem preco, desconto, frete, comissao ou pagamento
  - publiquem campanhas
  - exijam aprovacao humana
  - exijam acesso manual externo
  - impliquem deploy sem autorizacao
- Priorizar, nesta ordem:
  1. fase mais cedo no roadmap
  2. prioridade do Diretor (`conversion_impact`, `seo_gap`, `catalog_readiness`)
  3. `queue_rank`

## Execucao
- Rodar `python scripts/autonomous-continuous-cycle.py --advance`
- Rodar `node scripts/tri-environment-sync.js` quando houver sincronizacao de repositorio ou coleta de status
- O ciclo:
  1. atualiza o relatorio de fases com `scripts/run-autonomy-phases.py`
  2. executa a autoauditoria com `scripts/system-health-check.py`
  3. le backlog, roadmap e prioridades do Diretor
  4. retoma a tarefa `in_progress` selecionada pelo proprio ciclo, se existir
  5. caso contrario, marca a proxima tarefa segura como `in_progress`
  6. gera relatorio local em:
     - `logs/autonomous-cycle-report.json`
     - `logs/autonomous-cycle-report.md`
     - `logs/autonomous-cycle-events.jsonl`
  7. registra o estado de sincronizacao em `logs/tri-environment-sync.json`

## Interrupcao permitida
- Somente quando houver:
  - aprovacao humana obrigatoria
  - necessidade de alterar preco
  - necessidade de aumentar orcamento
  - deploy dependente de autorizacao
  - conflito tecnico sem solucao segura
  - risco de indisponibilidade ou perda de dados

## Reporte ao Diretor
- Ao concluir cada atividade, registrar:
  - tarefa concluida
  - arquivos alterados
  - testes executados
  - resultado obtido
  - riscos identificados
  - proxima tarefa sugerida
- Cada ciclo tambem deve deixar rastros estruturados em `logs/autonomous-cycle-events.jsonl` com:
  - `changed_files`
  - `tests_executed`
  - `result`
  - `next_task`
  - `reason`
