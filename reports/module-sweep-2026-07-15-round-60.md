## Round 60 - scripts/product-page-indexability-audit.py

- Módulo tratado: `scripts/product-page-indexability-audit.py`
- Ajuste aplicado: removida a criação automática do diretório de relatórios durante a geração dos artefatos JSON e Markdown.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a gravação de `product-page-indexability-audit.json` e `product-page-indexability-audit.md` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de relatório não estiver disponível.
- Teste adicionado: `test_product_page_indexability_audit_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/product-page-indexability-audit.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `67 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido adotado neste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/seo-automation-audit.py` ou `scripts/run-autonomy-phases.py`, ambos com perfil semelhante de escrita de relatórios locais.
