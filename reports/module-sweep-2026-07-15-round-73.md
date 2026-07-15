## Round 73 - scripts/olist-selenium-login.py

- Módulo tratado: `scripts/olist-selenium-login.py`
- Ajuste aplicado: removida a criação automática do diretório de resultado do fluxo Selenium do Olist.
- Hardening aplicado:
  - adicionado `result_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/olist-selenium-resultado.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de resultado não estiver disponível.
- Teste adicionado: `test_olist_selenium_login_avoids_mkdir_for_result_dir`
- Validações executadas:
  - `python -m py_compile scripts/olist-selenium-login.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `80 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/olist-sync-manual.py` ou `scripts/olist-oauth-login.py`, ambos ainda candidatos a criação automática de diretórios de saída/log.
