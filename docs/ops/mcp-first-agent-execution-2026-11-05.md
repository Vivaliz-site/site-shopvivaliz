<!-- OWNER_EXISTING_CREDENTIAL_DISCOVERY_20261107 -->
## Credenciais existentes: descoberta obrigatoria antes de pedir ao operador

Ao encontrar erro de login, token, RBAC ou conexao, nao presumir credencial ausente. Usar primeiro o MCP administrativo realmente exposto **quando a operacao envolver host e a rota for aplicavel**; em caso contrario registrar `MCP_NOT_APPLICABLE` ou `MCP_NOT_EXPOSED` e investigar os provedores autorizados diretamente; consultar o runbook de hosts e os workflows GitHub existentes (em especial `.github/workflows/shopvivaliz-remote-access.yml`). Consultar **somente metadados e nomes** de secrets na conta/host autorizados; invocar probe read-only pela via oficial que ja possui credencial, e distinguir claramente `present`, `valid`, `scope_verified`, `read_only` e `write_authorized`. Se o provedor retornar 401/403, nao contornar negativa com outro token/conta ou transporte. Fluxos administrativos P2 continuam sujeitos a revisao e controles do provedor.

Exemplo testado: run GitHub Actions [42429462524](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/42429462524), `secure_mcp_cloudflare_access_probe`, passou com `CLOUDFLARE_ACCESS_APPS_READ=true` e `CLOUDFLARE_ACCESS_SERVICE_TOKENS_READ=true`. O sinal `CLOUDFLARE_ACCESS_MANAGED_OAUTH_CAPABLE=true` e **heuristico** (derivado da leitura de apps, sem verificar `oauth_configuration`), nao e prova de OAuth gerenciado funcional nem DCR ativo. Isso e evidencia de credencial de **leitura**, nao de poder para editar Access app. Depois do probe, consultar PR #3810 (inspecao da aplicacao exata), e o script de reparo ja integrado da PR #3808. Nao usar Opera como dependencia e nao pedir consentimento repetido.
<!-- /OWNER_EXISTING_CREDENTIAL_DISCOVERY_20261107 -->

# Execucao MCP primeiro — contrato dos agentes ShopVivaliz (2026-11-05)

<!-- SHOPVIVALIZ_MCP_FIRST_ATTEMPT_V2 -->

**Diretriz expressa do proprietario: MCP administrativo e a PRIMEIRA rota executavel em tarefas operacionais dos quatro hosts.** Nao basta mencionar MCP, conferir que o nome aparece em um catalogo ou consultar `hosts_list`: o agente deve tentar uma ferramenta MCP adequada e autorizada na **sessao atual**, antes de escolher RDC, SSH, PowerShell remoto ou declarar bloqueio. Vale para ChatGPT (dev e atendimento), Codex, Claude Code, Gemini e subagentes com acesso a esse contrato. Este requisito nao concede novos privilegios.

## Fluxo que deve ser seguido em TODA tarefa operacional remota

1. **Descobrir na sessao:** verificar as ferramentas efetivamente expostas ao agente. O MCP central `shopvivaliz-remote-control` publica os quatro hosts ativos: `always-free-arm-1787907847-26`, `Fred-Win`, `KOCEPSV` e `marina-a1-4c24g` (esta ultima via transporte privado com grant administrativo vigente). O Orquestrador expõe as mesmas rotas; o MCP independente `shopvivaliz-marina-admin` complementa o central para capacidades próprias da Marina. **Usar `admin_command_run`, `task_submit` ou `service_action` na Marina somente se o schema real da sessao e os grants vigentes permitirem;** nunca inferir admin apenas de `hosts_list`. O host excluido `shopvivaliz-free-a1` nao e destino e nao deve ser acessado. Catalogo de hosts nao concede admin automaticamente.
2. **Tentar efetivamente o MCP antes do fallback:** usar ferramenta adequada a acao: `host_health`, `desktop_health`, `admin_command_run`, `task_submit`, `service_action` ou outra disponivel e autorizada; na Marina, `marina_admin_shell` ou ferramenta especifica quando autenticada. Para tarefa longa ou de execucao incerta usar `task_submit` com `request_id` estavel e obter `task_status`. Nao simular chamada.
3. **Guardar evidencia nao secreta:** em `remote_access_attempts`, registrar `host`, `tool`, `timestamp_utc`, `request_id`/`task_id` quando houver, `status`, `evidence` e `next_action`. Classificar honestamente:
   - `MCP_ATTEMPTED_OK`: houve chamada MCP e resultado comprovado;
   - `MCP_ATTEMPTED_FAILED`: houve chamada e erro real, com causa e identificador;
   - `MCP_NOT_EXPOSED`: o runtime desta sessao nao fornece a ferramenta; nunca escrever `MCP_ATTEMPTED`;
   - `MCP_NOT_AUTHORIZED`: falta grant/capacidade efetiva; nao usar outra rota para contornar recusa do provedor;
   - `MCP_NOT_APPLICABLE`: a operacao exige interface/capacidade ausente, com justificativa especifica.
4. **Falha nao e fim:** se ocorrer timeout, rede ou canal desconectado, checar primeiro `task_status` e o estado da operacao para evitar duplicacao; reparar problema recuperavel sem contornar auth, lease ou RBAC. So depois usar a proxima rota **independentemente autorizada** (RDC, SSH privado, GUI etc.) quando necessaria. Um timeout do RDC, por si so, nao justifica encerrar sem testar MCP.
5. **Validar resultado real:** test/readback e UI quando aplicavel. Nunca declarar `BLOCKED_EXTERNAL`, `AGUARDA OPERADOR` ou `NAO FOI POSSIVEL` apenas por erro de um canal enquanto o MCP administrativo adequado estiver disponivel sem tentativa. Nao inventar cobertura, sucesso ou autenticacao.

## Exemplo real: Marina, painel admin e fila comercial (14/11/2026)
<!-- MCP_ADMIN_CAPABILITY_BEFORE_BLOCK_20261114 -->
**Falha de classificacao que nao deve se repetir:** o agente viu `/admin/amazon-margin.php` redirecionar para `/auth/login.php` e inferiu que precisava encerrar ou delegar toda a execucao ao proprietario. Esse teste mostra apenas o estado de autenticacao daquele perfil web; NAO prova que `marina-a1-4c24g` esteja inacessivel por MCP. Em 14/11/2026 a conexao MCP central e Orquestrador executaram `hostname -s; id -un` como `ubuntu`, exit 0. O MCP independente via `scripts/marina_mcp_agent_client.py` publicou nove ferramentas, incluindo `marina_admin_shell`, e executou `id -un` com `as_user=ubuntu`, exit 0. A validade desses testes e temporal: revalidar o schema, grant e runtime em cada tarefa.

1. **Qual camada falhou?** Distinguir: `HOST_MCP_ADMIN` (execucao no host), `APP_AUTH` (sessao/login/CSRF do painel), `COMMERCIAL_AUTH` (aprovacao/grant e escopo da mutacao), `JOB_QUEUE` (pedido validamente registrado), `PROVIDER_READBACK` (resultado ERP/Amazon). Nunca colapsar em `SEM_ACESSO` quando o host MCP estiver operacional.
2. **Operacao real via MCP:** descobrir funcoes e tentar `admin_command_run(host="marina-a1-4c24g",command="hostname -s; id -un",timeout=5)` ou a equivalente do Orquestrador. Para comando demorado, usar `task_submit` com `request_id` estavel, acompanhar `task_status` ate terminal e provar `exit_code=0`. Se a sessao tiver apenas cliente shell autorizado, usar o MCP independente com `python3 scripts/marina_mcp_agent_client.py preflight`, `tools` e `call marina_admin_shell --args '{"command":"id -un","as_user":"ubuntu"}'`; nunca imprimir secrets nem inferir grant de um catalogo.
3. **Buscar a via oficial para a tarefa:** consultar o processo, codigo/runbook, fila e estado remoto, antes de pedir acesso manual. Para a margem Amazon: consultar journal do publisher e a operacao administrativa oficial `target_margin_apply`; `php scripts/amazon-margin/run.php --next` e o consumidor idempotente de pedidos ja autorizados, NAO criador deles. Executar worker que pode mutar precos somente quando o pedido e o grant legitimos ja existirem. Uma resposta `{"state":"audit_queue_empty","commercial_request":{"state":"empty"}}` prova executor funcional sem job disponivel.
4. **Retomar sem confundir bloqueios:** se existe API/CLI administrativa *oficial, documentada, aprovada e disponivel* para realizar a acao, utiliza-la pelo MCP, com lease/fencing e readback real. Se nao existe rota oficialmente autorizada para criar o pedido sem a sessao do aplicativo, registrar `HOST_MCP_ADMIN=OK` e `APP_AUTH/PUBLICATION_REQUEST=PENDENTE`, indicar a precondicao especifica e continuar outras etapas independentes permitidas. Nao declarar o MCP indisponivel nem chamar uma simulacao de publicacao real.
5. **Preservar separacao de poderes:** acesso root/ubuntu por MCP nao equivale a sessao admin do site, token do Seller Central ou autorizacao de alteracao comercial. Proibido usar shell para forjar sessao, CSRF, cookies, identidade, inserir job comercial direto em banco ou enviar PUT/PATCH fora do mecanismo de publicacao validado. Nunca usar outro canal para contornar 401/403, MFA, CAPTCHA, RBAC, grants P2, controles do provedor ou recusa expressa. Registrar os IDs de auditoria e fazer GET/GUI pos-escrita quando houver alteracao autorizada.

**Interpretacao correta das provas de 14/11:** `marina_admin_shell` respondeu `ubuntu/uid1001`; `marina_browser_navigate` acessou o painel mas foi redirecionado ao login; o worker oficial terminou `commercial_request.empty`; o banco mostrava apenas tres pedidos historicos finalizados. Portanto: `MCP_HOST_ADMIN=COMPROVADO`, `APP_SESSION_NO_PERFIL_TESTADO=NAO_AUTENTICADA`, `NEW_COMMERCIAL_REQUEST=INEXISTENTE`, `PRICE_PUBLICATION=NÃO_COMPROVADA`. Nao afirmar `APTO` para novos precos sem readback real.
<!-- /MCP_ADMIN_CAPABILITY_BEFORE_BLOCK_20261114 -->

## Tratamento de limite foreground e retomada duravel (2026-11-06)

<!-- MCP_FOREGROUND_DURABLE_ROUTING_V1 -->
**Regra executavel para todos os agentes:** `foreground_budget_exceeded_use_task_submit` e uma rejeicao do modo sincrono (`durable=false` com timeout acima do limite do adaptador, atualmente **5 segundos**). **Nao** e prova de senha ausente, plugin desativado, 403 de autorizacao ou limite mensal de tokens. Corrigir a **forma da chamada** em vez de encerrar o objetivo.

1. Comando comprovadamente curto: `admin_command_run` com `timeout <= 5` e `durable=false`, quando a ferramenta e o grant dessa sessao permitirem.
2. Operacao longa, incerta, administrativa ou que precise persistir: preferir **desde a primeira chamada** `task_submit(host, command, timeout, request_id)`. Usar `request_id` estavel para a mesma intencao, sem duplicar efeitos. A API pode tambem promover `admin_command_run(durable=true)` para fila; nao exigir execucao imediata.
3. Se ocorrer exatamente `foreground_budget_exceeded_use_task_submit`, **e a chamada anterior tiver sido recusada antes de ser aceita**, submeter pela fila autorizada com a mesma intencao, sem mudar host/grant/autenticacao nem reexecutar comando ja iniciado. Se o retorno for timeout, desconexao ou `controller_response_indeterminate`, **nao** resubmeter cegamente: reconciliar `request_id`, `task_id` e auditoria primeiro.
4. Apos o recibo `queued`, consultar `task_status` com verificacoes finitas ate estado terminal; exigir `state=succeeded`, `exit_code=0` **e readback especifico**. Recibo de fila, `running` ou timeout do chat nao significam conclusao. Persistir checkpoint e retomar na proxima execucao autorizada quando a tarefa nao puder ser acompanhada nesta sessao.
5. `MCP_NOT_AUTHORIZED`, 401/403 ou recusa expressa da plataforma **nao** sao erros de foreground. Nao usar `task_submit` ou outro transporte para contornar negativa de seguranca; preservar MFA, RBAC, grants, leases e aprovacoes humanas.
6. Permissao administrativa de **todas as ferramentas** no Admin Console, permissao de **execucao/confirmacao** da conta ChatGPT e **limite do transporte** sao controles distintos. Nao declarar falta de permissao por uma rejeicao foreground nem declarar `full_access` alterado sem readback oficial. Um 404 do gerenciador de permissao e falha desse controle, nao revogacao automatica das ferramentas MCP.

**Evidencia E2E (06/11/2026):** no backend, os MCPs Central e Orquestrador executaram o mesmo teste inocuo de escrita/limpeza pela fila, `task_status.state=succeeded`, `exit_code=0`, `MCP_WRITE_PROBE=PASS`; tasks `492ac6e6-7230-4c3e-ba70-64daefe791e9` e `df200811-6277-438e-9f54-b6ca9978a4bc`. Esse teste comprova a rota de escrita, nao autorizacao universal para qualquer acao sensivel.
<!-- /MCP_FOREGROUND_DURABLE_ROUTING_V1 -->

## Exemplo concreto (PrintNode no KOCEPSV)

Se um agente precisar inspecionar, enviar ou verificar **uma etiqueta autorizada de teste** na fila `ZDesigner server 2`, deve **primeiro** usar o MCP ShopVivaliz central com o host `KOCEPSV`, por `task_submit`/`admin_command_run` ou outra acao especifica que esteja exposta. Em seguida consultar `task_status`, validar fila e eventos de impressao. RDC/GUI sao complementares se necessarios. Se um teste de controle grafico falhar por timeout, isso nao prova que a via MCP falhou. Testes nao devem reimprimir pedidos reais ou repetir jobs de resultado indeterminado.

## Catalogo da sessao ≠ configuracao instalada

`claude mcp list` e `codex mcp list` confirmam apenas registro, **nao** invocacao nem sucesso de comandos. Instrucoes GitHub nao atualizam automaticamente conversas do ChatGPT ja abertas, outras contas, sessoes CLI em andamento ou catalogos de plugins do workspace. Para essas sessoes, usar o MCP nativo de fato publicado na sessao; se ausente, registrar `MCP_NOT_EXPOSED`, diagnosticar registro/perfil/permissao e seguir apenas por alternativa aprovada, sem fingir que tentou.

## Protecoes preservadas

Esta prioridade e de **ordem operacional**, nao novo acesso. MFA, Cloudflare Access, grants P2, escopos por host, aprovacao humana formal, locks/leases/fencing, isolamento de perfis, branch protection e recusas do provedor permanecem obrigatorios. Nao copiar tokens entre hosts, nao forcar autenticacao, nao criar rota paralela para contornar 401/403 e nao executar compra real/teste comercial irreversivel sem escopo autorizado.

<!-- /SHOPVIVALIZ_MCP_FIRST_ATTEMPT_V2 -->
