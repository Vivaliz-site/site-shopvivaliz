## Module Sweep 2026-07-15 - Round 41

- Modulo auditado: `scripts/agent-heartbeat-monitor.php`
- Ajuste aplicado: remoção da criação implícita do diretório de heartbeats.
- Endurecimento adicional:
  - nova rotina `heartbeatDirReady()`
  - `recordHeartbeat()` agora retorna `bool`
  - gravação do heartbeat usa `LOCK_EX` e só ocorre quando o diretório já existe e está gravável
- Teste adicionado: `test_agent_heartbeat_monitor_avoids_mkdir_for_heartbeat_dir`

### Arquivos alterados

- `scripts/agent-heartbeat-monitor.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l scripts/agent-heartbeat-monitor.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`48 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- Assim como no lock manager, a pasta de heartbeats agora precisa existir e estar gravável por provisionamento prévio.

### Próxima tarefa recomendada

- Auditar `api/ml/products.php` novamente, agora no recorte de `ml_item_map_path()`
- Motivo: o logger já foi endurecido, mas ainda resta um `@mkdir` pequeno e isolado no mesmo módulo.
