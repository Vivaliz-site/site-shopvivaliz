## Round 55 - scripts/agent-operations-worker.py

- Módulo tratado: `scripts/agent-operations-worker.py`
- Ajuste aplicado: removida a criação automática de diretórios operacionais (`.agent-heartbeats`, `logs`, `storage/private`) durante bootstrap e escrita.
- Hardening aplicado:
  - adicionado `dir_ready(path: Path) -> bool`;
  - `ensure_dirs()` agora exige diretórios previamente provisionados;
  - `write_json()` e `append_jsonl()` agora falham com `FileNotFoundError` se o diretório pai estiver indisponível;
  - `write_heartbeats()` valida explicitamente a disponibilidade de `.agent-heartbeats`.
- Teste adicionado: `test_agent_operations_worker_avoids_mkdir_for_operational_dirs`
- Validações executadas:
  - `python -m py_compile scripts/agent-operations-worker.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `62 passed`
- Riscos identificados:
  - o worker passa a depender de provisionamento prévio dos diretórios operacionais, o que é desejado para produção, mas precisa estar alinhado com quem inicializa o runtime.
- Próximo módulo seguro recomendado:
  - `scripts/system-health-check.py` ou `scripts/deploy-validator.py`, ambos candidatos fortes para o mesmo padrão de escrita defensiva.
