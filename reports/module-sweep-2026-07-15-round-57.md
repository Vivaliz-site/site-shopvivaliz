## Round 57 - scripts/deploy-validator.py

- Módulo tratado: `scripts/deploy-validator.py`
- Ajuste aplicado: removida a criação automática do diretório pai do relatório de validação de deploy.
- Hardening aplicado:
  - adicionado `report_dir_ready(path: Path) -> bool`;
  - a escrita do relatório agora exige diretório existente e gravável;
  - falha explícita com `FileNotFoundError` quando o diretório do relatório não estiver disponível.
- Teste adicionado: `test_deploy_validator_avoids_mkdir_for_report_dir`
- Validações executadas:
  - `python -m py_compile scripts/deploy-validator.py`
  - `pytest tests/test_production_hardening.py -q`
- Resultado:
  - `64 passed`
- Riscos identificados:
  - o comando passa a depender de provisionamento prévio da pasta de relatório, o que é consistente com o padrão endurecido adotado no ciclo.
- Próximo módulo seguro recomendado:
  - `scripts/deploy-diagnostic.py` ou `scripts/stock-alerts-audit.py`, ambos ainda candidatos a criação automática de diretórios de relatório.
