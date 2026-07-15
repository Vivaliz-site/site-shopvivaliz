## Module Sweep 2026-07-15 - Round 45

- Modulo auditado: `scripts/task_queue_lib.py`
- Ajuste aplicado: remoção da criação implícita de diretório ao persistir a fila de tarefas.
- Endurecimento adicional:
  - `save_queue()` agora exige diretório pai pré-existente
  - falha de persistência por diretório ausente agora é explícita via `FileNotFoundError`
- Teste adicionado: `test_task_queue_lib_avoids_mkdir_for_queue_files`

### Arquivos alterados

- `scripts/task_queue_lib.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/task_queue_lib.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`52 passed`)

### Riscos identificados

- A persistência da fila agora depende explicitamente de diretórios previamente provisionados, o que é consistente com o endurecimento aplicado em outros scripts.
- Ainda há módulos Python curtos com padrões parecidos, por exemplo `scripts/metrics-collector.py` e `scripts/observability-suite.py`.

### Próxima tarefa recomendada

- Auditar `scripts/metrics-collector.py`
- Motivo: é um próximo alvo curto de infraestrutura com persistência local e potencial de criação implícita de diretório.
