# Índice canônico do repositório

## Aplicação

- `admin/`, `api/`, `includes/`, `public/`: aplicação PHP e superfícies públicas/administrativas.
- `assets/`, `css/`, `js/`: recursos de frontend.
- `config/`: configuração versionada sem valores secretos.
- `database/`, `migrations/`: estrutura e evolução de banco.

## Automação

- `.github/workflows/`: automações ativas.
- `scripts/audit-agents-real-work.py`: auditor profundo de agentes baseado em evidência.
- `scripts/maintenance/audit_automation_changes.py`: gate contra regressões novas em agentes e automações.
- `.github/workflows/repository-governance.yml`: gate por PR/push com artifact obrigatório.
- `.github/workflows/agents-hourly-deep-audit.yml`: auditoria horária no minuto 17.

## Organização alvo

- `scripts/ai/`: agentes.
- `scripts/maintenance/`: manutenção e auditoria.
- `scripts/marketplace/`: integrações por canal.
- `scripts/production/`: mutações controladas de produção.
- `scripts/dev/`: ferramentas locais.
- `docs/knowledge/`: documentação canônica.
- `docs/operations/`: runbooks atuais.
- `docs/audits/`: evidências e backlog.
- `archive/`: material histórico não executável.

## Regra de atualização

Toda mudança estrutural deve atualizar este índice, a política de estrutura e o backlog no mesmo PR. Caminhos antigos só permanecem como ponte quando um consumidor real foi identificado e existe prazo de remoção.