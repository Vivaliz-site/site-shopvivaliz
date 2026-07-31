# Backlog de reorganização do repositório

Atualizado em: 2026-07-30

## Estado preservado

- PR histórico amplo: `#599`.
- Branch de preservação: `archive/pr-599-reorg-2026-07-30`.
- Motivo da substituição: 1.499 arquivos, 143 commits e centenas de stubs/wrappers impediam revisão segura.

## Fases

### Fase 1 — Governança e auditoria

Status: em execução.

- [x] criar branch limpa a partir de `main`;
- [x] adicionar gate fail-closed para mudanças novas;
- [x] restaurar auditoria horária de agentes;
- [x] adicionar testes unitários do detector;
- [x] exigir artifacts;
- [ ] integrar após checks verdes.

### Fase 2 — Workflows ativos

Status: pendente após Fase 1.

- inventariar workflows ativos e arquivados;
- remover placeholders e workflows que apenas imprimem estado;
- bloquear `continue-on-error`, `exit 0` e artifacts opcionais em auditorias;
- remover auto-merge e pushes automáticos amplos;
- validar permissions mínimas.

### Fase 3 — Agentes e filas

Status: pendente após Fase 2.

- colocar simuladores em fail-closed;
- separar `blocked`, `failed` e `completed_verified`;
- exigir commit, PR, testes e artifact para conclusão;
- corrigir leitores de fila para o schema canônico;
- impedir alteração de fila sem execução real.

### Fase 4 — Scripts por domínio

Status: pendente após Fase 3.

- mover um domínio por PR: IA, manutenção, Shopee, Olist, produção e desenvolvimento;
- manter wrapper somente para consumidor comprovado;
- adicionar prazo de remoção para cada wrapper;
- testar imports, workflows e comandos documentados.

### Fase 5 — Documentação e arquivos históricos

Status: pendente após Fase 4.

- mover documentos atuais para `docs/knowledge` ou `docs/operations`;
- mover relatórios antigos para `docs/audits` ou `archive`;
- não substituir centenas de arquivos por stubs permanentes;
- remover arquivos com nomes inválidos ou artefatos temporários após comprovação.

### Fase 6 — Secrets e histórico

Status: ação de segurança separada.

- rotacionar credenciais já expostas;
- sanitizar a árvore atual;
- planejar limpeza de histórico coordenada;
- invalidar tokens antigos antes de reescrever histórico.

## Gate de avanço

Uma fase só avança com PR pequeno, checks verdes, artifact obrigatório e diff revisável. Nenhum workflow de reorganização pode fazer commit, push ou merge por conta própria.