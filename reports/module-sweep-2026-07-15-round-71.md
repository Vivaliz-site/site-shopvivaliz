## Round 71 - scripts/olist-direct-login.py

- Módulo tratado: `scripts/olist-direct-login.py`
- Ajuste aplicado: removida a criação automática dos diretórios de log e de tokens no fluxo de login direto do Olist.
- Hardening aplicado:
  - adicionado `dir_ready(path: Path) -> bool`;
  - a gravação de `logs/olist-direct-login.log` agora exige diretório existente e gravável;
  - a gravação de `.tokens/olist-config.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de log ou tokens não estiverem disponíveis.
- Teste adicionado: `test_olist_direct_login_avoids_mkdir_for_log_and_tokens_dir`
- Validações executadas:
  - `python -m py_compile scripts/olist-direct-login.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `78 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/` e `.tokens/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/validate_20_products.py` ou `scripts/olist-selenium-login.py`, ambos ainda candidatos a criação automática de diretórios de saída/log.
