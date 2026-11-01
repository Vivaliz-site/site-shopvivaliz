# Substituir grant #3478 por autorizacao exclusivamente humana, sem auto-merge

Data: 2026-11-01. STATUS: PROPOSTA SEM ACESSO CONCEDIDO.

## Diagnostico reproduzido no executor real

A PR de seguranca #3478 foi revisada no HEAD `0fd80338283a41cd61affa2ce31116d773445aea` e integrada como `4c5911de0547d6c6515ac87eb703568ee37c7640` por `shopvivaliz-human`. Contudo, a API GitHub registra `auto_merge` **nao nulo** (auto_merge_enabled por `shopvivaliz-human`). A regra canônica e intencional `scripts/validate-owner-pr-grant.py` exige `auto_merge == null` para comprovar merge pessoal.

**Preflight real no backend:** `OWNER_PR_GRANT=DENIED reason=owner_only_merge_not_proven_manual`, exit=1, task `867d0f97-8ed8-4348-bec8-641335b8b887`. Nenhuma flag, service restart, SSH Maria ou grant ativado. Nao enfraquecer verificador ou interpretar `merged_by` como suficiente.

## Substituicao formal

Esta PR deve **remover** `security/owner-approvals/3478.json` e **adicionar**, como arquivo novo imutavel, `security/owner-approvals/<numero_nesta_pr>.json` com escopo identico:
- recurso `mcp:central:marina`, principal `shopvivaliz-central-mcp`;
- origem `always-free-arm-1787907847-26`, destino `marina-a1-4c24g`;
- unica capacidade `marina_readonly`;
- expiracao maxima `2027-02-01T00:00:00Z`;
- nenhuma operacao mutante e nenhum token ou secreto nesta PR.

## Ato humano vinculante

1. Um agente prepara a PR, testes, hash e gates, marca Ready e solicita apenas `shopvivaliz-human`.
2. A pessoa autentica `shopvivaliz-human`, revisa **exatamente o ultimo HEAD SHA**, e aprova.
3. **Clicar Merge pull request / Confirm merge pessoalmente. NAO usar Enable auto-merge.** O verificador rejeita qualquer `auto_merge` nao nulo, inclusive quando habilitado pelo proprio humano.
4. Antes de ativar qualquer coisa, operador protegido testa `scripts/validate-owner-pr-grant.py` contra o merge SHA e recebe `AUTHORIZED`. Se `DENIED`, deixa rota desabilitada.
5. Mesmo com `AUTHORIZED`, conferir grant imutavel, perfil da conta ubuntu, SSH privado pinado, RBAC efetivo do host e E2E authenticated do MCP, com lease, audit e rollback. O MCP independente da Marina continua separado.
