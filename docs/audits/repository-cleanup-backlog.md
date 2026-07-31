# Backlog de reorganização do repositório

Atualizado em: 2026-07-31

## Estado preservado

- PR amplo `#599`: fechado sem merge.
- Preservação histórica: `archive/pr-599-reorg-2026-07-30`.
- Motivo: mais de 1.500 arquivos e centenas de stubs/wrappers impediam revisão segura.

## Fases concluídas

### Fase 1 — Governança

- [x] PR `#600` integrado;
- [x] gate fail-closed;
- [x] auditoria horária no minuto 17;
- [x] testes e artifacts obrigatórios.

### Fase 2 — Workflows

- [x] PRs `#602` e `#609` integrados;
- [x] auditor global de workflows ativos;
- [x] remoção de escrita automática, sucesso forçado e artifacts opcionais;
- [x] Shopee produção somente manual;
- [x] migrador automático removido.

### Fase 3 — Agentes e filas

- [x] PR `#605` integrado;
- [x] estados canônicos separados;
- [x] `completed_verified` exige run, artifact, commit SHA e verificação;
- [x] fila não avança sem evidência;
- [x] heartbeat exige run recente e artifact.

### Fase 4 — Domínios críticos

- [x] PR `#611`: Olist canônico e fail-closed;
- [x] PR `#612`: executores IA aposentados em `scripts/ai/`;
- [x] health canônico em `scripts/maintenance/`;
- [x] utilitários Shopee com credenciais substituídos por wrappers bloqueados;
- [x] testes executam wrappers e exigem código 2, estado `blocked` e nenhuma operação externa.

### Fase 5 — Documentação

- [x] índice, política e relatório final atualizados;
- [x] documentos históricos tratados como inventário, não prova de execução;
- [x] nenhuma migração em massa ou criação de stubs permanentes.

A movimentação física de documentos históricos permanece trabalho não bloqueante e deve ocorrer apenas em PRs pequenos com consumidores identificados.

### Fase 6 — Secrets e histórico

Árvore atual:

- [x] valores Olist, Mercado Pago, Shopee, TikTok, Tiny e Melhor Envio removidos dos arquivos rastreados;
- [x] arquivo `storage/private/melhorenvio-tokens.json` removido;
- [x] qualquer arquivo rastreado em `storage/private/` passa a ser bloqueante;
- [x] query strings com token removidas;
- [x] scripts que continham credenciais aposentados;
- [x] exemplos token-shaped substituídos por placeholders explícitos;
- [x] scanner cobre formatos conhecidos, JWTs, literais sensíveis e blocos PEM completos;
- [x] scanner registra somente arquivo, linha e classe do padrão.

Ações externas ainda obrigatórias e não verificáveis pelo repositório:

- [ ] revogar e rotacionar credenciais Olist;
- [ ] revogar e rotacionar secrets Mercado Pago;
- [ ] revogar e rotacionar parceiro, sandbox e tokens Shopee;
- [ ] revogar e rotacionar aplicação TikTok;
- [ ] revogar e rotacionar token de webhook Tiny;
- [ ] revogar e rotacionar access token e refresh token Melhor Envio;
- [ ] armazenar substitutos somente em stores protegidos;
- [ ] validar integrações por execução real e read-back;
- [ ] planejar limpeza coordenada do histórico depois das revogações;
- [ ] comunicar colaboradores antes de reescrever histórico.

A remoção da árvore atual não invalida valores nem apaga commits antigos.

## Critério final

A reorganização operacional só termina quando:

- `finalize_reorganization.py` retorna zero;
- relatório final tem `status: success` e zero achados bloqueantes;
- Repository Governance, Agents Hourly Deep Audit, ShopVivaliz QA e Quality Gate estão verdes;
- artifacts obrigatórios correspondem ao SHA revisado;
- não há PR antigo de reorganização aberto;
- nenhum workflow faz commit, push ou merge da reorganização automaticamente.

## Próximos trabalhos não bloqueantes

- mover documentos históricos por assunto;
- remover wrappers após confirmar ausência de consumidores;
- organizar ferramentas auxiliares em PRs independentes;
- concluir as rotações externas e eventual limpeza de histórico.
