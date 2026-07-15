## Round 65 - scripts/ml-readiness-report.py

- Módulo tratado: `scripts/ml-readiness-report.py`
- Ajuste aplicado: removida a criação automática do diretório de saída do relatório de prontidão Mercado Livre.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de saída via `--output` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório do relatório não estiver disponível.
- Teste adicionado: `test_ml_readiness_report_avoids_mkdir_for_output_path`
- Validações executadas:
  - `python -m py_compile scripts/ml-readiness-report.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `72 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio do diretório informado em `--output`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/shopee-readiness-report.py` ou `scripts/validate_20_products.py`, ambos ainda candidatos a criação automática de diretórios de saída.
