## Module Sweep 2026-07-15 - Round 39

- Modulo auditado: `scripts/roi-engine.php`
- Ajuste aplicado: endurecimento do helper de escrita JSON do relatório.
- Endurecimento adicional:
  - `roi_write_json()` agora falha de forma explícita quando o diretório não existe ou não está gravável
  - o helper deixa de criar diretório implicitamente
  - falha de gravação retorna string vazia em vez de presumir sucesso
- Teste adicionado: `test_roi_engine_avoids_mkdir_for_json_report`

### Arquivos alterados

- `scripts/roi-engine.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l scripts/roi-engine.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`46 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O script ainda grava o relatório markdown final por outro caminho, não endurecido nesta rodada para manter o recorte pequeno e seguro.

### Próxima tarefa recomendada

- Auditar `scripts/agent-lock-manager.php`
- Motivo: é um script curto, operacional e ainda tem criação implícita de diretório, com impacto direto na coordenação de locks.
