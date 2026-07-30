# Registro de Rotinas Operacionais

Este documento e obrigatorio para agentes e desenvolvedores. Toda rotina nova criada no repositorio deve ser registrada aqui no mesmo PR/commit.

## Regra obrigatoria

Ao criar ou alterar uma rotina, script, workflow, cron, job, automacao, sincronizacao ou tarefa de IA, preencha uma linha nesta tabela e atualize o indice do repositorio quando necessario.

Nenhuma rotina operacional deve ficar solta, sem dono funcional, gatilho, entrada, saida e validacao.

## Campos padrao

| Campo | Obrigatorio | Descricao |
|---|---:|---|
| Nome | Sim | Nome curto da rotina |
| Arquivo principal | Sim | Caminho do script, workflow ou endpoint |
| Dono funcional | Sim | Area responsavel: catalogo, deploy, marketplace, IA, pedidos, email, etc. |
| Gatilho | Sim | Manual, push, schedule, webhook, CLI, cron, API |
| Entrada | Sim | Secrets, parametros, arquivos, payloads ou banco |
| Saida | Sim | Arquivos, logs, artefatos, comentarios, banco, API externa |
| Risco | Sim | baixo, medio, alto, producao |
| Validacao | Sim | Como provar que executou corretamente |
| Observacoes | Nao | Dependencias, rollback, limitacoes |

## Rotinas registradas

| Nome | Arquivo principal | Dono funcional | Gatilho | Entrada | Saida | Risco | Validacao | Observacoes |
|---|---|---|---|---|---|---|---|---|
| Shopee SEO Production Apply | `.github/workflows/shopee-production-seo.yml` + `scripts/marketplace/shopee/production_seo_apply.py` | Marketplace Shopee | Manual `workflow_dispatch` ou trigger controlado | `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`, `SHOPEE_REFRESH_TOKEN`, `SHOPEE_ACCESS_TOKEN` opcional, limite, confirmacao | Relatorio JSON, backup JSON, artefato GitHub Actions, comentario na issue de validacao | producao | Status `updated_verified` ou `verified_unchanged` com leitura posterior da Shopee | Nao altera preco/estoque; exige backup e read-back; caminho antigo e wrapper |
| Shopee Full Catalog Optimizer | `scripts/marketplace/shopee/full_catalog_optimizer.py` | Marketplace Shopee | CLI manual ou importado pelo executor de producao | Catálogo Shopee e secrets Shopee | Relatorio JSON e backup | medio/producao quando `--apply` | Backup, relatorio e invariantes de preco/estoque | Caminho antigo `scripts/shopee_full_catalog_optimizer.py` e wrapper |
| Shopee First Product Validation Trigger | `.github/triggers/shopee-first-product-validation.json` | Marketplace Shopee | Push controlado no arquivo trigger | JSON de solicitacao com `limit=1` | Run do workflow Shopee e comentario em issue | producao | Comentario automatico com `run_id`, `commit`, status e evidencia | Usado para validar primeiro produto antes de lote total |
| AI Autonomous Executor | `.github/workflows/ai-autonomous-executor.yml` + `scripts/autonomous-executor.py` | Agentes IA | Schedule horario ou manual | `tasks-queue.json`, secrets de IA | Commits, relatórios e execucoes em Actions | medio | Logs do workflow, diff gerado e testes | Deve consultar este registro antes de criar novas rotinas |
| Task Queue Manager | `scripts/manage-tasks-queue.py` | Agentes IA | CLI manual | argumentos CLI e `tasks-queue.json` | fila atualizada | baixo | Saida CLI e diff do arquivo de fila | Usar para controlar backlog dos agentes |
| Deploy legado | `.github/workflows/deploy.yml` | Deploy/site | Push ou manual | FTP secrets canonicos | Arquivos publicados no hosting | producao | Curl/site apos deploy e log do workflow | Preferir `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PORT`, `FTP_REMOTE_DIR` |
| Repo Hygiene | `.github/workflows/repo-hygiene.yml` + `scripts/audit_repository.py` | Governanca de repositorio | Pull request e push em branches principais | Arvore do repositorio | Relatorio de higiene no log do CI | baixo | Workflow verde e relatorio sem erros bloqueantes | Compila scripts migrados, wrappers legados e scanner global |
| Repository Wide Restructure Scanner | `scripts/maintenance/restructure_repository.py` | Governanca de repositorio | CLI ou workflow `Repository Hygiene` | Checkout completo do repositorio | `docs/audits/repository-wide-structure-report.md` e `.json` | baixo | Relatorio gerado com contagem e candidatos de migracao | Cobre o repo inteiro; nao move arquivos automaticamente |

## Como registrar nova rotina

1. Adicione uma linha em `Rotinas registradas`.
2. Atualize `docs/knowledge/repository-index.md` se criar pasta, modulo, script importante ou workflow novo.
3. Atualize `docs/knowledge/secrets-and-integrations-map.md` se criar secret, alias ou integracao.
4. Inclua validacao real no PR.
5. Nao faca merge se a rotina alterar producao sem backup, confirmacao e evidencia.
