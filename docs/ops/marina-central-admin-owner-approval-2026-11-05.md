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

## Correcao do contrato do MCP browser 5581 (2026-11-06)

A rota administrativa central via endpoint base 5580 ja tem o grant P2 aprovado
na PR #3670; o navegador MCP de porta 5581 herda os schemas e handlers do mesmo
servidor central, mas roda em outra unit systemd. O diagnostico anterior ao
reparo mostrou SHOPVIVALIZ_MARINA_CENTRAL_ADMIN_ENABLED=approved-v1 apenas
na unit base; a unit browser mantinha somente a flag de leitura. Isso fazia o
adaptador stdio do Claude (que prefere 5581) anunciar ferramentas administrativas
sem Marina, embora o 5580 pudesse anuncia-la.

O script scripts/ops/activate-mcp-central-marina-admin.py aceita
repair-browser --pr 3670 para corrigir SOMENTE a propagacao da flag ao
servico browser. A operacao continua condicionada a grant imutavel validado
na API GitHub, estado de atestacao administrativa local com permissao 0600,
flag da unit base exatamente aprovada, codigo instalado correspondente ao
main, host/identidade corretos e destino de drop-in confiavel. Nao cria
novo grant, tunel, segredo, principal nem muda a unit base ou Chrome.
O reparo e idempotente, verifica o ambiente do processo browser depois do
restart e reverte apenas o novo drop-in em caso de falha. O caminho de
ativacao original passa a habilitar tambem o browser; revogacao remove
ambas as flags administrativas e preserva o grant read-only.

Aceite adicional obrigatorio: tools/list autenticado em 5581 e 5580
devem exibir a mesma lista de hosts admin, incluindo Marina; chamada
administrativa canario em Marina deve responder pelo 5581 e continuar
autorizada pelo grant; verificar readback e que outros hosts e MCP
independente nao sofreram regressao. O snapshot ja aberto do app ChatGPT
pode continuar com schema antigo ate a republicacao/atualizacao oficial
do catalogo do app. Nao declarar paridade em agentes so por systemctl
verde ou por hosts_list.
