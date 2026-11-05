# Aprovacao P2: rota administrativa MCP central -> Marina (2026-11-05)

## Situacao comprovada
- O MCP central no backend alcança os quatro hosts por inventario, mas o schema de `admin_command_run`, `task_submit` e `service_action` exclui Marina sem grant administrativo efetivo.
- O grant de leitura de Marina (`security/owner-approvals/3478.json`) esta atestado, mas o arquivo protegido `marina-central-admin-owner-grant.json` esta ausente e a flag `SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED` nao esta ativa.
- O MCP **independente** da Marina ja executa `marina_admin_shell` com `as_root` por Cloudflare Access e permanece independente. Esta PR nao altera esse caminho.

## Escopo solicitado, explicitamente protegido
Somente `resource=mcp:central:marina`, `principal=shopvivaliz-central-mcp`, `host=marina-a1-4c24g`, `controller_host=always-free-arm-1787907847-26` e capacidade **`marina_admin_command`**. Nao autoriza IP selecionavel pelo cliente, host historico excluido, publicacao de endpoint, credenciais novas, permissao fora do host ou troca da identidade humana.

A capacidade pedida admite shell administrativo no host de producao pelo transporte privado com host key pinada; possui impacto P2 e exige revisao humana formal no HEAD exato, sem equivalencia por aprovacao em chat. As chamadas do MCP continuam sujeitas a identidade real, controles de execucao, lease/fencing quando aplicaveis, auditoria e idempotencia.

## Verificacoes antes da ativacao
1. GitHub: `APPROVED` por `shopvivaliz-human` no HEAD aprovado e merge conforme branch protections; checar schema do documento `security/owner-approvals/<PR>.json` e o verificador oficial.
2. Runtime: conferir operador, SHA, host, permissoes e autenticar o grant pelo mecanismo existente; gravar atestacao protegida `0600` com ownership real e habilitar a flag somente no servico correto.
3. E2E: confirmar `tools/list`, executar canario `hostname; id -u` autorizado em Marina pelo MCP **central**, validar as rotas de backend, Fred-Win e KOCEPSV sem regressao e os testes negativos sem grant ou escopo diferente.
4. Revalidar auditoria, persistencia apos restart, isolamento do MCP independente e sem efeito em DB, checkout, pagamentos ou workers.

## Rollback
Desabilitar somente a flag de administracao central e retirar/revogar a atestacao administrativa por operador oficial; manter o grant de leitura #3478 e o MCP independente. Reverter o release versionado do MCP central se necessario. Sem aprovacao formal e smoke real, status `AWAITING_APPROVAL` e **nao** `CONCLUIDO`.

Esta proposta nao instala nem ativa privilegios antes da decisao humana.