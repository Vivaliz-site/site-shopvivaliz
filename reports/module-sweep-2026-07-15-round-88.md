## Round 88 - scripts/quick-deploy-ecommerce.py

- Módulo tratado: `scripts/quick-deploy-ecommerce.py`
- Escopo da rodada: hardening focado na escrita local das páginas geradas pelo scaffold rápido.
- Ajuste aplicado:
  - removida a criação automática dos diretórios das páginas de saída;
  - adicionado `output_dir_ready(path: Path) -> bool`.
- Hardening aplicado:
  - a gravação dos arquivos como `catalogo/index.php`, `produto.php` e `carrinho/index.php` agora exige diretório pai existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório de uma página não estiver disponível.
- Teste adicionado: `test_quick_deploy_ecommerce_hardens_page_output_dirs`
- Validações executadas:
  - `python -m py_compile scripts/quick-deploy-ecommerce.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `95 passed`
- Riscos identificados:
  - a rodada endureceu a escrita do scaffold local; o conteúdo funcional das páginas geradas permaneceu inalterado por escolha de escopo.
- Próximo módulo seguro recomendado:
  - um próximo candidato inédito com perfil semelhante é `scripts/quick-deploy-ecommerce.py` da pasta `automations/` ou `scripts/stock-alerts-audit.py` já tratado; melhor próximo distinto agora seria `scripts/seo_generator.py`.
