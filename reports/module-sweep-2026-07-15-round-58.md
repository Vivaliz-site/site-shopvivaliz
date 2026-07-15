## Round 58 - scripts/deploy-diagnostic.py

- Módulo tratado: `scripts/deploy-diagnostic.py`
- Ajuste aplicado: removida a criação automática de `logs/` durante a geração do relatório de diagnóstico de deploy.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/deploy-diagnostic.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_deploy_diagnostic_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/deploy-diagnostic.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `65 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio do diretório `logs/`, consistente com o padrão endurecido do ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/stock-alerts-audit.py` ou `scripts/product-page-indexability-audit.py`, ambos fortes candidatos a criação automática de diretórios de relatório.
