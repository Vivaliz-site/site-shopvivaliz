## Module Sweep 2026-07-15 - Round 37

- Modulo auditado: `api/ml/products.php`
- Ajuste aplicado: endurecimento focado no logger de publicação do módulo.
- Endurecimento adicional:
  - `ml_publish_log()` agora retorna `bool`
  - o append do log só ocorre quando a pasta já existe e está gravável
  - gravação usa `LOCK_EX`
- Teste adicionado: `test_ml_products_avoids_mkdir_for_publish_log`

### Arquivos alterados

- `api/ml/products.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/ml/products.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`44 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O mesmo módulo ainda mantém outro ponto de `@mkdir` em `ml_item_map_path()`, que não foi alterado nesta rodada para manter o recorte pequeno e seguro.
- Um bom próximo alvo distinto fora de ML é `scripts/send-notifications.php` ou outro endpoint administrativo/operacional curto.

### Próxima tarefa recomendada

- Auditar `scripts/send-notifications.php`
- Motivo: continua com padrão curto de log/escrita e ajuda a manter o ciclo distribuído fora de API/ML.
