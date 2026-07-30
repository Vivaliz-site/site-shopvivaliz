# Registro de Rotinas Operacionais

Toda rotina executável deve ser registrada aqui no mesmo PR em que for criada ou alterada.

## Campos obrigatórios

Nome, arquivo canônico, dono funcional, gatilho, entradas, saídas, risco e validação.

## Rotinas canônicas

| Nome | Arquivo canônico | Dono | Gatilho | Entradas | Saídas | Risco | Validação |
|---|---|---|---|---|---|---|---|
| Repository Hygiene | `.github/workflows/repo-hygiene.yml` | Governança | PR, push, manual | Checkout do repo | Testes, auditoria, relatório e artifact | baixo | Todos os steps verdes |
| Scanner estrutural global | `scripts/maintenance/restructure_repository.py` | Governança | CI ou CLI | Árvore do repo | Relatório Markdown/JSON | baixo | Relatório gerado sem falha |
| Validador do manifesto | `scripts/maintenance/validate_structure_manifest.py` | Governança | CI ou CLI | Manifesto e árvore | Lista de inconsistências | baixo | Exit code 0 |
| Auditor de higiene | `scripts/audit_repository.py` | Segurança/Governança | CI ou CLI | Arquivos versionados | Erros e avisos sem valores de secrets | baixo | Exit code 0 |
| Shopee SEO Production Apply | `.github/workflows/shopee-production-seo.yml` + `scripts/marketplace/shopee/production_seo_apply.py` | Marketplace Shopee | Manual ou trigger controlado | Secrets Shopee, confirmação e limite | Backup, relatório, artifact e read-back | produção | `updated_verified` ou `verified_unchanged`; preço/estoque invariáveis |
| Shopee Full Catalog Optimizer | `scripts/marketplace/shopee/full_catalog_optimizer.py` | Marketplace Shopee | CLI/importação controlada | Catálogo e secrets Shopee | Candidatos, backup e relatório | médio/produção | Dry-run, teste de invariantes e rollback |
| Shopee Safety Gate | `.github/workflows/shopee-optimizer-safety.yml` | QA/Marketplace | PR, push, manual | Código e testes Shopee | Check de segurança | baixo | Testes de integração verdes |
| Olist Sync Master | `scripts/marketplace/olist/olist-sync-master.py` | Olist ERP | CLI/workflow que o invoque | `OLIST_*`, parâmetros de sync | Cache/log/alterações controladas | produção | Contagem, resposta API e read-back |
| Olist OAuth/Login tools | `scripts/marketplace/olist/olist-oauth-login.py` e ferramentas relacionadas | Olist ERP | CLI manual | `OLIST_*` e navegador quando aplicável | Tokens em ambiente seguro e status | alto | Autenticação sem imprimir secrets |
| Olist Images tools | `scripts/marketplace/olist/repair-olist-images.py`, `export-olist-images-csv.py`, `download-olist-images-v2.py` | Olist/Imagens | CLI | Cache/API Olist | Imagens, CSV e relatório | médio | Contagem e arquivos de saída |
| AI Autonomous Executor | `scripts/ai/autonomous-executor.py` | Agentes IA | Workflow/CLI | Fila, providers e política | Commits/relatórios somente com evidência | alto | Diff, testes e origem auditável |
| AI Collaboration | `scripts/ai/ai_collaboration.py` | Agentes IA | CLI/importação | Prompt/tarefa e APIs configuradas | Resultado colaborativo | médio | Resposta real dos providers ou erro explícito |
| Task Queue Manager | `scripts/ai/manage-tasks-queue.py` | Agentes IA | CLI | Argumentos e fila | Fila atualizada | médio | Diff e validação JSON |
| Observabilidade IA | `scripts/ai/metrics-collector.py`, `observability-suite.py`, `generate-report.py` | Agentes IA | CLI/workflow | Logs e execuções | Métricas/relatórios | baixo | Artifact ou relatório gerado |
| Deploy Diagnostic | `scripts/maintenance/deploy-diagnostic.py` | Infraestrutura | CLI | Configuração/deploy | Diagnóstico | baixo | Comandos e endpoints verificados |
| Quality Assurance | `scripts/maintenance/quality-assurance.py` | QA | CLI/workflow | Código | Resultado de validação | baixo | Exit code e logs |
| Vulnerability Scanner | `scripts/maintenance/vulnerability-scanner.py` | Segurança | CLI/workflow | Código/dependências | Relatório | médio | Sem exposição de credenciais |
| Rollback Manager | `scripts/maintenance/rollback-manager.py` | Infraestrutura | CLI com confirmação | Commit alvo | Plano ou branch de revert | alto | Branch isolada, diff e testes |
| System Health Check | `scripts/maintenance/system-health-check.py` | Operações | CLI/workflow | Estado real | Relatório baseado em evidência | baixo | Não aceitar mensagens autorreferidas como prova |

## Rotinas legadas

- Caminhos antigos listados no manifesto são wrappers, não implementações.
- `scripts/dev/legacy-reporting/` e `scripts/dev/legacy-data-tools/` são ferramentas históricas, não rotinas automáticas de produção.
- Workflows em `.github/workflows-archive/paused/` estão desativados e não devem ser reativados sem novo registro e validação.

## Regra para novas rotinas

1. Criar no diretório canônico.
2. Registrar nesta tabela.
3. Atualizar `config/repository-structure-manifest.json` se houver caminho novo ou legado.
4. Atualizar mapa de secrets quando aplicável.
5. Definir teste/evidência.
6. Para produção: backup, confirmação, rollback/read-back e artifact.
