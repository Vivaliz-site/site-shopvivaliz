# Pedido de ativacao: broker administrativo local da Marina

Estado: PEDIDO DE AUTORIZACAO, nao executado. Vinculado a issue #3152.

## Escopo exato
Host marina-a1-4c24g; principal marina-mcp-service; recurso mcp:marina.
Capacidade deployment_manage; unica operacao olist_browser_restart.
Alvo Vivaliz-site/amazon-returns-safet, unit fixa amazon-returns-olist-erp-browser.service.
Nenhum shell generico, comando arbitrario, escrita em banco, emissao fiscal,
reembolso, envio SAFE-T, alteracao de rede ou mudanca de permissao geral e concedida.

## Ativacao protegida apos aprovacao formal
1. Integrar e validar a PR tecnica da branch fix/marina-mcp-admin-client-20261102.
2. Na manutencao administrativa ja autorizada da Marina, usar release imutavel
   root-owned revisada; nunca editar a release ativa. Registrar SHA e rollback.
3. Instalar somente shopvivaliz-marina-root-broker.socket e .service, com
   socket AF_UNIX local, SO_PEERCRED, usuario HTTP sem root e NoNewPrivileges=yes.
4. Provisionar oficialmente credencial GitHub propria apenas para Contents Read
   e Pull requests Read deste repositorio, protegida 0600 em
   /etc/shopvivaliz/marina-root-broker/github-read-token, sem copiar PAT pessoal,
   cookies, credenciais do backend ou token de outros servicos.
5. Instalar client-grant.json root-owned com PR, HEAD e merge exatos; habilitar
   --enable-approved-admin-broker apenas apos preflight do grant.
6. Exigir lease de manutencao, fencing emitido pelo host, ticket de uso unico,
   auditoria persistente e reconciliacao de resultado antes de qualquer restart.

## Testes e aceite
A implementacao associada foi testada em 15 suites/105 testes, incluindo
fixture real de socket e processo systemd temporario; isso nao e E2E Marina.
Apos implantacao: negativa sem autenticacao/grant, grant expirado/revogado,
peer errado, stale fence/replay e resultado indeterminado sem repetir restart.
Teste positivo deve confirmar identidade Marina, restart da unica unit permitida,
ActiveState=active, efeito reconciliado, logs sem segredos e reconexao.
O JWT Cloudflare continua somente leitura nesta fase; este grant NAO autoriza
exposicao de ferramentas administrativas por JWT nem certifica paridade RDC.

## Rollback
Desabilitar somente socket/broker administrativo, retirar a opcao admin do
MCP pela release/operador oficial e restaurar a release anterior validada.
Preservar daemon MCP de leitura/escrita privada ja autorizado, Cloudflare admin,
site, banco, workers e todos os dados. Nao reutilizar a VM encerrada.

## Decisao humana
Somente shopvivaliz-human emite APPROVED no HEAD final. Integracao pelo fluxo
formal vigente, sem bypass ou aprovacao pelo agente. Apos concessao valida,
nao repetir o pedido para o mesmo escopo. Grants #3514 e #3521 permanecem intactos.
