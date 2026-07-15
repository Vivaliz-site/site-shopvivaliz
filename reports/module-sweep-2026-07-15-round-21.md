## Module Sweep 2026-07-15 - Round 21

- Modulo auditado: `api/autonomous/approval-queue-manager.php`
- Ajuste aplicado: `saveQueue()` deixou de criar diretório dinamicamente e agora persiste a fila apenas quando a pasta já existe e está gravável.
- Endurecimento adicional: o método passou a retornar `bool`, permitindo detectar falha de persistência sem mutações implícitas no filesystem.
- Teste adicionado: `test_autonomous_approval_queue_manager_avoids_mkdir_for_queue_save`

### Arquivos alterados

- `api/autonomous/approval-queue-manager.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/autonomous/approval-queue-manager.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`28 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Módulos autônomos ainda com `@mkdir` em runtime: `api/autonomous/incident-manager.php`, `api/autonomous/maintenance-controller.php` e `api/autonomous/backup-manager.php`.

### Próxima tarefa recomendada

- Auditar `api/autonomous/incident-manager.php`
- Motivo: centraliza evidências e logs de incidentes, então qualquer escrita implícita no filesystem ali tem impacto operacional maior.
