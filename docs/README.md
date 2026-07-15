# Índice de docs/

48+ arquivos aqui, gerados por diferentes agentes ao longo do tempo. Antes de criar um doc novo,
confira se algo já não cobre o mesmo tema — este índice existe para reduzir esse risco.

## ⚠️ Grupos com sobreposição conhecida (ainda não consolidados)

- **`ai-continuous-mode.md`, `ai-global-coordination.md`, `ai-phase-execution.md`,
  `ai-platform-architecture.md`, `ai-stakeholder-handoff.md`, `ai-execution-flow.md`,
  `ai-agents-map.md`** — todos descrevem, de ângulos diferentes, como o sistema de agentes
  autônomos deveria funcionar (arquitetura, fases, coordenação). Provavelmente redundantes entre
  si; nenhum foi confirmado como a versão "oficial". Consolidação real (ler os 7, unificar em um
  só) ainda não foi feita — ficou fora do escopo desta passada por risco de perder conteúdo sem
  revisão cuidadosa linha a linha. Se for mexer nesse tema, leia todos antes de confiar em um só.
- **`medusa-migracao-roadmap.md`, `medusa-eha-alinhamento.md`, `medusa-proximo-passo.md`** —
  documentam uma proposta de migração para MedusaJS como backend. Conforme decisão do usuário em
  sessão anterior, essa linha foi **deprioritizada** em favor da integração nativa com o ERP
  Tiny/Olist. Tratar como histórico, não como plano ativo, a menos que o usuário reabra o tema.
- **`olist-tiny-erp-api-knowledge.md`** (v1) foi removido nesta limpeza — conteúdo já superado por
  `olist-tiny-erp-api-knowledge-v2.md`, mesma data (2026-06-27), mesmo escopo.

## Docs de status pontual (histórico, não manter atualizados)

`status-marketplace-readiness-2026-07-05.md`, `status-stock-alerts-audit-2026-07-05.md`,
`status-task-033-stock-alerts-2026-07-05.md`, `status-task-038-gamification-2026-07-05.md`,
`status-task-040-graphql-2026-07-05.md`, `status-task-043-shopee-readiness-2026-07-05.md`,
`status-task-048-product-pages-2026-07-05.md` — snapshots datados de tarefas específicas. Não
refletem necessariamente o estado atual do código; confira o código/git log antes de confiar neles.

## Infraestrutura / operação (mais confiáveis, checar data)

- `oracle-dev-environment.md` — ambiente VM Oracle (produção real, ver CLAUDE.md)
- `github-actions-diagnostico.md`, `status-workflows-actions.md` — diagnóstico de workflows
- `GIT-AUTO-SYNC.md`, `AUTO_SYNC_GITHUB_PC_ORACLE.md`, `GIT_AUTONOMOUS_AGENT.md` — mecanismo de
  sync entre GitHub e a VM (ver também `CLAUDE.md`, mais atualizado)

## Para não repetir bugs já resolvidos

Ver **`CHANGELOG.md`** na raiz do repo, não aqui em `docs/`.
