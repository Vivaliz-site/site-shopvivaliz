# Auditoria estatica de arquivos

## Objetivo

`scripts/audit-static-tree.py` identifica arquivos potencialmente legados sem executar remocoes destrutivas. O modo padrao e dry-run, adequado para agentes e humanos revisarem o estado do repositorio.

## Comandos

```bash
npm run audit:tree
npm run audit:tree:json
npm run audit:tree:quarantine
```

## Politica de risco

- `low`: artefatos de historico ou log na raiz que podem ser movidos para `storage/orphan-quarantine/`.
- `medium`: arquivo sem referencia estatica por caminho; exige revisao tecnica antes de qualquer remocao.

## Regras

- A quarentena so move itens `low`.
- Arquivos protegidos como `.env`, `docker-compose.yml`, `Dockerfile`, `git-auto-sync.py`, filas e caches operacionais sao preservados.
- Um resultado `medium` nao significa arquivo morto; rotas PHP podem ser chamadas diretamente por URL, cron, webhook ou painel externo.
- Nunca comitar `storage/orphan-quarantine/` sem revisao humana.

## Ultima validacao local

Em dry-run, a auditoria leu 2847 arquivos e reportou 8 achados: 2 de baixo risco e 6 para revisao.
