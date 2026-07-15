## Round 86 - scripts/process_images.py

- Módulo tratado: `scripts/process_images.py`
- Escopo da rodada: hardening focado no diretório de saída das imagens processadas.
- Ajuste aplicado:
  - removida a criação automática da pasta processada por SKU antes do `image.save(...)`;
  - adicionado `output_dir_ready(path: Path) -> bool`.
- Hardening aplicado:
  - a gravação da imagem processada agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório processado não estiver disponível.
- Teste adicionado: `test_process_images_hardens_processed_output_dir`
- Validações executadas:
  - `python -m py_compile scripts/process_images.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `93 passed`
- Riscos identificados:
  - o fluxo continua dependendo de provisionamento prévio de `storage/processed`, consistente com o padrão endurecido deste ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/generate_ai_images.py` já foi tratado; um próximo inédito com perfil semelhante pode ser `scripts/publish-to-marketplace.py`.
