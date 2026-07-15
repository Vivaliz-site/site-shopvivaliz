## Round 87 - scripts/publish-to-marketplace.py

- Módulo tratado: `scripts/publish-to-marketplace.py`
- Escopo da rodada: hardening focado na escrita do relatório final de publicação.
- Ajuste aplicado:
  - removida a criação automática do diretório pai do arquivo de saída;
  - adicionado `output_dir_ready(path: Path) -> bool`.
- Hardening aplicado:
  - a gravação do JSON final agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório do relatório não estiver disponível.
- Teste adicionado: `test_publish_to_marketplace_hardens_output_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/publish-to-marketplace.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `94 passed`
- Riscos identificados:
  - a rodada endureceu apenas a saída local do relatório; o restante do fluxo de publicação permaneceu inalterado por escolha de escopo.
- Próximo módulo seguro recomendado:
  - um próximo candidato inédito com perfil semelhante é `scripts/quick-deploy-ecommerce.py`.
