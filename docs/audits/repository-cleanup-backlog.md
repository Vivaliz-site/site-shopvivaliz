# Backlog de Limpeza do Repositorio

Este backlog controla limpeza estrutural sem delecoes arriscadas.

## Status permitidos

- `manter`: arquivo/rotina valido e documentado.
- `migrar`: precisa mover, renomear ou padronizar.
- `renomear`: nome atual confunde ou duplica conceito.
- `arquivar`: legado deve ir para `archive/` com justificativa.
- `remover depois de validacao`: pode ser removido apos confirmar ausencia de uso.
- `bloqueado`: depende de acesso, credencial, decisao externa ou teste.
- `concluido-com-wrapper`: implementacao movida e caminho antigo mantido temporariamente.
- `mapeado-globalmente`: item coberto por scanner/indice global, aguardando lote fisico seguro.

## Prioridades

- P0: risco de seguranca, token, producao ou deploy.
- P1: duplicidade que causa erro operacional.
- P2: organizacao/documentacao.
- P3: melhoria futura.

## Itens iniciais

| ID | Prioridade | Area | Item | Status | Acao proposta | Validacao antes de concluir |
|---|---|---|---|---|---|---|
| CLEAN-001 | P0 | Secrets | Aliases Olist/Tiny duplicados | migrar | Usar `OLIST_*` como canonico para Olist e `TINY_*` apenas para Tiny nativo | Buscar usos de `TOKEN_API_OLIST`, `CLIENT_ID_API_OLIST`, `CLIENT_SECRET_OLIST`, `URL_REDIRCT_OLIST`, `URL_TINY_OLIST` |
| CLEAN-002 | P0 | Secrets | Access tokens hardcoded ou `.env` real | bloqueado | Criar CI de higiene e varrer repository tree | Workflow `repo-hygiene.yml` verde; nenhuma chave real encontrada |
| CLEAN-003 | P1 | Workflows | Workflows sem registro operacional | migrar | Registrar todos em `routines-registry.md` | Todos os arquivos em `.github/workflows/` possuem linha no registro ou justificativa |
| CLEAN-004 | P1 | Scripts | Scripts de producao espalhados | concluido-com-wrapper | Shopee SEO e otimizador movidos para `scripts/marketplace/shopee/`; wrappers legados mantidos | Workflow e testes apontam para caminho canonico; CI compila caminho novo e wrappers |
| CLEAN-005 | P1 | Shopee | Fluxos de SEO e triggers temporarios | manter | Manter apenas com issue/evidencia; remover triggers temporarios depois da validacao | Run com primeiro produto validado ou decisao de rollback |
| CLEAN-006 | P1 | Config | `config/secrets.py` com aliases legados | manter | Centralizar aliases e proibir novos usos fora do centralizador | `python -m py_compile config/secrets.py` e audit sem erros bloqueantes |
| CLEAN-007 | P2 | Docs | Documentos dispersos sem indice | mapeado-globalmente | `docs/operations/legacy-root-docs-index.md` criado; scanner global identifica candidatos | `restructure_repository.py --write-report` gera relatorio de 100% da arvore |
| CLEAN-008 | P2 | Logs/backups | Logs e backups versionados indevidos | mapeado-globalmente | Scanner global classifica artifacts temporarios e relatorios | Relatorio em `docs/audits/repository-wide-structure-report.md/json` |
| CLEAN-009 | P2 | Archive | Codigo experimental misturado | mapeado-globalmente | Scanner global classifica candidatos a `archive/` ou `scripts/dev/` | Nao mover sem confirmar imports/workflows |
| CLEAN-010 | P2 | Testes | Testes sem separacao unit/integration/smoke | migrar | Criar estrutura alvo e mover gradualmente | CI executando caminho correto |
| CLEAN-011 | P2 | Shopee | Remover wrappers legados Shopee | remover depois de validacao | Apos validar que workflows, docs, scripts locais e CI usam apenas caminho canonico, remover wrappers | Busca sem usos dos caminhos legados e CI verde |
| CLEAN-012 | P1 | Repo inteiro | Scanner estrutural global | manter | `scripts/maintenance/restructure_repository.py` varre 100% do checkout e gera relatorio | CI executa scanner no workflow `Repository Hygiene` |

## Migrações executadas neste PR

| Data | ID | Antes | Depois | Wrapper | Evidencia |
|---|---|---|---|---|---|
| 2026-07-30 | CLEAN-004 | `scripts/shopee_production_seo_apply.py` | `scripts/marketplace/shopee/production_seo_apply.py` | Sim | `.github/workflows/shopee-production-seo.yml` atualizado para caminho novo |
| 2026-07-30 | CLEAN-004 | `scripts/shopee_full_catalog_optimizer.py` | `scripts/marketplace/shopee/full_catalog_optimizer.py` | Sim | `repo-hygiene.yml` compila caminho novo e wrapper |
| 2026-07-30 | CLEAN-007/CLEAN-012 | documentos operacionais soltos na raiz | `docs/operations/legacy-root-docs-index.md` | N/A | Indice global criado com destinos alvo por documento |
| 2026-07-30 | CLEAN-012 | auditoria manual parcial | `scripts/maintenance/restructure_repository.py` | N/A | Scanner global criado para varrer 100% do checkout no CI |

## Protocolo de limpeza

1. Nao apagar em massa.
2. Para cada item, localizar uso em codigo, workflows, docs e deploy.
3. Preferir migracao com alias/wrapper temporario.
4. Registrar conclusao com commit, PR e evidencia.
5. Atualizar `repository-index.md`, `routines-registry.md` e `secrets-and-integrations-map.md` quando aplicavel.

## Historico

- 2026-07-30: backlog criado durante auditoria estrutural inicial do repositorio.
- 2026-07-30: primeira migracao fisica aplicada para scripts Shopee, mantendo wrappers legados.
- 2026-07-30: scanner global e indice de documentos legados da raiz adicionados para cobrir o repositorio inteiro.
