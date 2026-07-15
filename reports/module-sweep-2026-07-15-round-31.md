## Module Sweep 2026-07-15 - Round 31

- Modulo auditado: `api/catalog/products.php`
- Ajuste aplicado: remoção da criação implícita de diretório no cache de preços Tiny.
- Endurecimento adicional:
  - nova rotina `svcat_write_price_cache()`
  - gravação do cache só acontece quando o diretório já existe e está gravável
  - escrita passou a usar `LOCK_EX`
- Teste adicionado: `test_catalog_products_avoids_mkdir_for_tiny_price_cache`

### Arquivos alterados

- `api/catalog/products.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/catalog/products.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`38 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Permanecem módulos mais amplos com criação implícita de diretório, especialmente `api/orders/process-validated.php` e `api/orders/create-v2.php`, que merecem análise mais cuidadosa por afetarem fluxo de pedidos.

### Próxima tarefa recomendada

- Auditar `api/orders/process-validated.php`
- Motivo: ainda concentra múltiplos pontos de `@mkdir` e escrita em fluxo crítico de pedidos validados.
