# Backlog de Limpeza do Repositorio

Este backlog controla limpeza estrutural sem delecoes arriscadas.

## Status permitidos

- `manter`: arquivo/rotina valido e documentado.
- `migrar`: precisa mover, renomear ou padronizar.
- `renomear`: nome atual confunde ou duplica conceito.
- `arquivar`: legado deve ir para `archive/` com justificativa.
- `remover depois de validacao`: pode ser removido apos confirmar ausencia de uso.
- `bloqueado`: depende de acesso, credencial, decisao externa ou teste.

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
| CLEAN-004 | P1 | Scripts | Scripts de producao espalhados | migrar | Mover gradualmente para `scripts/production/` ou documentar legado | Imports/workflows atualizados e testes OK |
| CLEAN-005 | P1 | Shopee | Fluxos de SEO e triggers temporarios | manter | Manter apenas com issue/evidencia; remover triggers temporarios depois da validacao | Run com primeiro produto validado ou decisao de rollback |
| CLEAN-006 | P1 | Config | `config/secrets.py` com aliases legados | manter | Centralizar aliases e proibir novos usos fora do centralizador | `python -m py_compile config/secrets.py` e audit sem erros bloqueantes |
| CLEAN-007 | P2 | Docs | Documentos dispersos sem indice | migrar | Ligar documentos principais no `docs/knowledge/README.md` | Links principais revisados |
| CLEAN-008 | P2 | Logs/backups | Logs e backups versionados indevidos | bloqueado | Listar arquivos grandes/sensiveis e mover para storage privado ou artifacts | Confirmar que nao sao necessarios em producao |
| CLEAN-009 | P2 | Archive | Codigo experimental misturado | migrar | Mover para `archive/` ou `scripts/dev/` | Nenhum workflow/import depende do caminho antigo |
| CLEAN-010 | P2 | Testes | Testes sem separacao unit/integration/smoke | migrar | Criar estrutura alvo e mover gradualmente | CI executando caminho correto |

## Protocolo de limpeza

1. Nao apagar em massa.
2. Para cada item, localizar uso em codigo, workflows, docs e deploy.
3. Preferir migracao com alias/wrapper temporario.
4. Registrar conclusao com commit, PR e evidencia.
5. Atualizar `repository-index.md`, `routines-registry.md` e `secrets-and-integrations-map.md` quando aplicavel.

## Historico

- 2026-07-30: backlog criado durante auditoria estrutural inicial do repositorio.
