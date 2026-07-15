## Module Sweep 2026-07-15 - Round 50

- Modulo auditado: `scripts/utils/config.py`
- Ajuste aplicado: remoção da criação implícita de diretórios no import do módulo de configuração.
- Endurecimento adicional:
  - nova rotina `dir_ready()`
  - novo mapa `PATHS_READY` para expor a prontidão dos diretórios importantes sem mutar o filesystem
- Teste adicionado: `test_utils_config_avoids_mkdir_on_import`

### Arquivos alterados

- `scripts/utils/config.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/utils/config.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`57 passed`)

### Riscos identificados

- O módulo foi regravado para contornar problemas de codificação e remover side effects de import; convém manter atenção a futuros merges nesse arquivo.
- Outros utilitários Python ainda podem ter comportamento parecido.

### Próxima tarefa recomendada

- Auditar `scripts/mcp-local-autostart.py`
- Motivo: é um script curto de infraestrutura local com persistência de relatórios e potencial criação implícita de diretório.
