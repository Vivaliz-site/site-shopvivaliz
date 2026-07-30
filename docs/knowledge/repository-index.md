# Índice Operacional do Repositório ShopVivaliz

Este documento é a fonte de verdade para entender a estrutura do repositório, localizar rotinas e evitar scripts soltos.

## Regra obrigatória para agentes e desenvolvedores

Sempre que uma nova rotina, workflow, script, integração, job agendado, endpoint, migration ou módulo operacional for criado, este documento deve ser atualizado no mesmo PR/commit.

A entrada nova deve informar:

- caminho do arquivo ou diretório;
- dono funcional;
- gatilho de execução;
- entradas obrigatórias;
- saídas/artefatos gerados;
- secrets ou integrações usadas;
- forma mínima de validação;
- riscos conhecidos.

Nenhuma rotina nova deve ser criada sem registro aqui.

## Visão de alto nível

| Área | Caminho | Função | Observação |
|---|---|---|---|
| Storefront PHP legado | raiz, `api/`, includes e páginas PHP | Site público, APIs e integrações atuais | Ainda é parte do ambiente produtivo |
| Automações | `.github/workflows/` | CI, deploy, agentes, marketplace e validações | Todo workflow novo deve ser registrado aqui |
| Scripts operacionais | `scripts/` | Sincronização, marketplaces, agentes, auditorias e utilitários | Scripts com credenciais devem usar `config/secrets.py` ou secrets de Actions |
| Scripts Marketplace | `scripts/marketplace/` | Canais externos organizados por subpasta | Shopee já migrado para `scripts/marketplace/shopee/` |
| Scripts de manutenção | `scripts/maintenance/` | Diagnóstico, auditoria e reestruturação | Inclui scanner global do repositório |
| Configuração | `config/` | Secrets, constantes e configuração compartilhada | `config/secrets.py` é o centralizador Python |
| Documentação operacional | `docs/knowledge/` | Memória dos agentes e regras de operação | Deve ser consultada antes de mudanças relevantes |
| Operações | `docs/operations/` | Runbooks e documentos legados operacionais consolidados | Documentos soltos da raiz devem migrar para cá ou para audits |
| Auditorias | `docs/audits/` | Backlogs, relatórios e varreduras estruturais | Scanner global grava relatórios aqui |
| Medusa/Next em transição | `claude/medusa/` | Backend headless e storefront alvo | Ainda não substituir produção sem checklist próprio |
| Logs/relatórios | `logs/`, `storage/private/` | Evidências temporárias e backups gerados | Não commitar valores sensíveis reais |

## Workflows conhecidos

| Workflow | Caminho | Gatilho | Saída esperada | Validação mínima |
|---|---|---|---|---|
| Repository Hygiene | `.github/workflows/repo-hygiene.yml` | PR, push e manual | Auditoria de higiene + relatório estrutural global | Compilar governança, rodar scanner e audit sem erro bloqueante |
| Shopee SEO Production Apply | `.github/workflows/shopee-production-seo.yml` | Manual ou trigger controlado | Relatório JSON, backup e comentário na issue de validação | Deve provar `item_id`, status, leitura posterior e invariantes de preço/estoque |
| Trio IA Autônomo | `.github/workflows/ai-autonomous-executor.yml` | Manual/pausado | Workflow consolidado em watchdog | Não reativar sem verificar duplicidade |
| Trio IA Ecommerce | `.github/workflows/ai-trio-ecommerce.yml` | Manual | Implementação de tarefa específica | PR/commit com evidência |
| Deploy | `.github/workflows/deploy.yml` ou pipeline equivalente | Push/manual | Publicação em hospedagem/VM | Teste HTTP real pós-deploy |
| QA/Testes | workflows de CI | Push/PR | Checks de sintaxe e testes | Status verde no GitHub Actions |

## Scripts operacionais conhecidos

| Script | Função | Entradas | Saídas | Observação |
|---|---|---|---|---|
| `scripts/maintenance/restructure_repository.py` | Varre 100% do checkout e classifica arquivos fora da estrutura alvo | Árvore local do repo | `docs/audits/repository-wide-structure-report.md/json` | Não move arquivos automaticamente |
| `scripts/audit_repository.py` | Auditoria de higiene, secrets, aliases e estrutura | Árvore local do repo | Log CI e exit code | Bloqueia regressões estruturais |
| `scripts/marketplace/shopee/production_seo_apply.py` | Aplica SEO real no catálogo Shopee com backup e leitura posterior | `--confirm`, `--limit`, secrets Shopee | JSON em `logs/shopee-production-seo/`, backup em `storage/private/shopee-production-backups/` | Nunca altera preço ou estoque; caminho antigo é wrapper |
| `scripts/marketplace/shopee/full_catalog_optimizer.py` | Geração de títulos/descrições e apoio ao SEO | Catálogo Shopee | Relatórios e candidatos de otimização | Caminho antigo é wrapper |
| `scripts/utils/shopee_client.py` | Cliente Shopee Partner API v2 | Secrets Shopee | Chamadas API assinadas | Renova token automaticamente na inicialização, a cada 2h e quando expirar |
| `scripts/manage-tasks-queue.py` | Gerencia fila de tarefas do Trio IA | CLI e `tasks-queue.json` | Alteração da fila | Registrar mudanças de formato da fila aqui |
| `scripts/autonomous-executor.py` | Executor de tarefas autônomas | Fila e secrets dos provedores | Commits/relatórios | Deve respeitar regras de agentes |

## Integrações principais

| Integração | Uso | Documento de secrets |
|---|---|---|
| Shopee | Catálogo, SEO, imagens e atualização de produtos | `docs/knowledge/secrets-and-integrations-map.md` |
| Olist/Tiny | ERP, catálogo e sincronizações | `docs/knowledge/secrets-and-integrations-map.md` |
| Mercado Livre | Marketplace | `docs/knowledge/secrets-and-integrations-map.md` |
| Amazon SP-API | Marketplace | `docs/knowledge/secrets-and-integrations-map.md` |
| TikTok Shop | Marketplace | `docs/knowledge/secrets-and-integrations-map.md` |
| Melhor Envio | Frete/logística | `docs/knowledge/secrets-and-integrations-map.md` |
| SMTP/Titan | Relatórios e notificações | `docs/knowledge/secrets-and-integrations-map.md` |

## Dívidas técnicas registradas

| Item | Risco | Direção de limpeza |
|---|---|---|
| Aliases duplicados de secrets | Configuração inconsistente entre workflows, scripts e `.env` | Manter um nome canônico por integração e aliases apenas no centralizador |
| Olist/Tiny com nomes paralelos | Tokens equivalentes podem ser cadastrados com nomes diferentes | Padronizar como `OLIST_*` para marketplace/ERP principal e `TINY_*` apenas quando endpoint Tiny nativo exigir |
| Scripts sem registro | Dificulta auditoria e manutenção | Todo script novo deve ser registrado neste índice |
| Workflows de trigger temporário | Podem ficar ativos sem necessidade | Documentar trigger, remover quando não for mais usado ou marcar como temporário |
| Documentos operacionais na raiz | Dificulta localização e causa duplicidade | Migrar para `docs/operations/` ou `docs/audits/` usando índice legado |

## Checklist antes de criar rotina nova

1. Verificar se já existe script/workflow semelhante.
2. Verificar se já existe secret canônico para a integração.
3. Usar nomes canônicos do mapa de secrets.
4. Registrar a rotina neste índice.
5. Registrar ou atualizar integração em `secrets-and-integrations-map.md`.
6. Adicionar validação objetiva: teste, log, artefato, comentário em issue ou leitura posterior.
7. Não fazer merge se a documentação operacional ficar desatualizada.
