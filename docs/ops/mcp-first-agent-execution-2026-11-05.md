# Execucao MCP primeiro — contrato dos agentes ShopVivaliz (2026-11-05)

<!-- SHOPVIVALIZ_MCP_FIRST_ATTEMPT_V2 -->

**Diretriz expressa do proprietario: MCP administrativo e a PRIMEIRA rota executavel em tarefas operacionais dos quatro hosts.** Nao basta mencionar MCP, conferir que o nome aparece em um catalogo ou consultar `hosts_list`: o agente deve tentar uma ferramenta MCP adequada e autorizada na **sessao atual**, antes de escolher RDC, SSH, PowerShell remoto ou declarar bloqueio. Vale para ChatGPT (dev e atendimento), Codex, Claude Code, Gemini e subagentes com acesso a esse contrato. Este requisito nao concede novos privilegios.

## Fluxo que deve ser seguido em TODA tarefa operacional remota

1. **Descobrir na sessao:** verificar as ferramentas efetivamente expostas ao agente. O MCP central `shopvivaliz-remote-control` opera `always-free-arm-1787907847-26`, `Fred-Win` e `KOCEPSV`. Para `marina-a1-4c24g` consultar o MCP independente `shopvivaliz-marina-admin` e o MCP central apenas nas capacidades publicadas e grants efetivos. Catalogo de hosts nao concede admin automaticamente.
2. **Tentar efetivamente o MCP antes do fallback:** usar ferramenta adequada a acao: `host_health`, `desktop_health`, `admin_command_run`, `task_submit`, `service_action` ou outra disponivel e autorizada; na Marina, `marina_admin_shell` ou ferramenta especifica quando autenticada. Para tarefa longa ou de execucao incerta usar `task_submit` com `request_id` estavel e obter `task_status`. Nao simular chamada.
3. **Guardar evidencia nao secreta:** em `remote_access_attempts`, registrar `host`, `tool`, `timestamp_utc`, `request_id`/`task_id` quando houver, `status`, `evidence` e `next_action`. Classificar honestamente:
   - `MCP_ATTEMPTED_OK`: houve chamada MCP e resultado comprovado;
   - `MCP_ATTEMPTED_FAILED`: houve chamada e erro real, com causa e identificador;
   - `MCP_NOT_EXPOSED`: o runtime desta sessao nao fornece a ferramenta; nunca escrever `MCP_ATTEMPTED`;
   - `MCP_NOT_AUTHORIZED`: falta grant/capacidade efetiva; nao usar outra rota para contornar recusa do provedor;
   - `MCP_NOT_APPLICABLE`: a operacao exige interface/capacidade ausente, com justificativa especifica.
4. **Falha nao e fim:** se ocorrer timeout, rede ou canal desconectado, checar primeiro `task_status` e o estado da operacao para evitar duplicacao; reparar problema recuperavel sem contornar auth, lease ou RBAC. So depois usar a proxima rota **independentemente autorizada** (RDC, SSH privado, GUI etc.) quando necessaria. Um timeout do RDC, por si so, nao justifica encerrar sem testar MCP.
5. **Validar resultado real:** test/readback e UI quando aplicavel. Nunca declarar `BLOCKED_EXTERNAL`, `AGUARDA OPERADOR` ou `NAO FOI POSSIVEL` apenas por erro de um canal enquanto o MCP administrativo adequado estiver disponivel sem tentativa. Nao inventar cobertura, sucesso ou autenticacao.

## Exemplo concreto (PrintNode no KOCEPSV)

Se um agente precisar inspecionar, enviar ou verificar **uma etiqueta autorizada de teste** na fila `ZDesigner server 2`, deve **primeiro** usar o MCP ShopVivaliz central com o host `KOCEPSV`, por `task_submit`/`admin_command_run` ou outra acao especifica que esteja exposta. Em seguida consultar `task_status`, validar fila e eventos de impressao. RDC/GUI sao complementares se necessarios. Se um teste de controle grafico falhar por timeout, isso nao prova que a via MCP falhou. Testes nao devem reimprimir pedidos reais ou repetir jobs de resultado indeterminado.

## Catalogo da sessao ≠ configuracao instalada

`claude mcp list` e `codex mcp list` confirmam apenas registro, **nao** invocacao nem sucesso de comandos. Instrucoes GitHub nao atualizam automaticamente conversas do ChatGPT ja abertas, outras contas, sessoes CLI em andamento ou catalogos de plugins do workspace. Para essas sessoes, usar o MCP nativo de fato publicado na sessao; se ausente, registrar `MCP_NOT_EXPOSED`, diagnosticar registro/perfil/permissao e seguir apenas por alternativa aprovada, sem fingir que tentou.

## Protecoes preservadas

Esta prioridade e de **ordem operacional**, nao novo acesso. MFA, Cloudflare Access, grants P2, escopos por host, aprovacao humana formal, locks/leases/fencing, isolamento de perfis, branch protection e recusas do provedor permanecem obrigatorios. Nao copiar tokens entre hosts, nao forcar autenticacao, nao criar rota paralela para contornar 401/403 e nao executar compra real/teste comercial irreversivel sem escopo autorizado.

<!-- /SHOPVIVALIZ_MCP_FIRST_ATTEMPT_V2 -->
