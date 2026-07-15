## Module Sweep 2026-07-15 - Round 49

- Modulo auditado: `scripts/utils/logger.py`
- Ajuste aplicado: remoção da criação implícita de `storage/logs` no import do módulo.
- Endurecimento adicional:
  - nova rotina `_log_dir_ready()`
  - `_ensure_header()` agora só tenta preparar o CSV quando o diretório já existe e está gravável
  - `_write()` mantém o log em stdout mesmo sem diretório pronto, e só grava CSV quando possível
- Teste adicionado: `test_utils_logger_avoids_mkdir_for_pipeline_csv`

### Arquivos alterados

- `scripts/utils/logger.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/utils/logger.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`56 passed`)

### Riscos identificados

- O comportamento de logging em arquivo agora depende explicitamente de diretório pré-provisionado, enquanto stdout continua funcionando.
- Ainda há outros utilitários curtos que podem seguir o mesmo padrão de endurecimento.

### Próxima tarefa recomendada

- Auditar `scripts/utils/config.py`
- Motivo: é um utilitário curto e central, com probabilidade alta de criação implícita de diretório.
