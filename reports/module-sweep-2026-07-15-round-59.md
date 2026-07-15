## Round 59 - scripts/stock-alerts-audit.py

- Módulo tratado: `scripts/stock-alerts-audit.py`
- Ajuste aplicado: removida a criação automática do diretório de relatórios durante a geração dos artefatos JSON e Markdown.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `stock-alerts-audit.json` e `stock-alerts-audit.md` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_stock_alerts_audit_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/stock-alerts-audit.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `66 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, alinhado ao padrão endurecido adotado no ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/product-page-indexability-audit.py` ou `scripts/seo-automation-audit.py`, ambos com perfil semelhante de geração de relatórios locais.
