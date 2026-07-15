# Pacote menor - limpeza historica de credenciais Olist/Tiny

Data: 2026-07-05
Origem: commit ea09c00

## Conteudo do pacote

Patch gerado em:
- release-notes/patches/cleanup-historico-credenciais-only.patch

Arquivos incluidos no patch:
- AUDITORIA-COMPLETA.md (removido)
- AUDITORIA-ESTRUTURA.md (removido)
- AUDITORIA-PIPELINES.md (removido)
- RELATORIO-VERIFICACAO-PROFUNDA.md (removido)
- audit-report.txt (removido)
- auditoria_git_2026-07-05_1956.txt (removido)
- continuous-report.md (removido)
- docs/olist-tiny-erp-api-knowledge.md (ajuste de exemplo para usar variavel de ambiente)

## Garantias de seguranca

- Nenhuma variavel tecnica foi removida do codigo.
- Nenhum endpoint Olist/Tiny foi alterado.
- Nomes preservados: OLIST_CLIENT_ID, OLIST_CLIENT_SECRET, OLIST_REFRESH_TOKEN, OLIST_ACCESS_TOKEN, TINY_CLIENT_ID, TINY_CLIENT_SECRET.
- Padrões preservados: getenv(...), $_ENV e env(...).
- Arquivos .env.example preservados.

## Objetivo

Permitir reaplicar apenas a limpeza historica em outro branch/repo sem carregar alteracoes funcionais nao relacionadas.
