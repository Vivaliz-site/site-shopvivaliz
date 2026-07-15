## Round 79 - scripts/download-olist-images-v2.py

- Módulo tratado: `scripts/download-olist-images-v2.py`
- Ajuste aplicado: removida a criação automática do diretório-base de saída, das subpastas por produto e dos diretórios implícitos para CSVs.
- Hardening aplicado:
  - adicionados `dir_ready(path: Path) -> bool` e `parent_dir_ready(path: Path) -> bool`;
  - o downloader agora exige que `output_dir` já exista e seja gravável;
  - o download por SKU agora exige subdiretório já provisionado;
  - a gravação de `olist_imagens_baixadas.csv` e `mapa_upload_shopvivaliz.csv` agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de saída, produto, auditoria ou mapeamento não estiverem disponíveis.
- Teste adicionado: `test_download_olist_images_v2_avoids_mkdir_for_output_dirs`
- Validações executadas:
  - `python -m py_compile scripts/download-olist-images-v2.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `86 passed`
- Riscos identificados:
  - o utilitário passa a depender de provisionamento prévio dos diretórios de saída, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/export-olist-images-csv.py` ou `scripts/sync-olist-images.py`, ambos ainda inéditos neste ciclo e com forte chance de escrita local automática.
