# Reemissao do grant humano MCP central -> Marina (2026-11-01)

## Causa comprovada
A PR de seguranca #3478 foi revisada por `shopvivaliz-human`, mas a API oficial GitHub registra `auto_merge != null` no PR integrado, habilitado pela conta humana. O contrato vigente em `scripts/validate-owner-pr-grant.py` e `scripts/ops/activate-mcp-central-marina-readonly.py` exige **merge direto e pessoal**, comprovado com `auto_merge == null`. Portanto, **#3478 nao constitui grant ativavel**. Nao mudar o verificador nem promover a PR original como autorizacao valida. A rota central permanece desligada.

## Escopo da nova decisao
Reemitir somente o mesmo grant minimo, em `security/owner-approvals/<NUMERO_DESTA_PR>.json`, criado neste PR:
- Provedor: `shopvivaliz-central-mcp` no backend `always-free-arm-1787907847-26`.
- Host exclusivo: `marina-a1-4c24g` (VM ativa de producao).
- Recurso: `mcp:central:marina`; capacidade unica `marina_readonly`.
- Leitura restrita: `hosts_list`, `host_health`, `service_status` para as unidades permitidas pelo codigo; sem SSH/shell generico, comandos administrativos, sudo, deploy ou escrita.
- Identidade de host/SSH fixada e vigente, prazo `2027-02-01T00:00:00Z`, verificacao de grant e checks obrigatorios. Nao reutilizar VM antiga nem credenciais da conta humana.

## Passos obrigatorios de autorizacao
1. Agente cria Draft com arquivo de grant numerado e testes de seguranca direcionados. Nao habilitar auto-merge.
2. Somente `shopvivaliz-human` valida o SHA final, aprova e clica **Merge pull request** pessoalmente. `Enable auto-merge` nao serve; caso seja ativado, este grant sera negado de novo.
3. O operador tecnico, usando identidade distinta, verifica `OWNER_PR_GRANT=AUTHORIZED`, revisao/merge/hash no `main`, metadados SSH, release instalado, lease/fencing e preflight oficial **antes** da ativacao.
4. Mesmo apos o merge, a rota fica OFF ate instalacao autorizada no backend, E2E real de `hosts_list`, `host_health`, `service_status` e negativas de mutacoes. O MCP independente da Marina permanece obrigatorio.
5. Rollback: revogar o grant por PR, remover drop-in pelo procedimento oficial e validar a indisponibilidade da rota.

Esta reemissao nao altera escopo, permissao externa ou runtime. A PR antiga #3478 permanece no historico, mas nao pode ser usada para ativacao.
