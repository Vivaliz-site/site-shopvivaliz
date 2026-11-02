# Duas rotas autorizadas para Marina: MCP central e MCP independente

Data: 2026-11-01. Estado observado no backend apos #3474/#3484/#3486/#3491: **rota central read-only anunciada e grant atestado; app ChatGPT ainda sem catalogo de ferramentas atualizado; nenhuma chamada E2E central->Marina certificada**. MCP independente da Marina continua inativo/nao conectado. Paridade RDC: **NOT_CERTIFIED**.

O `hosts_list` autenticado anunciou `marina-a1-4c24g` com `route_enabled=true` (audit `402aa25d-e002-4559-8cb0-dd99d029bb54`). Isso **nao** comprova a execucao remota de `marina_host_health` ou `marina_service_status` a partir do ChatGPT. As ferramentas existem no servidor, mas sua publicacao/Refresh e habilitacao em Workspace Settings > Apps pelo administrador do app continuam pendentes. Nao chamar `host_health` com um host fora do schema congelado nem contornar autorizacao por shell/RDC. Evidencias e status: issue #3152.

## Decisao do proprietario
1. O MCP central da ShopVivaliz **tambem deve alcancar Marina** e oferecer, mediante grants efetivos e testes, paridade com as tarefas pertinentes do RDC. A Marina nao deve ficar invisivel na experiencia central.
2. O MCP **independente** da Marina continua obrigatorio. Deve conectar-se diretamente ao ChatGPT pelo OpenAI Secure MCP Tunnel proprio, sem depender de RDC, controlador, laptop ou relay.
3. Sao **duas vias separadas**, nao dois servicos compartilhando tokens. O fato de a via central conseguir observar a Marina nao atesta o MCP independente, nem autoriza shell/sudo, deploy ou qualquer mudanca no host.
4. A VM `shopvivaliz-free-a1` foi excluida e nao deve receber aliases, comandos, tunel ou rotinas.

## Codigo de leitura restrita implantado no MCP central
- `remote-control-mcp/server.py` reconhece `marina-a1-4c24g` como destino explicito `private_ssh_read_only`, distinto dos hosts antigos e do MCP independente. Duas ferramentas proprias `marina_host_health` e `marina_service_status` existem no codigo protegido, mas o ChatGPT precisa atualizar o snapshot do app para anuncia-las.
- A rota de observacao utiliza unicamente endereco privado Tailscale, conta `ubuntu`, identidade privada do host **ja documentada**, arquivo de host keys pinadas, `BatchMode=yes`, `IdentitiesOnly=yes`, `StrictHostKeyChecking=yes`, sem encaminhamento de agente, configuracao SSH externa, wildcard ou shell root.
- Transportes e destinos **nunca sao fornecidos pelo solicitante**. O MCP central so monta comandos fixos para `host_health` e `service_status` em allowlist. O novo host e excluido dos schemas de `admin_command_run`, `task_submit`, `service_action`, processos, arquivos e logs enquanto nao existirem grants e executores apropriados. Chamadas manuais fora da allowlist tambem sao negadas pelo handler.
- A chave de ativacao `SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1` e **desabilitada por padrao** em novos ambientes. No backend atual, existe grant protegido do proprietario e a rota foi anunciada habilitada. A flag sozinha nao concede autorizacao; a atestacao protegida, escopo e expiracao sao rechecados em cada leitura. Nenhum outro privilegio foi liberado.
- Esta fase NAO usa RDC como relay, NAO ativa escrita em Marina, NAO altera workers/DB/precos e NAO prova o MCP independente. As units do MCP central existentes foram atualizadas pelo deploy oficial dos PRs ja integrados.

## Paridade integral futura
A matriz `docs/ops/mcp-rdc-capability-parity-2026-11-01.json` cobre os **29 metodos conectados do RDC**. Marcadores `DIRECT_CANDIDATE`, `PARTIAL_ANALOGUE`, `GENERIC_SURROGATE` e `NOT_IMPLEMENTED` sao comparacoes de catalogo, nao provas. Todos permanecem `NOT_CERTIFIED` ate E2E real.

A evolucao posterior precisa oferecer, por operacao e host, leitura e escrita de arquivos, processo interativo, busca com paginacao, instalacao, gestao de servicos, tarefas duraveis e recursos graficos apropriados, seguindo o contrato de paridade `docs/superpowers/specs/2026-11-01-mcp-admin-cross-repository-parity.md`. Nao transformar o servidor em shell/root arbitrario. Acoes administrativas em Marina exigem PR humano especifico, principal autorizado no host, lease/fencing/CAS, auditoria redigida, idempotencia, testes negativos e rollback. `shopvivaliz-human` e exclusivo da pessoa proprietaria e nao e credencial de automacao.

## Aceite obrigatorio e tarefas pendentes
- CI do PR de codigo: testes de regressao e schema da rota read-only, metodos do RDC e VM extinta.
- Grant read-only do recurso `mcp:central:marina` ja foi aprovado por `shopvivaliz-human` e atestado no backend via fluxo oficial. **Novas capacidades** e alteracoes de politica ou seguranca continuam exigindo PR de autorizacao especifica, permissao real, revisao humana e isolamento de host.
- Validar ainda pelo **conector ChatGPT atualizado** a chamada efetiva a Marina com identidade pinada, rota SSH privada e negativa de ferramenta fora da allowlist; `hosts_list` sozinho nao prova conectividade SSH E2E. Nao usar outro meio para contornar recusa do provedor.
- Confirmar `hosts_list`, `host_health`, `service_status` pelo MCP autenticado, auditoria, acesso negado para chamadas nao autorizadas, revogacao e reconexao.
- Provisionar e testar MCP independente por sua propria rota oficial; nao declarar paridade a partir apenas da rota central.
- Somente depois implementar rotinas administrativas autorizadas por PR separado e executar E2E/rollback por repositorio.

Estado verificado nesta etapa: `MCP_CENTRAL_MARINA=READONLY_ROUTE_FLAG_ENABLED_E2E_NOT_TESTED`, `CHATGPT_APP_CATALOG=REFRESH_REQUIRED`, `MCP_MARINA_INDEPENDENTE=NAO_CONECTADO`, `RDC_PARITY=NOT_CERTIFIED`.
