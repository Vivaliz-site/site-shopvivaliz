# Backlog de reorganização do repositório

Atualizado em: 2026-07-31

## Estado preservado

- PR histórico amplo: `#599`, fechado sem merge.
- Branch de preservação: `archive/pr-599-reorg-2026-07-30`.
- Motivo da substituição: mais de 1.500 arquivos alterados e centenas de stubs e wrappers impediam revisão segura.

## Fases concluídas

### Fase 1 — Governança e auditoria

Status: concluída no PR `#600`.

- [x] branch limpa a partir de `main`;
- [x] gate fail-closed para mudanças novas;
- [x] auditoria horária de agentes restaurada;
- [x] testes unitários do detector;
- [x] artifacts obrigatórios;
- [x] merge somente após checks verdes.

### Fase 2 — Workflows ativos

Status: concluída nos PRs `#602` e `#609`.

- [x] auditor global de workflows ativos;
- [x] remoção de `continue-on-error`, `set +e`, `exit 0` forçado e artifacts opcionais dos fluxos críticos;
- [x] remoção de escrita automática em incidentes e catálogo;
- [x] Shopee produção somente manual;
- [x] remoção do workflow automático de migração e de pushes do reorganizador;
- [x] permissões mínimas nos gates.

### Fase 3 — Agentes e filas

Status: concluída no PR `#605`.

- [x] simuladores e executores aposentados em fail-closed;
- [x] estados `blocked`, `failed` e `completed_verified` separados;
- [x] conclusão exige `run_id`, artifact, commit SHA e verificação;
- [x] leitor da fila usa o schema canônico `tasks`;
- [x] fila não avança sem evidência verificável;
- [x] heartbeat exige run recente e artifact real.

### Fase 4 — Scripts críticos por domínio

Status: concluída para os caminhos executáveis críticos nos PRs `#611`, `#612` e no PR final.

- [x] Olist: entrypoints canônicos em `scripts/marketplace/olist/`;
- [x] login automatizado com senha, TLS desativado e exposição parcial de token removido;
- [x] IA aposentada: entrypoints canônicos em `scripts/ai/`;
- [x] manutenção: health check canônico em `scripts/maintenance/`;
- [x] wrappers legados sem regra de negócio;
- [x] testes executam os wrappers e exigem saída fail-closed.

Não foram movidas ferramentas sem validação apenas por estética. Scripts não críticos continuam como inventário para lotes futuros independentes, sem bloquear o contrato operacional concluído.

### Fase 5 — Documentação e arquivos históricos

Status: concluída no escopo seguro.

- [x] índice canônico atualizado;
- [x] política estrutural preservada;
- [x] relatório final criado;
- [x] documentos históricos da raiz inventariados pelo verificador final;
- [x] nenhuma substituição em massa por stubs permanentes;
- [x] nenhum arquivo histórico é tratado como prova de execução.

A movimentação física de documentos históricos da raiz permanece dívida não executável. Ela só deve ocorrer em PRs pequenos quando houver benefício comprovado e links consumidores identificados.

### Fase 6 — Secrets e histórico

Status da árvore atual: concluído e verificável por CI.

- [x] valor de token removido de `OLIST-WEBHOOK-CONFIG.md`;
- [x] scanner final cobre valores com formato de credencial, inclusive hexadecimal longo ligado a campo sensível;
- [x] configuração documenta apenas o nome canônico `OLIST_WEBHOOK_TOKEN`;
- [x] nenhum valor substituto foi versionado.

Ações externas separadas, ainda necessárias e não verificáveis pelo repositório:

- [ ] revogar e rotacionar o token Olist no provedor;
- [ ] armazenar o novo valor somente em secret protegido;
- [ ] planejar limpeza coordenada do histórico Git depois da revogação;
- [ ] comunicar colaboradores antes de reescrever histórico.

Essas ações permanecem abertas porque remover o valor da árvore atual não invalida a credencial nem apaga commits antigos.

## Critério final

A reorganização operacional é considerada concluída somente quando:

- o verificador `scripts/maintenance/finalize_reorganization.py` retorna zero;
- o relatório contém `status: success` e nenhum achado bloqueante;
- `Repository Governance`, `Agents Hourly Deep Audit`, `ShopVivaliz QA` e `Quality Gate` estão verdes;
- artifacts obrigatórios estão vinculados ao SHA do PR;
- não há PR antigo de reorganização aberto;
- nenhum workflow faz commit, push ou merge da reorganização por conta própria.

## Próximos trabalhos não bloqueantes

- mover documentos históricos por assunto, sem stubs em massa;
- remover wrappers após confirmar que não há consumidores;
- organizar ferramentas auxiliares não críticas em PRs independentes;
- concluir rotação externa e eventual limpeza de histórico conforme o plano de segurança.
