## Round 75 - scripts/olist-oauth-login.py

- Módulo tratado: `scripts/olist-oauth-login.py`
- Ajuste aplicado: removida a criação automática do diretório de resultado do fluxo OAuth Olist.
- Hardening aplicado:
  - adicionado `result_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/olist-oauth-resultado.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de resultado não estiver disponível.
- Teste adicionado: `test_olist_oauth_login_avoids_mkdir_for_result_dir`
- Validações executadas:
  - `python -m py_compile scripts/olist-oauth-login.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `82 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/olist-chrome.py` ou `scripts/olist-direct-login.py` já foi tratado; então o próximo distinto melhor é `scripts/olist-sync-chrome.py` ou `scripts/auto-oauth-login.py`.
