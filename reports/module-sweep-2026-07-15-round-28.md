## Module Sweep 2026-07-15 - Round 28

- Modulo auditado: `admin/sync-critical-files.php`
- Ajuste aplicado: remoção da criação implícita de diretório e adoção de escrita atômica para sincronização dos arquivos críticos.
- Endurecimento adicional:
  - nova rotina `sync_write_file_atomic()`
  - escrita só ocorre quando o diretório de destino já existe e está gravável
  - arquivo temporário + `rename()` reduzem risco de arquivo parcial
- Teste adicionado: `test_admin_sync_critical_files_uses_atomic_write_without_mkdir`

### Arquivos alterados

- `admin/sync-critical-files.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l admin/sync-critical-files.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`35 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Restam endpoints com padrão semelhante, especialmente `api/olist/webhook-processor.php` e `api/sync/full-sync.php`.

### Próxima tarefa recomendada

- Auditar `api/olist/webhook-processor.php`
- Motivo: ainda usa `@mkdir` e append de log em endpoint de integração externa.
