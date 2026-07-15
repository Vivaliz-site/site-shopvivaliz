## Module Sweep 2026-07-15 - Round 38

- Modulo auditado: `scripts/send-notifications.php`
- Ajuste aplicado: remoção da criação implícita de diretório no logger do notificador.
- Endurecimento adicional:
  - o construtor deixou de mutar o filesystem
  - `log()` passou a checar diretório existente e gravável
  - gravação do log agora usa `LOCK_EX`
- Teste adicionado: `test_send_notifications_avoids_mkdir_for_notification_log`

### Arquivos alterados

- `scripts/send-notifications.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l scripts/send-notifications.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`45 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O script continua dependendo da infraestrutura de envio configurada externamente; nesta rodada o endurecimento ficou restrito ao logging local.

### Próxima tarefa recomendada

- Auditar `scripts/roi-engine.php`
- Motivo: é um script curto e ainda usa criação implícita de diretório para logs/saídas operacionais.
