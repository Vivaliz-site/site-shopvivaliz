## Module Sweep 2026-07-15 - Round 33

- Modulo auditado: `api/orders/create-v2.php`
- Ajuste aplicado: endurecimento focado no subfluxo de log legado de pedidos.
- Endurecimento adicional:
  - `svo_append_legacy_order_log()` agora retorna `bool`
  - o append só ocorre quando a pasta de logs já existe e está gravável
  - gravação usa `LOCK_EX` sem `@file_put_contents`
- Teste adicionado: `test_orders_create_v2_avoids_mkdir_for_legacy_order_log`

### Arquivos alterados

- `api/orders/create-v2.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/orders/create-v2.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`40 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Os módulos de pedidos ainda mantêm outros pontos de criação de diretório no fluxo principal de persistência; eles pedem uma rodada separada e mais cuidadosa.
- Próximos alvos fora do domínio de pedidos podem reduzir risco de concentração excessiva.

### Próxima tarefa recomendada

- Auditar `api/catalog/signal.php`
- Motivo: é um módulo curto, distinto, e ainda usa criação implícita de diretório para sinalização operacional.
