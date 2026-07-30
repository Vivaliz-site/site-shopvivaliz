# Politica de Estrutura do Repositorio

Esta politica organiza onde novos arquivos devem entrar e como evitar duplicidade.

## Objetivo

Reduzir arquivos soltos, scripts duplicados, workflows sem documentacao e integracoes com secrets espalhados.

## Estrutura alvo

```text
app/                  Codigo principal do site ou aplicacao
api/                  Endpoints publicos e APIs internas
config/               Configuracao centralizada e leitura de secrets
scripts/
  production/         Rotinas que alteram producao
  maintenance/        Diagnostico, limpeza, reparos e auditorias
  marketplace/        Shopee, Olist, ML, Amazon, TikTok e outros canais
    shopee/           Rotinas canonicas Shopee
  ai/                 Agentes, automacoes e rotinas IA
  dev/                Ferramentas locais e apoio ao desenvolvimento
docs/
  knowledge/          Memoria operacional dos agentes
  operations/         Runbooks de operacao
  audits/             Auditorias, backlogs e relatorios
tests/
  unit/               Testes unitarios
  integration/        Testes com integracoes ou banco controlado
  smoke/              Testes rapidos de producao/pos-deploy
.github/workflows/    CI, deploy e automacoes GitHub Actions
storage/private/      Dados privados locais; nao versionar conteudo real
logs/                 Logs locais; nao versionar conteudo real
archive/              Codigo legado arquivado com motivo e prazo
```

## Regras de criacao

1. Novo script que altera producao deve ficar em `scripts/production/` ou ter `production` no nome e registro em `routines-registry.md`.
2. Novo script de marketplace deve ficar em `scripts/marketplace/<canal>/` ou ser registrado como legado no indice.
3. Novo workflow deve ter entrada em `routines-registry.md`.
4. Nova integracao ou secret deve atualizar `secrets-and-integrations-map.md`.
5. Novo documento principal deve ser ligado em `docs/knowledge/README.md`.
6. Arquivo experimental deve ficar em `archive/` ou `scripts/dev/`, nunca misturado com producao.
7. Logs, backups, dumps, relatórios privados e arquivos `.env` reais nao devem ser versionados.
8. Documento operacional novo nao deve ser criado na raiz, exceto `README.md`, `START_HERE.md`, `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md` ou `LICENSE`.

## Regras para mover arquivos existentes

Mover arquivo existente exige:

- identificar todos os imports, includes, chamadas CLI e workflow references;
- atualizar documentacao e testes;
- manter wrapper temporario quando houver risco de quebra;
- registrar o item no backlog de limpeza com status `migrar`, `arquivar`, `mapeado-globalmente` ou `concluido-com-wrapper`.

## Scanner estrutural global

O arquivo `scripts/maintenance/restructure_repository.py` deve ser usado para varrer 100% do checkout e gerar:

- `docs/audits/repository-wide-structure-report.md`
- `docs/audits/repository-wide-structure-report.json`

Ele classifica documentos soltos na raiz, scripts soltos, artifacts temporarios, patches legados e candidatos a `archive/`.

## Migracoes fisicas aplicadas

| Data | Area | Antes | Depois | Regra aplicada |
|---|---|---|---|---|
| 2026-07-30 | Shopee | `scripts/shopee_production_seo_apply.py` | `scripts/marketplace/shopee/production_seo_apply.py` | Implementacao movida, wrapper legado mantido |
| 2026-07-30 | Shopee | `scripts/shopee_full_catalog_optimizer.py` | `scripts/marketplace/shopee/full_catalog_optimizer.py` | Implementacao movida, wrapper legado mantido |
| 2026-07-30 | Repo inteiro | documentos operacionais soltos na raiz | `docs/operations/legacy-root-docs-index.md` | Mapeamento global criado; movimentacao fisica futura exige stubs |
| 2026-07-30 | Repo inteiro | auditoria parcial manual | `scripts/maintenance/restructure_repository.py` | Scanner global criado e ligado ao CI |

## Arquivamento

Use `archive/<ano>/<area>/` para codigo legado. Todo arquivo arquivado deve ter um README local ou entrada no backlog explicando:

- origem;
- motivo do arquivamento;
- substituto atual;
- prazo ou condicao para remocao definitiva.

## Bloqueios

Nao fazer merge quando:

- script novo nao esta registrado;
- workflow novo nao esta documentado;
- secret novo nao aparece no mapa;
- arquivo de producao altera dados sem backup/read-back;
- alias legado e usado fora do centralizador sem justificativa;
- `.env`, token, chave privada, dump ou log sensivel foi versionado;
- documento operacional novo foi criado na raiz sem justificativa e sem entrada no indice.
