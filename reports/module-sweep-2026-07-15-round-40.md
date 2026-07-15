## Module Sweep 2026-07-15 - Round 40

- Modulo auditado: `scripts/agent-lock-manager.php`
- Ajuste aplicado: remoção da criação implícita do diretório de locks.
- Endurecimento adicional:
  - nova rotina `lockDirReady()`
  - `acquireLock()` agora falha de forma explícita quando o diretório de locks não existe ou não está gravável
  - gravação do lock usa `LOCK_EX`
- Teste adicionado: `test_agent_lock_manager_avoids_mkdir_for_lock_dir`

### Arquivos alterados

- `scripts/agent-lock-manager.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l scripts/agent-lock-manager.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`47 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O coordenador de locks continua dependente de diretório pré-provisionado; isso é intencional após o endurecimento, mas merece documentação operacional se ainda não existir.

### Próxima tarefa recomendada

- Auditar `scripts/agent-heartbeat-monitor.php`
- Motivo: é um script operacional curto e ainda usa criação implícita de diretório para heartbeats.
