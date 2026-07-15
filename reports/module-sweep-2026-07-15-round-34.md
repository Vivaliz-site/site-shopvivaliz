## Module Sweep 2026-07-15 - Round 34

- Modulo auditado: `api/catalog/signal.php`
- Ajuste aplicado: remoção da criação implícita de diretório no tracker de sinais do catálogo.
- Endurecimento adicional:
  - nova rotina `svsig_write()`
  - gravação do arquivo de sinais só ocorre quando o diretório de storage já existe e está gravável
  - o endpoint agora responde `signal_storage_unavailable` quando não consegue persistir o contador
- Teste adicionado: `test_catalog_signal_avoids_mkdir_and_surfaces_storage_unavailability`

### Arquivos alterados

- `api/catalog/signal.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/catalog/signal.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`41 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Ainda restam módulos curtos e distintos com padrões parecidos, como `api/ml/client.php` e `api/ml/products.php`, além de áreas mais sensíveis já mapeadas.

### Próxima tarefa recomendada

- Auditar `api/ml/client.php`
- Motivo: é um módulo curto, distinto do catálogo, e ainda usa criação implícita de diretório para persistência local.
