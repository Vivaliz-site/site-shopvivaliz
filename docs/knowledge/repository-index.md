# Índice canônico do repositório

## Inventário exaustivo

- `docs/knowledge/repository-file-index.md`: lista gerada de todos os diretórios e arquivos versionados, com função inferida por caminho e extensão.
- `docs/audits/repository-hygiene.md`: grupos de conteúdo idêntico e caminhos candidatos a revisão; o relatório não autoriza remoção automática.
- `scripts/generate-repository-index.ps1`: gerador que lê o índice Git em formato NUL, preservando nomes de arquivo incomuns sem abrir ou reproduzir segredos.

## Aplicação

- `admin/`, `api/`, `includes/`, `public/`: aplicação PHP e superfícies públicas e administrativas.
- `assets/`, `css/`, `js/`: recursos de frontend.
- `config/`: configuração versionada sem valores secretos.
- `database/`, `migrations/`: estrutura e evolução do banco.

## Automação e governança

- `.github/workflows/`: workflows ativos. Devem usar permissões mínimas e artifacts obrigatórios quando produzirem evidência.
- `.github/workflows/repository-governance.yml`: gate read-only para PRs e pushes em `main`.
- `.github/workflows/agents-hourly-deep-audit.yml`: auditoria profunda horária no minuto 17.
- `scripts/audit-agents-real-work.py`: auditor profundo de agentes baseado em evidência.
- `scripts/maintenance/audit_automation_changes.py`: bloqueia regressões novas em agentes e automações.
- `scripts/maintenance/audit_active_workflows.py`: verifica todos os workflows ativos.
- `scripts/maintenance/system_health_check.py`: health check canônico para agentes, fila e executores aposentados.
- `scripts/maintenance/finalize_reorganization.py`: contrato final da reorganização e scanner da árvore atual.

## Agentes e executores

- `scripts/ai/retired_executor.py`: implementação compartilhada para executores aposentados em estado `blocked`.
- `scripts/ai/autonomous_executor.py`: entrypoint canônico bloqueado do executor autônomo aposentado.
- `scripts/ai/continuous_executor.py`: entrypoint canônico bloqueado do executor contínuo aposentado.
- `scripts/ai/parallel_executor.py`: entrypoint canônico bloqueado do executor paralelo aposentado.
- `scripts/all-documented-agents.py`: auditoria de prontidão; operações externas sem credenciais permanecem `blocked`.
- `tasks-queue.json`: fila canônica. Sucesso só é permitido em `completed_verified` com evidência completa.

Os caminhos antigos em `scripts/` permanecem apenas como wrappers quando existe compatibilidade a preservar. Wrapper não pode conter regra de negócio, mutação de fila ou publicação Git.

## Marketplaces

- `scripts/marketplace/olist/sync_master.py`: entrypoint canônico fail-closed do sincronizador Olist aposentado.
- `scripts/marketplace/olist/oauth_login.py`: entrypoint canônico que bloqueia o login automatizado inseguro.
- `scripts/marketplace/shopee/`: rotinas Shopee canônicas quando existentes.
- `.github/workflows/shopee-production-seo.yml`: produção Shopee exclusivamente manual, com environment e evidência obrigatória.

## Organização por domínio

- `scripts/ai/`: agentes e executores de IA.
- `scripts/maintenance/`: manutenção, auditoria, health e migração controlada.
- `scripts/marketplace/`: integrações separadas por canal.
- `scripts/production/`: mutações controladas de produção.
- `scripts/dev/`: ferramentas exclusivamente locais.
- `docs/knowledge/`: documentação canônica.
- `docs/operations/`: runbooks atuais.
- `docs/audits/`: evidências, relatórios e backlog.
- `archive/`: material histórico não executável.

## Evidência e segurança

- `artifacts/repository-governance/`: relatório de mudanças novas.
- `artifacts/workflow-policy/`: política global de workflows ativos.
- `artifacts/system-health/`: estado de fila, agentes e executores.
- `artifacts/reorganization-final/`: evidência final da estrutura crítica.
- Segredos existem somente em stores protegidos. Documentos e exemplos não podem conter valores reais.

## Regra de atualização

Toda mudança estrutural deve atualizar este índice, a política e o backlog no mesmo PR. Uma mudança só é considerada concluída quando o código real, testes, checks e artifacts correspondem ao SHA revisado. Documentos históricos na raiz são dívida inventariada e não justificam migração em massa, stubs permanentes ou afirmação de sucesso sem execução.
