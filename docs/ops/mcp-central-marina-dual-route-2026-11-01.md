# Duas rotas autorizadas para Marina: MCP central e MCP independente

Data: 2026-11-01. Status: **CODIGO EM REVISAO; NAO ATIVO/NAO CERTIFICADO**.

## Decisao do proprietario
1. O MCP central da ShopVivaliz **tambem deve alcancar Marina** e oferecer, mediante grants efetivos e testes, paridade com as tarefas pertinentes do RDC. A Marina nao deve ficar invisivel na experiencia central.
2. O MCP **independente** da Marina continua obrigatorio. Deve conectar-se diretamente ao ChatGPT pelo OpenAI Secure MCP Tunnel proprio, sem depender de RDC, controlador, laptop ou relay.
3. Sao **duas vias separadas**, nao dois servicos compartilhando tokens. O fato de a via central conseguir observar a Marina nao atesta o MCP independente, nem autoriza shell/sudo, deploy ou qualquer mudanca no host.
4. A VM `shopvivaliz-free-a1` foi excluida e nao deve receber aliases, comandos, tunel ou rotinas.

## Codigo proposto para o MCP central
- `remote-control-mcp/server.py` passa a reconhecer `marina-a1-4c24g` como destino explicito `private_ssh_read_only`, distinto dos hosts antigos e do MCP independente.
- A rota de observacao utiliza unicamente endereco privado Tailscale, conta `ubuntu`, identidade privada do host **ja documentada**, arquivo de host keys pinadas, `BatchMode=yes`, `IdentitiesOnly=yes`, `StrictHostKeyChecking=yes`, sem encaminhamento de agente, configuracao SSH externa, wildcard ou shell root.
- Transportes e destinos **nunca sao fornecidos pelo solicitante**. O MCP central so monta comandos fixos para `host_health` e `service_status` em allowlist. O novo host e excluido dos schemas de `admin_command_run`, `task_submit`, `service_action`, processos, arquivos e logs enquanto nao existirem grants e executores apropriados. Chamadas manuais fora da allowlist tambem sao negadas pelo handler.
- A chave de ativacao `SHOPVIVALIZ_MARINA_CENTRAL_READONLY_ENABLED=approved-v1` fica **desabilitada por padrao**, inclusive depois do merge do codigo. Nao deve ser definida sem PR de seguranca separado, atestacao do autorizador interno e checagens de permissao externa/host. A flag por si so nao constitui aprovacao.
- Esta fase NAO instala nenhuma identidade, nao cria chaves, nao altera unit, nao usa o RDC como relay, nao inicia novos servicos e nao modifica producao.

## Paridade integral futura
A matriz `docs/ops/mcp-rdc-capability-parity-2026-11-01.json` cobre os **29 metodos conectados do RDC**. Marcadores `DIRECT_CANDIDATE`, `PARTIAL_ANALOGUE`, `GENERIC_SURROGATE` e `NOT_IMPLEMENTED` sao comparacoes de catalogo, nao provas. Todos permanecem `NOT_CERTIFIED` ate E2E real.

A evolucao posterior precisa oferecer, por operacao e host, leitura e escrita de arquivos, processo interativo, busca com paginacao, instalacao, gestao de servicos, tarefas duraveis e recursos graficos apropriados, seguindo o contrato de paridade `docs/superpowers/specs/2026-11-01-mcp-admin-cross-repository-parity.md`. Nao transformar o servidor em shell/root arbitrario. Acoes administrativas em Marina exigem PR humano especifico, principal autorizado no host, lease/fencing/CAS, auditoria redigida, idempotencia, testes negativos e rollback. `shopvivaliz-human` e exclusivo da pessoa proprietaria e nao e credencial de automacao.

## Aceite obrigatorio e tarefas pendentes
- CI do PR de codigo: testes de regressao e schema da rota read-only, metodos do RDC e VM extinta.
- Revisao e merge exclusivamente humanos para alteracao de politica/seguranca. Preparar grant de ativacao separado, com recurso `mcp:central:marina`, identidade do operador, capacidades de leitura, expiração e SHA do codigo aprovado; implementar no autorizador local o reconhecimento antes de ativar. O schema do validador atual nao contempla automaticamente esse novo recurso.
- Validar no backend as permissoes reais da chave privada e host key, a identidade da Marina e a conectividade pelo novo handler MCP, sem SSH publico e sem reutilizacao de credenciais. **A chamada central de SSH nesta sessao foi recusada pelo provedor**, logo nao houve validacao positiva dessa rota, e outra rota nao deve ser usada para contornar a negativa.
- Confirmar `hosts_list`, `host_health`, `service_status` pelo MCP autenticado, auditoria, acesso negado para chamadas nao autorizadas, revogacao e reconexao.
- Provisionar e testar MCP independente por sua propria rota oficial; nao declarar paridade a partir apenas da rota central.
- Somente depois implementar rotinas administrativas autorizadas por PR separado e executar E2E/rollback por repositorio.

Estado aceito nesta etapa: `MCP_CENTRAL_MARINA=STAGED_ONLY`, `MCP_MARINA_INDEPENDENTE=NAO_CONECTADO`, `RDC_PARITY=NOT_CERTIFIED`.
