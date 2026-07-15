## Round 83 - scripts/import_shopee.py

- Módulo tratado: `scripts/import_shopee.py`
- Ajuste aplicado: endurecidos os diretórios de saída usados para download por SKU, mapeamento CSV e planilha Excel final.
- Hardening aplicado:
  - adicionados `dir_ready(path: Path) -> bool` e `parent_dir_ready(path: Path) -> bool`;
  - o diretório de cada SKU agora precisa existir e ser gravável antes do download;
  - a gravação de `storage/sku_mapping.csv` agora exige diretório pai existente e gravável;
  - a gravação de `planilhas/produtos.xlsx` agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando os diretórios de saída necessários não estiverem disponíveis.
- Teste adicionado: `test_import_shopee_hardens_output_dirs`
- Validações executadas:
  - `python -m py_compile scripts/import_shopee.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `90 passed`
- Riscos identificados:
  - o fluxo de importação continua dependendo de diretórios provisionados previamente, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/repair-olist-images.py` já foi tratado; um próximo inédito com perfil semelhante seria `scripts/generate_ai_images.py` ou `scripts/upload_images.py`, se você quiser expandir o sweep para outro bloco funcional.
