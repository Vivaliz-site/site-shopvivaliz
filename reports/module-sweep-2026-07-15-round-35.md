## Module Sweep 2026-07-15 - Round 35

- Modulo auditado: `api/ml/client.php`
- Ajuste aplicado: remoção da criação implícita de diretório no armazenamento de tokens ML.
- Endurecimento adicional:
  - nova rotina `ml_tokens_writable()`
  - `ml_save_tokens()` agora falha explicitamente quando o diretório de tokens não está pronto para gravação
- Teste adicionado: `test_ml_client_avoids_mkdir_for_token_storage`

### Arquivos alterados

- `api/ml/client.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l api/ml/client.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`42 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Ainda há módulos curtos em ML e integrações com padrões semelhantes, mas o ciclo pode ganhar mais cobertura saindo do domínio ML na próxima rodada.

### Próxima tarefa recomendada

- Auditar `admin/force-git-pull.php`
- Motivo: é um endpoint administrativo distinto, com escrita de log e alto potencial de exposição operacional.
