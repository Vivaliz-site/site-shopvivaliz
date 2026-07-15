## Round 80 - scripts/export-olist-images-csv.py

- Módulo tratado: `scripts/export-olist-images-csv.py`
- Ajuste aplicado: removida a criação automática dos diretórios de saída para CSV e JSON do exportador de imagens Olist.
- Hardening aplicado:
  - adicionado `output_dir_ready(path: Path) -> bool`;
  - a gravação de `OUT_CSV` agora exige diretório pai existente e gravável;
  - a gravação de `OUT_JSON` agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de saída não estiverem disponíveis.
- Teste adicionado: `test_export_olist_images_csv_avoids_mkdir_for_output_dirs`
- Validações executadas:
  - `python -m py_compile scripts/export-olist-images-csv.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `87 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio dos diretórios de saída, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/sync-olist-images.py` ou `scripts/repair-olist-images.py`, ambos ainda inéditos neste ciclo e potenciais candidatos a escrita local automática.
