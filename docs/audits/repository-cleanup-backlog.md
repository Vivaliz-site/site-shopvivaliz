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

## Auditoria de inventário — 2026-08-02

Evidência reproduzível: `python scripts/generate_repository_index.py`, com saída em
`docs/knowledge/repository-file-index.md` e `docs/audits/repository-hygiene.md`.

- 3.824 arquivos versionados e 782 diretórios foram catalogados.
- 192 grupos possuem conteúdo idêntico no índice Git. A maior parte são assets
  processados; arquivos com conteúdo idêntico não devem ser removidos somente
  por esse critério.
- Foram encontrados 12 grupos que incluem código. Parte deles são arquivos vazios de
  marcador (`.gitkeep` ou `__init__.py`); manifests dentro de `dist/` são cópias
  de build esperadas. Os pares de endpoints e scripts restantes exigem análise
  de rota, telemetria e compatibilidade antes de uma consolidação.
- Os caminhos de checkout e carrinho declaradamente legados não possuem
  referência de código versionado fora de documentos de auditoria. Mesmo assim,
  a remoção fica bloqueada até haver confirmação de tráfego e de rotas públicas.
- Os wrappers Shopee repetidos são mantidos intencionalmente pelo contrato de
  `scripts/maintenance/finalize_reorganization.py` e pelo workflow de
  governança; não devem ser apagados isoladamente.
- O relatório agora reproduz a varredura sintática de nomes de função declarados
  em mais de um arquivo. Repetição de nome não prova duplicação semântica porque
  métodos de classes e scripts de página podem ter escopos independentes.
- Dados operacionais proibidos pelas regras do repositório são sinalizados como
  violações e seus identificadores variáveis são mascarados no índice.
- Pares de endpoints, helpers e scripts idênticos não foram consolidados sem
  verificação de rota, telemetria e contrato de compatibilidade.

Decisão aplicada: nenhum arquivo de produção, endpoint, build, log ou dado de
catálogo foi movido ou removido nesta auditoria. Próximas limpezas devem ser
PRs pequenos, cada um com consumidor identificado e verificação de rota/CI.

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
