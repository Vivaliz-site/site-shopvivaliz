## Module Sweep 2026-07-15 - Round 47

- Modulo auditado: `scripts/observability-suite.py`
- Ajuste aplicado: remoção da criação implícita do diretório de logs no construtor.
- Endurecimento adicional:
  - nova rotina `logs_dir_ready()`
  - `run_all()` agora sinaliza quando o diretório de observabilidade não está pronto
- Teste adicionado: `test_observability_suite_avoids_mkdir_for_logs_dir`

### Arquivos alterados

- `scripts/observability-suite.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/observability-suite.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`54 passed`)

### Riscos identificados

- O recorte desta rodada ficou restrito ao side effect do construtor; o módulo em si ainda é majoritariamente demonstrativo.
- Ainda existem outros módulos curtos de infraestrutura com padrões semelhantes.

### Próxima tarefa recomendada

- Auditar `scripts/rollback-manager.py`
- Motivo: é outro script curto de infraestrutura com persistência local e potencial criação implícita de diretório.
