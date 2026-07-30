# Índice de Documentos Legados da Raiz

A migração dos documentos operacionais e relatórios identificados na raiz foi executada em lotes seguros.

## Regra

- Conteúdo operacional novo não entra na raiz.
- Documentos antigos permanecem como stubs somente quando links legados podem existir.
- A lista completa de origem/destino está em `config/repository-structure-manifest.json`.
- O CI valida se o destino existe e se a origem é um stub.

## Destinos utilizados

| Tipo | Destino |
|---|---|
| Regras e memória permanente | `docs/knowledge/` |
| Guias de agentes | `docs/operations/agents/` |
| Deploy e FTP | `docs/operations/deploy/` |
| Olist/Tiny | `docs/operations/olist/` |
| Shopee | `docs/operations/shopee/` |
| Monitoramento | `docs/operations/monitor/` |
| Ads | `docs/operations/ads/` |
| Troubleshooting | `docs/operations/troubleshooting/` |
| Relatórios e auditorias históricas | `docs/audits/legacy-reports/` |
| Incidentes de segurança | `docs/audits/security/` |
| Artefatos malformados/substituídos | `archive/<ano>/artifacts/` |

## Lotes concluídos

1. Guias de Trio IA, Olist, FTP e Shopee.
2. Índices e instruções de agentes.
3. Relatórios de agentes, saúde, diagnósticos e validações.
4. Guias 24/7, setup e troubleshooting.
5. Entradas duplicadas `START_HERE`/agentes.
6. Documentação Olist, incluindo sanitização de credencial.
7. Monitor, investigações e Google Ads.
8. Relatórios executivos, auditorias e colaboração IA.
9. Arquivo com nome de caminho Windows corrompido movido para archive.

## Fonte de verdade

Não manter uma tabela duplicada manual com dezenas de caminhos. Use:

- `config/repository-structure-manifest.json` para automação;
- `docs/knowledge/repository-index.md` para visão humana;
- `scripts/maintenance/validate_structure_manifest.py` para validação.

## Remoção futura de stubs

Só remover stub quando:

- busca não encontrar links/referências;
- nenhum workflow ou script depender do nome;
- manifesto for atualizado;
- CI estiver verde.
