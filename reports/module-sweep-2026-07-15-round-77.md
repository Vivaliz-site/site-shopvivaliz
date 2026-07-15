## Round 77 - scripts/auto-oauth-login.py

- Módulo tratado: `scripts/auto-oauth-login.py`
- Ajuste aplicado: removida a criação automática dos diretórios de log e tokens no fluxo de OAuth automático do Olist.
- Hardening aplicado:
  - adicionado `dir_ready(path: Path) -> bool`;
  - a gravação de `logs/auto-oauth-login.log` agora exige diretório existente e gravável;
  - o screenshot de erro também valida explicitamente a disponibilidade do diretório;
  - a preparação de `.tokens/olist-config.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de log ou tokens não estiverem disponíveis.
- Teste adicionado: `test_auto_oauth_login_avoids_mkdir_for_log_and_tokens_dir`
- Validações executadas:
  - `python -m py_compile scripts/auto-oauth-login.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `84 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/` e `.tokens/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/olist-chrome.py` ou `scripts/olist-manual-sync.py` caso exista variante semelhante ainda não endurecida.
