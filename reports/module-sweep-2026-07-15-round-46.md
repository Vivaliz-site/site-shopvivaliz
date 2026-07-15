## Module Sweep 2026-07-15 - Round 46

- Modulo auditado: `scripts/metrics-collector.py`
- Ajuste aplicado: remoção da criação implícita de diretório no coletor de métricas.
- Endurecimento adicional:
  - nova rotina `metrics_dir_ready()`
  - `log_task_completion()` agora falha de forma explícita com `False` quando o diretório de métricas não está pronto para escrita
  - o construtor deixa de criar a pasta `logs/`
- Teste adicionado: `test_metrics_collector_avoids_mkdir_for_metrics_log`

### Arquivos alterados

- `scripts/metrics-collector.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/metrics-collector.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`53 passed`)

### Riscos identificados

- O script ainda escreve o relatório markdown final fora do recorte desta rodada.
- Outros scripts de observabilidade/infraestrutura ainda podem manter padrões parecidos.

### Próxima tarefa recomendada

- Auditar `scripts/observability-suite.py`
- Motivo: é outro módulo curto de infraestrutura com escrita local e provável criação implícita de diretório.
