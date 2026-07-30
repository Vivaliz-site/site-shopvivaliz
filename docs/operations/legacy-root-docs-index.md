# Índice de Documentos Legados Soltos na Raiz

Este documento consolida a sujeira estrutural encontrada por busca no repositório: documentos operacionais, status, validações e relatórios que vivem na raiz e dificultam entendimento.

## Regra

Novos documentos operacionais não devem ser criados na raiz. Use:

- `docs/knowledge/` para memória operacional permanente;
- `docs/operations/` para runbooks e guias de operação;
- `docs/audits/` para auditorias, relatórios, validações e status históricos;
- `archive/<ano>/` para artefatos legados, temporários ou substituídos.

## Documentos legados identificados na raiz

| Arquivo | Classificação | Destino alvo | Status |
|---|---|---|---|
| `AGENTS-ACCESS-INDEX.md` | agentes/índice antigo | `docs/operations/agents/` | pendente |
| `AGENTS-STATUS-REPORT.md` | relatório/status | `docs/audits/legacy-reports/` | pendente |
| `AGENTES_STATUS_CRITICO.md` | relatório/status crítico | `docs/audits/legacy-reports/` | pendente |
| `INSTRUCOES-PARA-AGENTES.md` | instrução operacional | `docs/operations/agents/` ou `docs/knowledge/agent-rules.md` | pendente |
| `AUTONOMOUS_TRIO_GUIDE.md` | guia operacional | `docs/operations/agents/autonomous-trio-guide.md` | migrado-com-stub |
| `AUTOMACAO-AUTONOMA-24-7.md` | guia operacional | `docs/operations/agents/` | pendente |
| `SISTEMA-AUTONOMO-INDEX.md` | índice antigo | `docs/operations/agents/` | pendente |
| `SISTEMA-AUTONOMO-COMPLETO.md` | guia/status antigo | `docs/operations/agents/` ou `docs/audits/legacy-reports/` | pendente |
| `OPERATIONS-24-7.md` | operação | `docs/operations/` | pendente |
| `HOURLY-SUMMARY.md` | relatório periódico | `docs/audits/legacy-reports/` | pendente |
| `SYSTEM-HEALTH-REPORT.txt` | relatório de saúde | `docs/audits/legacy-reports/` | pendente |
| `CONCLUSAO_DIAGNOSTICO.md` | conclusão diagnóstico | `docs/audits/legacy-reports/` | pendente |
| `VALIDACAO_FINAL.md` | validação | `docs/audits/legacy-reports/` | pendente |
| `AUDITORIA-PRECOS-FINAL.md` | auditoria | `docs/audits/legacy-reports/` | pendente |
| `AUDITORIA-FASE-A-PROPOSTA.md` | auditoria/proposta | `docs/audits/legacy-reports/` | pendente |
| `PR_RESOLUTION_PLAN.md` | plano PR | `docs/audits/legacy-reports/` | pendente |
| `PR_RESOLUTION_REPORT.md` | relatório PR | `docs/audits/legacy-reports/` | pendente |
| `SETUP_CHECKLIST.md` | checklist operacional | `docs/operations/` | pendente |
| `CHECKLIST-RAPIDO-SECRETS-FTP.md` | checklist secrets/FTP | `docs/operations/deploy/ftp-secrets-checklist.md` | migrado-com-stub |
| `GITHUB_ACTIONS_FIXES.md` | histórico Actions | `docs/audits/legacy-reports/` | pendente |
| `PLANO-TOKEN-RENEWAL-5-2h.md` | plano token | `docs/operations/shopee/` | pendente |
| `SINCRONIZACAO-OLIST-STATUS.md` | status Olist | `docs/operations/olist/sync-status.md` | migrado-com-stub |
| `URGENTE-OLIST-TOKEN-FIX.md` | correção Olist | `docs/operations/olist/` ou `docs/audits/legacy-reports/` | pendente |
| `RESUMO-FINAL-OLIST.md` | resumo Olist | `docs/audits/legacy-reports/` | pendente |
| `PIPELINE-SHOPEE-STATUS.txt` | status Shopee | `docs/operations/shopee/pipeline-status-legacy.md` | migrado-com-stub |
| `PRODUCAO-LIBERADA-2026-07-13.md` | status produção | `docs/audits/legacy-reports/` | pendente |
| `DOCUMENTO-CRITICO-2026-07-08-REPASSE.md` | documento crítico histórico | `docs/audits/legacy-reports/` | pendente |
| `IMPLEMENTACAO-8-MELHORIAS.md` | histórico de implementação | `docs/audits/legacy-reports/` | pendente |
| `KNOWN_ISSUES.md` | problemas conhecidos | `docs/operations/` ou backlog | pendente |

## Migrações executadas

| Data | Origem | Destino | Compatibilidade |
|---|---|---|---|
| 2026-07-30 | `AUTONOMOUS_TRIO_GUIDE.md` | `docs/operations/agents/autonomous-trio-guide.md` | stub na raiz |
| 2026-07-30 | `SINCRONIZACAO-OLIST-STATUS.md` | `docs/operations/olist/sync-status.md` | stub na raiz |
| 2026-07-30 | `CHECKLIST-RAPIDO-SECRETS-FTP.md` | `docs/operations/deploy/ftp-secrets-checklist.md` | stub na raiz |
| 2026-07-30 | `PIPELINE-SHOPEE-STATUS.txt` | `docs/operations/shopee/pipeline-status-legacy.md` | stub na raiz |

## Estado atual

A organização física começou. Documentos já migrados mantêm ponte na raiz para preservar links antigos; próximos lotes devem continuar usando o mesmo padrão.
