# Índice de Documentos Legados Soltos na Raiz

Este documento consolida a sujeira estrutural encontrada por busca no repositório: documentos operacionais, status, validações e relatórios que vivem na raiz e dificultam entendimento.

## Regra

Novos documentos operacionais não devem ser criados na raiz. Use:

- `docs/knowledge/` para memória operacional permanente;
- `docs/operations/` para runbooks e guias de operação;
- `docs/audits/` para auditorias, relatórios, validações e status históricos;
- `archive/<ano>/` para artefatos legados, temporários ou substituídos.

## Documentos legados identificados na raiz

| Arquivo | Classificação | Destino alvo | Ação segura |
|---|---|---|---|
| `AGENTS-ACCESS-INDEX.md` | agentes/índice antigo | `docs/operations/legacy-root-docs/` | manter stub ou atualizar links |
| `AGENTS-STATUS-REPORT.md` | relatório/status | `docs/audits/legacy-reports/` | arquivar como histórico |
| `AGENTES_STATUS_CRITICO.md` | relatório/status crítico | `docs/audits/legacy-reports/` | arquivar como histórico |
| `INSTRUCOES-PARA-AGENTES.md` | instrução operacional | `docs/operations/legacy-root-docs/` | fundir com `docs/knowledge/agent-rules.md` |
| `AUTONOMOUS_TRIO_GUIDE.md` | guia operacional | `docs/operations/legacy-root-docs/` | manter stub até links serem atualizados |
| `AUTOMACAO-AUTONOMA-24-7.md` | guia operacional | `docs/operations/legacy-root-docs/` | consolidar com registro de rotinas |
| `SISTEMA-AUTONOMO-INDEX.md` | índice antigo | `docs/operations/legacy-root-docs/` | substituir por `docs/knowledge/repository-index.md` |
| `SISTEMA-AUTONOMO-COMPLETO.md` | guia/status antigo | `docs/operations/legacy-root-docs/` | arquivar ou consolidar |
| `OPERATIONS-24-7.md` | operação | `docs/operations/` | revisar e consolidar |
| `HOURLY-SUMMARY.md` | relatório periódico | `docs/audits/legacy-reports/` | arquivar como histórico |
| `SYSTEM-HEALTH-REPORT.txt` | relatório de saúde | `docs/audits/legacy-reports/` | arquivar como histórico |
| `CONCLUSAO_DIAGNOSTICO.md` | conclusão diagnóstico | `docs/audits/legacy-reports/` | arquivar como histórico |
| `VALIDACAO_FINAL.md` | validação | `docs/audits/legacy-reports/` | arquivar como histórico |
| `AUDITORIA-PRECOS-FINAL.md` | auditoria | `docs/audits/legacy-reports/` | arquivar como histórico |
| `AUDITORIA-FASE-A-PROPOSTA.md` | auditoria/proposta | `docs/audits/legacy-reports/` | arquivar como histórico |
| `PR_RESOLUTION_PLAN.md` | plano PR | `docs/audits/legacy-reports/` | arquivar como histórico |
| `PR_RESOLUTION_REPORT.md` | relatório PR | `docs/audits/legacy-reports/` | arquivar como histórico |
| `SETUP_CHECKLIST.md` | checklist operacional | `docs/operations/legacy-root-docs/` | consolidar com runbooks |
| `CHECKLIST-RAPIDO-SECRETS-FTP.md` | checklist secrets/FTP | `docs/operations/legacy-root-docs/` | consolidar com mapa de secrets |
| `GITHUB_ACTIONS_FIXES.md` | histórico Actions | `docs/audits/legacy-reports/` | arquivar como histórico |
| `PLANO-TOKEN-RENEWAL-5-2h.md` | plano token | `docs/operations/legacy-root-docs/` | consolidar com mapa Shopee |
| `SINCRONIZACAO-OLIST-STATUS.md` | status Olist | `docs/audits/legacy-reports/` | arquivar como histórico |
| `URGENTE-OLIST-TOKEN-FIX.md` | correção Olist | `docs/audits/legacy-reports/` | arquivar como histórico |
| `RESUMO-FINAL-OLIST.md` | resumo Olist | `docs/audits/legacy-reports/` | arquivar como histórico |
| `PIPELINE-SHOPEE-STATUS.txt` | status Shopee | `docs/audits/legacy-reports/` | arquivar como histórico |
| `PRODUCAO-LIBERADA-2026-07-13.md` | status produção | `docs/audits/legacy-reports/` | arquivar como histórico |
| `DOCUMENTO-CRITICO-2026-07-08-REPASSE.md` | documento crítico histórico | `docs/audits/legacy-reports/` | arquivar como histórico |
| `IMPLEMENTACAO-8-MELHORIAS.md` | histórico de implementação | `docs/audits/legacy-reports/` | arquivar como histórico |
| `KNOWN_ISSUES.md` | problemas conhecidos | `docs/operations/legacy-root-docs/` | fundir com troubleshooting/backlog |

## Estado atual

Esta é a primeira consolidação global. A movimentação física desses documentos deve ser feita em lote próprio com stubs na raiz, porque há muitos links internos antigos apontando para esses nomes.
