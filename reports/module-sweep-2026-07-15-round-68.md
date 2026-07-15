## Round 68 - scripts/vulnerability-scanner.py

- Módulo tratado: `scripts/vulnerability-scanner.py`
- Ajuste aplicado: removida a criação automática do diretório de relatórios no scanner de vulnerabilidades.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/security-scan.jsonl` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_vulnerability_scanner_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/vulnerability-scanner.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `75 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/validate_20_products.py` ou `scripts/deploy-validator.py` já foi tratado; então o próximo distinto melhor é `scripts/system-health-check.py` já tratado, restando um novo alvo como `scripts/olist-headless-login.py` ou `scripts/auto-sync-daemon.py`.
