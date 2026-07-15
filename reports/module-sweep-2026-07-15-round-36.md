## Module Sweep 2026-07-15 - Round 36

- Modulo auditado: `admin/force-git-pull.php`
- Ajuste aplicado: remoção da criação implícita de diretório no logger do endpoint administrativo.
- Endurecimento adicional:
  - nova rotina `force_pull_log()`
  - o log só é gravado quando a pasta já existe e está gravável
  - append passou a usar `LOCK_EX`
- Teste adicionado: `test_admin_force_git_pull_avoids_mkdir_for_log_file`

### Arquivos alterados

- `admin/force-git-pull.php`
- `tests/test_production_hardening.py`

### Validações executadas

- `php -l admin/force-git-pull.php`
- `pytest tests/test_production_hardening.py -q`

### Resultado

- Lint PHP ok
- Suite de hardening ok (`43 passed`)

### Riscos identificados

- O startup do PHP local ainda alerta sobre extensão `curl` ausente.
- O endpoint continua sensível por executar comandos de Git; nesta rodada o endurecimento ficou restrito ao subfluxo de log.
- Próximos alvos podem voltar para integrações ou rotas de suporte com menor raio operacional.

### Próxima tarefa recomendada

- Auditar `api/ml/products.php`
- Motivo: é um módulo curto, distinto do admin, e ainda apresenta criação implícita de diretório em persistência local.
