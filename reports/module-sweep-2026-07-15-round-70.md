## Round 70 - scripts/olist-headless-login.py

- Módulo tratado: `scripts/olist-headless-login.py`
- Ajuste aplicado: removida a criação automática do diretório de logs no fluxo de login headless do Olist.
- Hardening aplicado:
  - adicionado `log_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/olist-headless-login.log` agora exige diretório existente e gravável;
  - o screenshot de erro também valida explicitamente a disponibilidade do diretório;
  - falha explícita com `FileNotFoundError` quando o diretório de log não estiver disponível.
- Teste adicionado: `test_olist_headless_login_avoids_mkdir_for_log_dir`
- Validações executadas:
  - `python -m py_compile scripts/olist-headless-login.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `77 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/olist-direct-login.py` ou `scripts/validate_20_products.py`, ambos ainda candidatos a criação automática de diretórios de saída/log.
