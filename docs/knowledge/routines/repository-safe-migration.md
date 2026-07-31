# Rotina: Repository Safe Migration

| Campo | Valor |
|---|---|
| Arquivo principal | `.github/workflows/repository-safe-migration.yml` |
| Executor | `scripts/maintenance/apply_migration_plan.py` |
| Dono funcional | Governança de repositório |
| Gatilho | `workflow_dispatch` manual |
| Entrada | Branch não protegida e plano JSON explícito |
| Saída | Arquivos movidos, wrappers/stubs, manifesto atualizado, commit e artifact |
| Risco | Médio; altera caminhos versionados, mas não produção diretamente |
| Validação | Preflight, testes unitários, validação pós-aplicação, compilação e PR |
| Rollback | Reverter o commit gerado na branch |

## Regras

- não executar na `main`;
- não mover workflows ativos;
- não aceitar colisões ou traversal;
- não migrar documento com provável credencial sem sanitização manual;
- manter os lotes pequenos e relacionados;
- merge somente após os gates do PR.

Toda alteração desta rotina deve atualizar este documento e `docs/operations/repository-safe-migrations.md`.
