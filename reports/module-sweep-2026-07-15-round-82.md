## Round 82 - scripts/repair-olist-images.py

- Módulo tratado: `scripts/repair-olist-images.py`
- Ajuste aplicado: endurecida a escrita do script SQL e da atualização do cache local.
- Hardening aplicado:
  - adicionado `parent_dir_ready(path: Path) -> bool`;
  - a gravação de `logs/repair-images.sql` agora exige diretório pai existente e gravável;
  - a regravação de `storage/cache/olist-products-cache.json` agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório SQL ou de cache não estiver disponível.
- Teste adicionado: `test_repair_olist_images_hardens_sql_and_cache_dirs`
- Validações executadas:
  - `python -m py_compile scripts/repair-olist-images.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `89 passed`
- Riscos identificados:
  - o módulo continua alterando o cache local por design; esta rodada endureceu apenas a segurança da escrita, não o comportamento funcional do reparo.
- Próximo módulo seguro recomendado:
  - um próximo candidato inédito fora desta trilha Olist seria `scripts/import_shopee.py` ou outro utilitário com escrita local automática ainda não endurecida.
