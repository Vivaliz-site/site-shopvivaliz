# Knowledge Base do ShopVivaliz

Esta pasta é a referência operacional obrigatória para agentes e desenvolvedores.

## Documentos principais

- [`repository-index.md`](repository-index.md) — estrutura canônica e componentes principais.
- [`routines-registry.md`](routines-registry.md) — rotinas, gatilhos, entradas, saídas, riscos e validações.
- [`ownership-map.md`](ownership-map.md) — donos funcionais por área.
- [`structure-policy.md`](structure-policy.md) — regras de criação, migração, wrappers, stubs e workflows.
- [`secrets-and-integrations-map.md`](secrets-and-integrations-map.md) — nomes canônicos de secrets e integrações.
- [`agent-rules.md`](agent-rules.md) — regras obrigatórias de execução e evidência.
- [`testing.md`](testing.md) — testes mínimos e validação.
- [`deploy.md`](deploy.md) — publicação e pós-deploy.
- [`troubleshooting.md`](troubleshooting.md) — diagnóstico operacional.
- [`data-integrity.md`](data-integrity.md) — integridade de catálogo e banco.
- [`pricing-integrity.md`](pricing-integrity.md), [`stock-integrity.md`](stock-integrity.md), [`cart-integrity.md`](cart-integrity.md), [`order-integrity.md`](order-integrity.md) — regras comerciais críticas.

## Fontes complementares obrigatórias

- `config/repository-structure-manifest.json` — mapa máquina-legível de todas as migrações.
- `docs/operations/legacy-root-docs-index.md` — política e histórico da migração documental.
- `docs/audits/repository-cleanup-backlog.md` — estado dos lotes e bloqueios.
- `docs/audits/security/credential-exposure-2026-07-30.md` — incidente de credencial Olist e ações exigidas.

## Ordem recomendada

1. Identificar o sintoma ou objetivo.
2. Consultar `repository-index.md`.
3. Confirmar a rotina em `routines-registry.md`.
4. Confirmar ownership em `ownership-map.md`.
5. Consultar `structure-policy.md` antes de criar ou mover arquivos.
6. Consultar o mapa de secrets quando houver integração externa.
7. Verificar o manifesto antes de usar caminho legado.
8. Executar testes/CI e coletar evidência.
9. Atualizar a documentação e o manifesto no mesmo PR.

## Estado da reorganização

Foram criadas áreas canônicas para:

- Shopee e Olist em `scripts/marketplace/`;
- agentes em `scripts/ai/`;
- manutenção em `scripts/maintenance/`;
- ferramentas históricas em `scripts/dev/`;
- testes em `tests/unit/` e `tests/integration/`;
- workflows pausados em `.github/workflows-archive/paused/`;
- documentos operacionais em `docs/operations/`;
- relatórios e incidentes em `docs/audits/`.

Caminhos antigos permanecem somente como wrappers ou stubs quando necessário. A lista completa está no manifesto.

Documentação não substitui evidência de código, testes, logs, artifacts, banco ou resposta da API/servidor.
