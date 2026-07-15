## Round 67 - scripts/heartbeat-executor.py

- Módulo tratado: `scripts/heartbeat-executor.py`
- Ajuste aplicado: removida a criação automática do diretório de logs durante a gravação do heartbeat do executor.
- Hardening aplicado:
  - adicionado `log_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/executor-heartbeat.log` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de log não estiver disponível.
- Teste adicionado: `test_heartbeat_executor_avoids_mkdir_for_log_dir`
- Validações executadas:
  - `python -m py_compile scripts/heartbeat-executor.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `74 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/vulnerability-scanner.py` ou `scripts/validate_20_products.py`, ambos ainda candidatos a criação automática de diretórios/relatórios.
