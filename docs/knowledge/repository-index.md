# Índice Operacional do Repositório ShopVivaliz

Este documento é a entrada humana principal para entender a estrutura. O mapa máquina-legível completo está em `config/repository-structure-manifest.json`.

## Regra obrigatória

Toda criação, remoção, migração ou renomeação de rotina, script, workflow, integração, endpoint, teste ou documento operacional deve atualizar no mesmo PR:

- este índice;
- `docs/knowledge/routines-registry.md`, quando houver execução;
- `docs/knowledge/secrets-and-integrations-map.md`, quando houver integração ou secret;
- `config/repository-structure-manifest.json`, quando houver mudança de caminho;
- `docs/audits/repository-cleanup-backlog.md`, quando houver dívida ou limpeza.

## Estrutura canônica

| Área | Caminho | Função |
|---|---|---|
| Site e APIs | raiz PHP, `app/`, `api/`, `includes/` | Storefront, endpoints e integrações web existentes |
| Configuração | `config/` | Configuração compartilhada, secrets canônicos e manifesto estrutural |
| Agentes IA | `scripts/ai/` | Executores, fila, colaboração, observabilidade e relatórios IA |
| Manutenção | `scripts/maintenance/` | Auditoria, diagnóstico, segurança, rollback, health check e governança |
| Shopee | `scripts/marketplace/shopee/` | Cliente de aplicação SEO e otimização do catálogo Shopee |
| Olist/Tiny ERP | `scripts/marketplace/olist/` | Autenticação, sync, imagens, catálogo e monitoramento Olist |
| Desenvolvimento legado | `scripts/dev/legacy-reporting/`, `scripts/dev/legacy-data-tools/` | Ferramentas históricas não canônicas de produção |
| Testes unitários | `tests/unit/` | Testes isolados e rápidos |
| Testes de integração | `tests/integration/` | Integrações e fluxos controlados |
| Smoke tests | `tests/smoke/` | Verificações pós-deploy/produção |
| Workflows ativos | `.github/workflows/` | Actions ativas |
| Workflows pausados | `.github/workflows-archive/paused/` | Histórico não carregado pelo GitHub Actions |
| Conhecimento | `docs/knowledge/` | Regras, ownership, rotinas e secrets |
| Operações | `docs/operations/` | Runbooks e guias operacionais |
| Auditorias | `docs/audits/` | Evidências, incidentes, relatórios e backlog |
| Arquivo | `archive/<ano>/` | Artefatos substituídos ou malformados preservados |

## Componentes de governança

| Componente | Caminho | Validação |
|---|---|---|
| Auditor de higiene | `scripts/audit_repository.py` | Detecta secrets, aliases, estrutura, wrappers e stubs |
| Scanner global | `scripts/maintenance/restructure_repository.py` | Varre o checkout inteiro e gera relatório estrutural |
| Validador do manifesto | `scripts/maintenance/validate_structure_manifest.py` | Confirma destinos, wrappers, stubs, testes e workflows arquivados |
| Manifesto estrutural | `config/repository-structure-manifest.json` | Fonte de verdade máquina-legível das migrações |
| CI de higiene | `.github/workflows/repo-hygiene.yml` | Compila áreas canônicas, executa testes, scanner e validadores |

## Integrações principais

| Integração | Caminho principal | Nomes de secrets |
|---|---|---|
| Shopee | `scripts/marketplace/shopee/` | `SHOPEE_*` |
| Olist ERP | `scripts/marketplace/olist/` | `OLIST_*` |
| Tiny nativo | somente fluxo que chama API Tiny separada | `TINY_*` |
| Mercado Livre | APIs/scripts ML existentes | `ML_*` |
| Amazon SP-API | APIs/scripts Amazon existentes | `AMAZON_*` |
| TikTok Shop | APIs/scripts TikTok existentes | `TIKTOK_*` |
| Deploy FTP | `.github/workflows/deploy.yml` | `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PORT`, `FTP_REMOTE_DIR` |
| Email | notificações existentes | `SMTP_*`, `EMAIL_FROM`, `EMAIL_TO` |

## Compatibilidade legada

Os caminhos antigos de scripts permanecem temporariamente como wrappers. Documentos antigos da raiz permanecem como stubs. Não inserir lógica ou documentação nova nesses caminhos.

A lista completa de origem e destino está em `config/repository-structure-manifest.json`.

## Estado da reorganização

- scripts Shopee, Olist, IA e manutenção: migrados para áreas canônicas;
- ferramentas históricas da raiz: migradas para `scripts/dev/` com wrappers;
- testes existentes: separados em `unit` e `integration`;
- workflows confirmados como pausados: removidos da pasta ativa e arquivados;
- documentos operacionais e relatórios da raiz: migrados em lotes com stubs;
- arquivo com nome de caminho Windows corrompido: preservado em `archive/2026/artifacts/` e removido da raiz;
- credencial Olist encontrada em documento: removida da árvore atual, incidente registrado e rotação externa exigida.

## Antes de criar algo novo

1. Procure rotina equivalente.
2. Escolha o diretório canônico.
3. Use secrets canônicos.
4. Registre ownership, gatilho, entrada, saída, risco e validação.
5. Atualize o manifesto.
6. Adicione teste ou evidência objetiva.
7. Não marque como concluído sem CI/log/read-back aplicável.
