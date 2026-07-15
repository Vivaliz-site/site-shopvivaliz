## Round 78 - scripts/auto-complete-olist.py

- Módulo tratado: `scripts/auto-complete-olist.py`
- Ajuste aplicado: removida a criação automática dos diretórios de log e tokens no fluxo de autocomplete OAuth do Olist.
- Hardening aplicado:
  - adicionado `dir_ready(path: Path) -> bool`;
  - a gravação de `logs/auto-complete-olist.log` agora exige diretório existente e gravável;
  - a gravação de `.tokens/olist-config.json` agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de log ou tokens não estiverem disponíveis.
- Teste adicionado: `test_auto_complete_olist_avoids_mkdir_for_log_and_tokens_dir`
- Validações executadas:
  - `python -m py_compile scripts/auto-complete-olist.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `85 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio de `logs/` e `.tokens/`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/download-olist-images-v2.py` ou `scripts/export-olist-images-csv.py`, ambos ainda inéditos neste ciclo e potenciais candidatos a escrita local automática.
