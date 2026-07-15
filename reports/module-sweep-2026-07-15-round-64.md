## Round 64 - scripts/verify_marketplace_upload.py

- Módulo tratado: `scripts/verify_marketplace_upload.py`
- Ajuste aplicado: removida a criação automática do diretório do relatório de verificação de marketplace.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/marketplace_verification_report.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_verify_marketplace_upload_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/verify_marketplace_upload.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `71 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/validate_20_products.py` ou `scripts/deploy-validator.py` já foi tratado; então o próximo distinto melhor é `scripts/ml-readiness-report.py` ou `scripts/shopee-readiness-report.py`.
