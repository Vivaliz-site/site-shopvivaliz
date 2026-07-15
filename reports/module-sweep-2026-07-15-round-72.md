## Round 72 - scripts/validate_20_products.py

- Módulo tratado: `scripts/validate_20_products.py`
- Ajuste aplicado: removida a criação automática do diretório do relatório detalhado de validação.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/validation_20_products.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_validate_20_products_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/validate_20_products.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `79 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/olist-selenium-login.py` ou `scripts/olist-sync-manual.py`, ambos ainda candidatos a criação automática de diretórios de saída/log.
