## Module Sweep 2026-07-15 - Round 44

- Modulo auditado: `scripts/mcp-server.py`
- Ajuste aplicado: remoção da criação implícita de diretório na inicialização do logger e no tool de escrita de arquivo.
- Endurecimento adicional:
  - novas rotinas `logs_dir_ready()` e `parent_dir_ready()`
  - logging em arquivo só é ativado quando `logs/` já existe e está gravável
  - `_write_file()` agora retorna erro explícito quando o diretório pai não está pronto para escrita
- Teste adicionado: `test_mcp_server_avoids_mkdir_for_logs_and_write_tool`

### Arquivos alterados

- `scripts/mcp-server.py`
- `tests/test_production_hardening.py`

### Validações executadas

- `python -m py_compile scripts/mcp-server.py`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Compilação Python ok
- Suite de hardening ok (`51 passed`)

### Riscos identificados

- O logger do MCP agora depende de diretório de logs pré-existente para gravação em arquivo; o stream handler continua ativo mesmo sem esse diretório.
- Outros scripts Python ainda podem conter criação implícita de diretório em recortes específicos.

### Próxima tarefa recomendada

- Auditar `scripts/task_queue_lib.py`
- Motivo: é um script curto de infraestrutura e ainda usa criação implícita em caminho de persistência.
