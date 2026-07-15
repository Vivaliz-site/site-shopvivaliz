## Module Sweep 2026-07-15 - Round 32

- Modulo auditado: `api/orders/process-validated.php`
- Ajuste aplicado: endurecimento focado no subfluxo de log de pedidos validados.
- Endurecimento adicional:
  - `svop_append_log()` agora retorna `bool`
  - o append do JSONL só ocorre quando a pasta de logs já existe e está gravável
  - gravação usa `LOCK_EX` sem `@file_put_contents`
- Teste adicionado: `test_orders_process_validated_avoids_mkdir_for_order_log`

### Arquivos alterados

- `api/orders/process-validated.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/orders/process-validated.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`39 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O mesmo módulo ainda possui outros pontos de criação de diretório para armazenamento do pedido; eles exigem revisão separada por afetarem o fluxo principal de persistência.
- Outro alvo próximo e distinto é `api/orders/create-v2.php`, com padrões semelhantes.

### Próxima tarefa recomendada

- Auditar `api/orders/create-v2.php`
- Motivo: permite continuar no domínio de pedidos, mas sem repetir o mesmo módulo dentro desta passada.
