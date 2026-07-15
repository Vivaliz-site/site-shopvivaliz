## Round 76 - scripts/olist-sync-chrome.py

- Módulo tratado: `scripts/olist-sync-chrome.py`
- Ajuste aplicado: removida a criação automática do diretório de resultado do fluxo Chrome/Selenium do Olist.
- Hardening aplicado:
  - adicionado `result_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/olist-sync-resultado.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de resultado não estiver disponível.
- Correção adicional:
  - adicionado `import os`, necessário porque o script usa `os.getenv(...)` e não compilava corretamente sem esse import.
- Teste adicionado: `test_olist_sync_chrome_avoids_mkdir_for_result_dir`
- Validações executadas:
  - `python -m py_compile scripts/olist-sync-chrome.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `83 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/auto-oauth-login.py` ou `scripts/olist-chrome.py`, ambos ainda candidatos a criação automática de diretórios de saída/log.
