## Round 63 - scripts/deploy_production.py

- Módulo tratado: `scripts/deploy_production.py`
- Ajuste aplicado: removida a criação automática do diretório do relatório de deploy em produção.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/production_deployment_report.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_deploy_production_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/deploy_production.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `70 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/verify_marketplace_upload.py` ou `scripts/validate_20_products.py`, ambos com potencial de ainda criarem diretórios de saída automaticamente.
