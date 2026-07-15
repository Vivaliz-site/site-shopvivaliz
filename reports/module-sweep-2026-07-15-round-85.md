## Round 85 - scripts/upload_images.py

- Módulo tratado: `scripts/upload_images.py`
- Escopo da rodada: hardening focado na escrita do CSV final de mapeamento de URLs enviadas.
- Ajuste aplicado:
  - removida a criação automática do diretório pai de `storage/uploaded_urls.csv`;
  - adicionado `output_dir_ready(path: Path) -> bool`.
- Hardening aplicado:
  - a gravação do arquivo de mapeamento agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de saída não estiver disponível.
- Teste adicionado: `test_upload_images_hardens_output_mapping_dir`
- Validações executadas:
  - `python -m py_compile scripts/upload_images.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `92 passed`
- Riscos identificados:
  - a rodada endureceu a saída local do CSV final; o restante do fluxo FTP permaneceu inalterado por escolha de escopo.
- Próximo módulo seguro recomendado:
  - um próximo candidato inédito com perfil semelhante é `scripts/generate_ai_images.py` já tratado; então o melhor próximo alvo fora desse bloco pode ser `scripts/process_images.py`.
