<!-- AGENT_ACCESS_EXHAUSTION_V2_20261030 -->
## Obrigacao de esgotamento de rotas autorizadas antes de interromper

Para toda tarefa ShopVivaliz acionada por "Retome", "Continue", "Prossiga" ou "nao pare ate concluir", cada agente deve **executar a acao disponivel e continuar**: diagnostico isolado, PR aberto, falha de login, timeout ou uma ferramenta inoperante nao constituem conclusao. Identificar primeiro o host e a topologia vigentes no main; registrar `remote_access_attempts` nao secreto por host/tarefa e consultar as capacidades *realmente expostas*.

**Rotas a investigar e tentar quando aptas ao escopo**, com fallback justificado: (1) ShopVivaliz Remote Control MCP, suas tarefas duraveis, inspecao e navegador grafico; (2) Remote Desktop Commander (RDC 1 e RDC 2, cada um com sessao/permissoes proprias) na Marina, backend, laptop Fred-Win ou KOCEPSV; (3) SSH privado autenticado pela VCN ou Tailscale, usando apenas credenciais ja provisionadas por mecanismo oficial; (4) tuneis privados/reverse SSH ou Cloudflare explicitamente documentados, sem confundir tunel de aplicacao MEI com MCP administrativo; (5) GUI autorizada por perfil/sessao (browser bridge, RustDesk) quando houver interface; (6) OCI Bastion/console/Run Command e GitHub Actions como mecanismos de recuperacao ou deploy **apenas nos respectivos fluxos permitidos**. A indisponibilidade de um conector nao invalida os demais; a existencia de uma rota tambem nao garante permissao para toda acao.

Para cada tentativa aplicavel, documentar horario UTC, host/identidade, canal, teste, permissao efetiva, resultado, causa, correcao, evidencias, proximo fallback e classificacao (`PASS`, `FAILED_REPAIRABLE`, `NOT_PROVISIONED`, `DENIED`, `NOT_APPLICABLE`). Nao registrar segredos, cookies, OTP ou tokens. Recuperar sessao autentica existente sem copiar credenciais entre perfis. Resolver erros reparaveis, testar novamente de modo finito/idempotente, seguir para a proxima rota autorizada e preservar estado `RUNNING/NAO_APTO` enquanto houver trabalho executavel. **So declarar `BLOCKED_EXTERNAL`** quando houver prova objetiva de impedimento externo apos esgotar e justificar todas as rotas aplicaveis; registrar proprietario do bloqueio e acao segura seguinte.

**Limites superiores inalterados:** recusas explicitas da plataforma, RBAC, sudo, MFA, CAPTCHA, leases/fencing, branch protection, aprovacao humana para estoque/precos, quotas e controles de gasto nao podem ser burlados usando outro MCP, RDC, SSH, tunnel, navegador, identidade, workflow ou host. A VM `shopvivaliz-free-a1` foi retirada e nunca pode ser alvo. Marina e producao e backend e controlador; confirmar ao vivo. Para correcoes de codigo: RED, causa raiz, GREEN, CI protegido, merge autorizado, deploy por Master Pipeline, SHA publico exato e E2E GUI com trace/persistencia. Sem isso nao marcar `CONCLUIDO/APTO`. Esta regra reforca, nao revoga, `docs/knowledge/host-access.md`, `GEPETO-POLICY.md` e as politicas vigentes.
<!-- /AGENT_ACCESS_EXHAUSTION_V2_20261030 -->

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Verificacao posterior, prevalente (2026-10-29):** o MCP central do backend foi corrigido e implantado (PRs #3391/#3392): `hosts_list` agora divulga **somente backend, Fred-Win e KOCEPSV**; `shopvivaliz-free-a1` foi excluida e rejeitada. Marina segue fora da allowlist MCP central; seu MCP proprio OpenAI esta inativo, embora haja RDC direto, SSH privado verificado e tunel SSH do **MEI** ativo para Marina (HTTP 200). O PR #3395 bloqueou 20 jobs legados na origem, sem certificar as funcoes substitutas. O controlador 24x7 ainda exige reconciliacao sob lease valido e o runner `mei-ci-a1` permanece sem registro. **Toda afirmacao mais antiga de "MCP central ainda inclui a origem" neste arquivo e um snapshot anterior, agora superado.** Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **INSTRUÇÃO OPERACIONAL ATUAL PARA TODOS OS AGENTES (2026-10-29):** antes de qualquer execução ou `Retome`, ler no `main` `docs/knowledge/current-topology-and-access-2026-10-29.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md` e o runbook do módulo; registrar SHA/checkpoint e validar identidade e autorização reais. **Produção web/DB/workers = `marina-a1-4c24g` (OCI ESPOSA), via RDC direto comprovado. Backend/controller/browser/MCP central = `always-free-arm-1787907847-26` (OCI PRINCIPAL). `shopvivaliz-free-a1` foi excluída e NÃO é destino válido; nunca reconectar, recriar ou substituir seu alias por Marina.** O MCP central ainda contém inventário antigo e não alcança Marina; seu MCP independente/túnel ainda não está ativo/registrado. SSH privado, RDC 2, sudo e OCI exigem autorização/prova por canal. Workflows/scripts antigos que apontem para a origem não devem ser executados, mesmo que relatórios históricos os recomendem; corrigir de forma revisada, testar e validar antes do uso.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

<!-- OCI_DUAL_ACCOUNT_CANONICAL_V1 -->
## OCI: duas contas Oracle Cloud (PRINCIPAL / ESPOSA)

Antes de executar operacoes Oracle Cloud, leia obrigatoriamente [docs/ops/oci-dual-account-access.md](docs/ops/oci-dual-account-access.md). Essa e a referencia canonica de arquitetura, configuracao, nomes dos GitHub Actions secrets, comandos permitidos, validacao e limites operacionais. Ambas as contas foram autenticadas de forma independente via GitHub Actions (run 38908995411): OCI_PRINCIPAL_AUTH=PASS e OCI_ESPOSA_AUTH=PASS. Nao confundir esse resultado com acesso OCI nativo pelo MCP do backend, ainda nao comprovado. Nao copiar, imprimir, registrar ou publicar chaves privadas. Preservar isolamento de tenancies, IAM e aprovacoes para mudancas de recursos.
<!-- /OCI_DUAL_ACCOUNT_CANONICAL_V1 -->

<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
## REGRA ABSOLUTA: todo erro identificado exige correcao real e validacao

Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, bloqueio tecnico, resultado incorreto ou problema de validacao detectado **nao pode ser ignorado, apenas documentado ou encerrado sem remediacao**.

Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

**Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicitos e gate de I/O. Se a tarefa/automacao for `SOMENTE LEITURA`, **nao executar mutacoes**: registrar e encaminhar cada defeito ao mecanismo de reparo autorizado ja existente. Proibido contornar recusas por conectores, shell ou agentes alternativos ou duplicar agentes, monitorias, automacoes, tarefas, bindings e PRs.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->
## Gate da Auditoria Extrema V5: E2E UI real, prova em disco e continuidade

Todo agente deve executar integralmente `docs/quality/AUDIT_UI_EVIDENCE_INTEGRITY_V2.md`: inventariar tela/controles/casos reais, percorrer cada jornada via UI grafica, capturar trace/midia real, corrigir via RED/GREEN/regressao, reauditar em sessao independente e validar UI novamente depois do deploy. O certifier deve ler hashes de arquivos fisicos e devolver `AUDIT_VERDICT=APTO` no mesmo SHA/release/ambiente antes de marcar tarefa `CONCLUIDO`. Nao aceitar afirmacoes/flags, screenshot isolado, API/CLI/SQL ou headless-only como prova. Manter checkpoint `RUNNING` com `next_action` e retomada controlada enquanto NAO_APTO; nao abandonar E2E por timeout, PR, merge, CI, falha de browser ou limite de turno. Respeitar ownership, seguranca e controles de custo. `BLOCKED_EXTERNAL` exige comprovacao real, nao e APTO.
<!-- /AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
## GATE INEGOCIAVEL: Auditoria Extrema V5 executada pela UI real

**Ordem explicita do proprietario (2026-10-13), vinculante a GPT, Claude, Gemini, Codex, subagentes, orquestradores e revisores:** toda auditoria extrema/completa/V5/aptidao de sistema que tenha UI **DEVE ser executada pelo proprio agente atraves da interface grafica real**. O auditor nao pode substituir a UI por API, terminal, SQL, inspecao de codigo, logs, mocks, healthcheck, testes unitarios, Playwright exclusivamente headless nem screenshot estatico. Esses meios sao auxiliares para investigar/reconciliar, jamais prova suficiente do funcionamento que o usuario vivencia.

1. **UI primeiro, sem atalhos:** inventariar telas, jornadas, perfis, estados e casos reais; abrir a interface publicada no host/perfil autorizado; navegar, clicar, preencher, filtrar, salvar/submeter dentro das permissoes; observar resposta visual, erros e efeitos; recarregar, sair e voltar para provar persistencia. Para Amazon Seller Central, Mercado Livre e paineis externos, auditar os casos e transicoes acessiveis na UI oficial, um a um conforme escopo, sem declarar sucesso por API somente.
2. **Evidencia obrigatoria por jornada:** registrar ambiente/URL, SHA/release quando aplicavel, sessao autorizada sem segredos, timestamp, passos efetivamente executados, capturas/trace/video, estado antes/depois, erros de console/rede, reload/revisita e reconciliacao independente dos efeitos duraveis. Screenshot isolado, abertura de pagina, resposta HTTP 200 ou teste simulado nao certificam jornada.
3. **Auditoria inclui reparo:** ao encontrar falha corrigivel no escopo autorizado, investigar causa raiz, corrigir, prevenir recorrencia, executar testes e **repetir o mesmo fluxo pela UI real apos correcao/deploy**, com evidencia independente. Nao encerrar no diagnostico, em PR ou em teste apenas de backend.
4. **Gate fail-closed, sem falso APTO:** qualquer fluxo com UI nao percorrido e comprovado permanece `NAO_VALIDADO_UI` / `NAO_APTO`; e proibido declarar `APTO`, `CONCLUIDO`, 100% ou verde. `browser.required=false` ou `journey.ui=false` nao podem ser usados para ocultar UI existente. Se realmente nao existir interface para o componente auditado, registrar inventario negativo de UI, justificativa e evidencias; validar os caminhos reais nao visuais pertinentes. Ausencia/falha transitoria de browser exige reparo/fallback autorizado antes de `BLOCKED_EXTERNAL`.
5. **Seguranca:** usar browser/desktop/MCP/RDC nos hosts e perfis previstos nos runbooks, com ownership/leases, controles de autorizacao e MFA; preservar sessoes. A auditoria nao autoriza exclusao, faturamento real, modificacao irreversivel ou acesso a segredo. Quando a acao terminal nao puder ser realizada com seguranca, testar ate o limite seguro e registrar exatamente a cobertura pendente; nunca inventar sucesso.

Fonte complementar: `AUDIT_POLICY.md`, `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md` e `docs/quality/AUDIT_ABSOLUTE_GATE_V1.md` do repositorio governado. **Este gate prevalece sobre instrucoes mais brandas que permitam certificar por API ou parecer do agente.**
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- RESUME_EXECUTION_GATE_V1 -->
## Execução obrigatória ao receber Retome / Continue / Prossiga / Siga

Para qualquer executor, subagente ou ferramenta neste projeto: carregue `AGENTS.md` e a documentação aplicável do `main`; persista prova `rules_loaded` (SHA/ref/data). `Retome` significa **executar agora** a próxima ação do checkpoint, não só relatar status nem pedir aprovação genérica já fornecida. Preserve sessões e ownership; corrija falhas, tente rotas seguras alternativas, teste e valide o objetivo original antes de encerrar. Continue como `RUNNING` com `next_action` específico enquanto existir caminho autorizado; `CONCLUIDO` exige evidência E2E fresca, e `BLOCKED_EXTERNAL` exige causa externa, alternativas exauridas e condição exata para retorno. Quando existir, use `agent_task_state.py terminal --task <task_id>` antes de declarar conclusão; ACK, erro transitório, PR, merge, `Pensando` e mensagem intermediária não são término. Não contorne MFA, CI, approvals ou controles. Não prometa continuidade fora de sessão sem checkpoint/controlador real. O agente delegado segue esta mesma regra.
<!-- /RESUME_EXECUTION_GATE_V1 -->


<!-- CREDENTIAL_REPROMPT_GATE_V1 -->
## Credencial ChatGPT ja provisionada: preflight obrigatorio antes de solicitar senha

Para `atendimento@shopvivaliz.com.br` ou `dev@shopvivaliz.com.br`, o agente
(GPT, Claude, Codex, Gemini, subagentes e controladores) deve, **antes de
solicitar ao usuario senha, OTP ou novo provisionamento**, confirmar a sessao
correta e executar na VM backend, via MCP autorizado, a verificacao somente
de metadados:

`python3 scripts/chatgpt_credential_preflight.py --session atendimento`
(ou `--session dev` para o perfil Dev).

- `saved_entry=PRESENT` / `DENY_EXISTING_CREDENTIAL`: a senha ja possui
  referencia salva no perfil dedicado. **Nao solicitar senha novamente**;
  primeiro reparar a integracao navegador/GNOME Keyring e tentar o
  preenchimento **nativo e autorizado**, sem ler ou exportar o segredo.
- `saved_entry=NOT_VERIFIED` / `DENY_ABSENCE_NOT_PROVEN`: perfil
  inacessivel, banco ocupado, erro de probe ou ausencia nos locais consultados
  **nao** prova que a credencial nao existe. Recuperar transporte/MCP,
  consultar as demais fontes protegidas previstas no runbook e continuar.
- O probe jamais concede permissao para pedir senha: nova intervencao humana
  somente apos evidencia independente de credencial ausente/revogada/invalida,
  ou desbloqueio/consentimento/MFA humano obrigatorio pelo fluxo oficial.
- Um registro salvo **nao prova** que a senha e valida ou que o ChatGPT esta
  autenticado. Validar cada sessao pelo email exato em `/api/auth/session`.
- Nunca copiar senha do iPhone ou navegador para terminal, Git, chat, logs,
  processo ou outro perfil. Nunca tentar descriptografar/mostrar a credencial.

Evidencia obrigatoria do preflight: apenas status, sessao verificada, timestamp
e acao permitida, **sem** qualquer valor secreto. Se a VM nao estiver
acessivel, registrar `SOURCE_NOT_VERIFIED`; nao converter isso em
`CREDENTIAL_ABSENT` nem pedir novamente a mesma senha.
<!-- /CREDENTIAL_REPROMPT_GATE_V1 -->

<!-- CREDENTIAL_REPROMPT_FAST_MCP_V1 -->
### Execucao rapida do preflight pelo Claude/MCP (evitar fila e timeout)

Na VM backend, executar o preflight pelo **ShopVivaliz Remote Control**
`admin_command_run` com os argumentos explicitos abaixo. O comando e
somente leitura e retorna em chamada inline, sem `task_submit`, `Monitor`,
`task_status` repetido ou `Bash` local:

- `host="always-free-arm-1787907847-26"`
- `command="python3 /home/ubuntu/shopvivaliz-claude-workspace/site-shopvivaliz/scripts/chatgpt_credential_preflight.py --session atendimento"`
- `timeout=5`
- `durable=false`

Para Dev, trocar exclusivamente `--session atendimento` por `--session dev`.
O timeout do adaptador pode transformar a solicitacao em tarefa duravel se
esses parametros forem omitidos; **nao** fazer polling longo de credencial.
Se a chamada inline nao for possivel, a fonte fica `NOT_VERIFIED`, nao
`ABSENT`, e continua proibido pedir novamente a senha sem prova.
<!-- /CREDENTIAL_REPROMPT_FAST_MCP_V1 -->


<!-- CHATGPT_LOGIN_GMAIL_WATCH_10_MINUTES_V1 -->
## Login corporativo: monitorar Gmail por ate 10 minutos por tentativa

Quando o agente SOLICITAR ou iniciar realmente um login para
dev@shopvivaliz.com.br ou atendimento@shopvivaliz.com.br, monitorar o
Gmail fredmourao@gmail.com por ate **600 segundos**, iniciando com a
acao oficial sob lease, checando mensagem nova da OpenAI para a conta
exata e terminando ao identificar a mensagem ou expirar o prazo.
Na VM, remote-control-browser-mcp/login_email_watch.py realiza
verificacao **somente de metadados** a cada **15 segundos**, com
identidade exata, prazo fixo, deduplicacao por conta, resultado privado
e leitura sanitizada por browser_auth_tabs. Nenhum codigo e lido,
transmitido ou usado automaticamente para entrar no ChatGPT.
A janela exige **OAuth Gmail readonly independente do backend**;
se ele nao existir, retornar oauth_not_provisioned e NAO fingir que
a monitoria de 10 minutos foi iniciada. No chat com Gmail conectado,
o agente pode consultar os metadados durante a tentativa interativa,
mas NAO prometer acompanhamento depois de encerrar a resposta.
A automacao ChatGPT horaria NAO substitui o acompanhamento curto.
O codigo de e-mail, senha e GNOME TOTP sao etapas separadas;
a pessoa autorizada preenche na tela oficial quando o cliente bloquear
a transferencia automatica, jamais via CDP/shell/clipboard/ponte
alternativa. Preservar browser_session, lease/fencing, controles MFA,
sem pedir novamente secrets. Detalhes no runbook
docs/knowledge/chatgpt-vm-auth.md.
<!-- /CHATGPT_LOGIN_GMAIL_WATCH_10_MINUTES_V1 -->


<!-- REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->
## Gate obrigatorio: esgotar rotas de acesso remoto antes de bloquear

Em `Retome`, `Continue`, `Prossiga`, `Siga` ou qualquer trabalho remoto, **um erro em um unico canal jamais comprova inacessibilidade do host**. Antes de `BLOCKED_EXTERNAL` ou de solicitar ao usuario uma acao que o agente pode executar:
1. Ler `docs/knowledge/host-access.md` no `main` atual e o runbook do repositorio; verificar destino, identidade, privilegios, capacidades expostas e autorizacoes efetivas. Nunca adivinhar que uma rota existe.
2. **Avaliar todas as rotas remotas realmente provisionadas, permitidas e adequadas a acao**, com a prioridade do runbook: (a) ShopVivaliz Remote Control MCP/controlador e ferramentas de host/tarefa/desktop; (b) RDC e RDC 2 para os hosts e capacidades explicitamente autorizados; (c) SSH privado/VCN/Tailscale e tunel/reverse SSH documentado, usando credenciais protegidas existentes; (d) GUI remota por bridge `desktop_*` ou RustDesk self-hosted quando a tarefa exigir interface; (e) OCI Bastion, OCI Compute Run Command, console serial e GitHub Actions/control plane somente para bootstrap, recuperacao ou reparo, conforme suas regras. Usar outras rotas somente se estiverem documentadas e autorizadas.
3. Para cada rota aplicavel, provar disponibilidade, autenticacao/autorizacao, conectividade, identidade do host e capacidade da operacao; diagnosticar e reparar falhas tecnicas que estejam ao alcance, testar novamente com tentativas limitadas e idempotentes e passar ao proximo caminho quando apropriado. Nao confundir canal de GUI com shell nem rotas de recovery com transporte normal de runtime.
4. Registrar checkpoint nao secreto `remote_access_attempts` por host e tarefa: canal, objetivo, permissao, teste, resultado, causa, reparo, proximo fallback e evidencia com instante. Canal nao disponivel deve ser classificado `NOT_PROVISIONED` com evidencia, nao `TRIED`.
5. **So declarar host inacessivel/bloqueio externo depois de examinar e esgotar todos os caminhos adequados, seguros, tecnicamente viaveis e autorizados**, justificando cada nao utilizacao. Enquanto houver rota executavel, manter `RUNNING` e continuar a tarefa. Pedir aprovacao apenas quando obrigatoria, indicando a permissao exata.

Limites: nao contornar MFA, CAPTCHA, RBAC, protecoes de branch, limites administrativos ou aprovacoes; nao procurar/extrair secrets nem tentar senhas indiscriminadamente; nao habilitar SSH publico ou usar IP publico como fallback indevido. Browser de agente ShopVivaliz permanece na VM backend com perfil isolado; Windows e apenas host de tarefas/GUI autorizadas, nao fallback de navegador. Sem loops ilimitados nem alegacao de acesso nao testado.
<!-- /REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->

<!-- MANDATORY_AGENT_RULES_READ_GATE_V1 -->
## Preflight obrigatorio antes de Retome ou qualquer acao

Antes da PRIMEIRA ferramenta que altera estado e sempre que receber `Retome`, `Continue`, `Prossiga` ou `Siga`, todo agente deve LER EFETIVAMENTE, na revisao `main` atual, `AGENTS.md`, `docs/knowledge/atendimento-chatgpt-bootstrap.md`, `docs/knowledge/host-access.md`, `docs/knowledge/README.md`, `docs/knowledge/agent-rules.md` e a documentacao do modulo. Ler somente uma mensagem de chat ou citar nomes de arquivos nao comprova leitura. Checar SHA/revisao e guardar checkpoint nao secreto `rules_loaded` contendo caminho, ref, SHA, timestamp e agente/sessao; se nao houver ferramenta de leitura, registrar `RULES_NOT_LOADED` e recuperar acesso antes de alterar estado. A leitura das regras e precondicao (fail closed) para mutacoes, mas nao impede diagnosticos read-only seguros. Nao declarar `rules_loaded` sem evidencias reais de leitura. Toda retomada revalida revisao e recarrega se mudou; obedecer regras vigentes sem fingir que arquivos em PR ja estao em `main`.
<!-- /MANDATORY_AGENT_RULES_READ_GATE_V1 -->

<!-- EXHAUST_AUTHORIZED_PATHS_BEFORE_BLOCKED_V1 -->
## Bloqueio somente apos esgotamento comprovado dos caminhos viaveis

**PROIBIDO declarar `BLOCKED_EXTERNAL`, `IMPEDIDO`, `INCONCLUSIVO` como encerramento ou pedir ao usuario que resolva falha tecnica enquanto existir qualquer acao segura, autorizada e tecnicamente viavel.** Antes de informar bloqueio:
1. Confirmar estado e causa raiz com logs, codigo, checkpoints, host e testes; distinguir falha transitoria, defeito local, permissao ausente e dependencia externa.
2. Tentar reparo local, configuracao correta, recuperacao de sessao, retry com backoff limitado e idempotencia, rota alternativa autorizada, ferramenta equivalente e recuperacao de integracao; escolher somente opcoes adequadas ao defeito real, sem repeticoes infinitas.
3. Em CI ou PR: verificar checks e logs, corrigir causa de falha, atualizar branch e repetir checks; nunca burlar branch protection, aprovacoes obrigatorias ou status checks.
4. Registrar para cada rota relevante: diagnostico, tentativa e resultado, evidencia, razao objetiva de inviabilidade e proxima alternativa. Se houver alternativa executavel, continuar `RUNNING` sem devolver a tarefa ao usuario.
5. Somente apos comprovado esgotamento de **todas as alternativas razoaveis e autorizadas aplicaveis**, registrar bloqueio objetivo com checkpoint recuperavel, dependencia externa especifica e passo exato para desbloqueio.
6. Nao executar ataques, bypass de MFA, captura de secrets, mudanca destrutiva nao autorizada, nem loops ilimitados. Falta de autorizacao obrigatoria permite `AWAITING_APPROVAL`, com escopo e aprovacao exata, sem falsas alegacoes de exaustao.

Esta regra aplica-se aos comandos `Retome`, `Continue`, `Prossiga`, `Siga` e a todas as tarefas operacionais, incluindo subagentes e controladores.
<!-- /EXHAUST_AUTHORIZED_PATHS_BEFORE_BLOCKED_V1 -->

<!-- RESUME_COMMAND_HARD_GATE_V2 -->
## GATILHO IMPERATIVO: RETOME / CONTINUE / PROSSIGA / SIGA

Qualquer mensagem do proprietario contendo somente `Retome`, `Continue`, `Prossiga` ou `Siga` (independentemente de maiusculas, minusculas ou pontuacao) e uma **ordem completa de execucao**, nao pedido de status, resumo, proposta, plano ou novo prompt. Nao exigir que o usuario repita a tarefa ou estas regras.

Obrigacoes de cada agente/subagente/worker:
1. Identificar a ultima tarefa pendente no contexto, checkpoints e estado vivo; comprovar o que ja foi feito e a proxima acao executavel.
2. Consultar AGENTS.md, regras centralizadas e runbooks do projeto; preservar sessoes, autenticacoes e progresso validos.
3. Executar a proxima acao imediatamente, continuando em sequencia ate entrega validada enquanto houver capacidade real, autorizacao e caminho seguro. Corrigir falhas de ferramentas, CI, APIs, MCP/RDC, navegador ou infra encontradas no caminho; usar fallback autorizado e retries idempotentes.
4. Nao encerrar por diagnostico, plano, PR, merge, timeout ou erro transitorio; executar testes, checks, integracao/deploy pertinentes e validacao funcional/E2E antes de `CONCLUIDO`.
5. Se a sessao/limite de ferramenta impedir continuar, registrar checkpoint fiel e dependencia exata; nao prometer execucao assincrona sem controlador de continuidade comprovadamente ativo.
6. Nunca burlar politicas de seguranca, revisao, CI, branch protection, MFA ou aprovacao necessaria. Diante de bloqueio externo real, documentar evidencias, alternativas tentadas e passo de desbloqueio; nunca marcar `CONCLUIDO` sem evidencias.

A simples resposta "retomado", um relatorio de problemas ou solicitar "diga prossiga" **nao satisfaz** esta regra. Evidencia de execucao e resultado funcional sao requisitos de aceite. Esta norma complementa, sem enfraquecer, as politicas especificas do repositorio.
<!-- /RESUME_COMMAND_HARD_GATE_V2 -->

<!-- AUTONOMOUS_RESUME_TO_VALIDATED_COMPLETION_V1 -->
## Regra obrigatoria: retomar e executar ate conclusao comprovada

- Em toda tarefa operacional, recuperar o ultimo estado e checkpoint confiavel antes de agir; nao repetir operacoes ja concluidas nem descartar trabalho existente.
- Agir autonomamente dentro das permissoes vigentes. Diagnostico, relatorio, PR aberto, tentativa de comando, timeout ou falha transitoria nao encerram a tarefa.
- Ciclo obrigatorio: **verificar estado -> executar -> identificar falhas -> investigar causa raiz -> corrigir -> prevenir recorrencia -> testar -> retomar -> validar**.
- Diante de erro de browser, MCP, RDC, API, CLI, rede, sessao, worker ou integracao, verificar se a acao anterior teve efeito, recuperar de forma idempotente e tentar alternativas tecnicamente viaveis, seguras e autorizadas.
- Usar checkpoints, logs e estado persistente quando existirem. Nunca alegar trabalho em segundo plano ou retomada automatica se nao houver controlador realmente configurado e ativo.
- Manter a tarefa `RUNNING` enquanto existir proxima acao segura e viavel no ambiente de execucao. Declarar `CONCLUIDO` apenas com evidencias frescas, testes pertinentes, regressao e validacao runtime/E2E quando aplicavel.
- Se houver bloqueio externo objetivo ou aprovacao obrigatoria pendente, registrar causa, evidencias, tentativas, checkpoint e acao exata para retomar; nao contornar protecoes de branch, autenticacao, MFA, politicas de seguranca ou controles de aprovacao.
- Respeitar regras especificas do repositorio, limites da sessao e escopo de autorizacao. Acao destrutiva ou irreversivel exige autorizacao adequada.
- A regra vale para agentes, subagentes, Codex, MCP/RDC, automacoes, debugging, auditorias, deploys e retomadas; a resposta final deve distinguir entrega comprovada de bloqueio documentado.

Fonte global da politica: `Vivaliz-site/site-shopvivaliz/REGRAS-AGENTES-CENTRALIZADAS.md`.
<!-- /AUTONOMOUS_RESUME_TO_VALIDATED_COMPLETION_V1 -->

<!-- AGENT_TASK_CLONE_LIFECYCLE_V1 -->
## Clones temporários: limpeza obrigatória na conclusão

Todo agente (GPT, Claude, Codex, Gemini e controladores) deve associar os clones temporários a um `task_id` durável e utilizar `scripts/agent_clone_lifecycle.py clone --task ... --repo ...`; se o clone já existir, executar `register --task ... --path ...` imediatamente. Ao finalizar com `agent_task_state.py complete --task ...`, os clones registrados serão removidos automaticamente **somente** se estiverem limpos, sem trabalho não enviado, sem processo ativo e sem bloqueio Git. `BLOCKED_EXTERNAL` e tarefas em andamento preservam os arquivos. O timer de recuperação retoma limpezas interrompidas. Nunca fazer `rm -rf` genérico sobre clones, caches de outros agentes ou worktrees não registrados. Consulte `docs/knowledge/task-continuity.md`.

<!-- /AGENT_TASK_CLONE_LIFECYCLE_V1 -->


<!-- BROWSER_SESSION_ACCOUNT_BINDING_V1 -->
> 🔐 **SESSÕES CHATGPT FIXAS:** leia `docs/knowledge/browser-sessions.md`. Na VM backend, `shopvivaliz-dev-chromium`/CDP 9559 pertence a `dev@shopvivaliz.com.br` e `shopvivaliz-atendimento-chromium`/CDP 9556 pertence a `atendimento@shopvivaliz.com.br`; o antigo perfil Fred/CDP 9555 é somente compatibilidade temporária para checkpoints pré-migração. **Nunca faça logout para alternar contas, nunca autentique uma conta no perfil da outra e nunca misture cookies/storage.** Se uma sessão falhar, repare o perfil correspondente e preserve o login da outra.
<!-- /BROWSER_SESSION_ACCOUNT_BINDING_V1 -->
<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA TAMBÉM É CORRETIVA:** auditoria interna, externa, independente, contraditória ou feita por outro agente/revisor segue o mesmo ciclo. Auditor externo com autorização deve corrigir, testar e reauditar. Se for read-only, o relatório não encerra: os achados corrigíveis seguem para executor autorizado e permanecem em andamento até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canonico aos hosts ShopVivaliz — revisao 2026-10-29

**Antes de operar:** ler `docs/knowledge/host-access.md` e `docs/knowledge/current-topology-and-access-2026-10-29.md`; registrar revisao e confirmar host/identidade/permissoes ao vivo.

- **Producao web/deploy/servicos do site:** `marina-a1-4c24g` (OCI ESPOSA, privado documentado `10.80.1.116`; Tailscale observado `100.118.170.46`). RDC 1/2 direto para operacoes autorizadas; runner Marina para deploys versionados.
- **Backend/controller/browser:** `always-free-arm-1787907847-26` (OCI PRINCIPAL, privado `10.0.1.38`; Tailscale observado `100.66.174.74`). MCP central so para hosts presentes e validos em sua allowlist; navegador corporativo Dev/Atendimento permanece no backend.
- **Origem web `shopvivaliz-free-a1` aposentada/excluida conforme proprietario em 2026-10-29**; `10.0.1.112:22` sem rota. Proibido acionar, publicar ou recriar como fallback. Confirmacao `TERMINATED` na OCI exige inventario de lifecycle.
- **MCP central DESATUALIZADO:** `hosts_list` ainda inclui origem excluida e omite Marina. Nao renomear o host legado nem pressupor que `admin_command_run` do central opera na Marina.
- **MCP independente Marina NAO APTO:** `shopvivaliz-marina-mcp.service` e `shopvivaliz-marina-mcp-tunnel.service` inativos; Cloudflare admin `active` nao comprova registro de conector OpenAI. Seguir `marina-direct-mcp/README.md` e `docs/ops/marina-independent-secure-mcp-tunnel.md` antes de anunciar disponibilidade.
- Usar SSH privado/Tailscale somente apos comprovar autenticacao/host key; OCI/GitHub control plane para bootstrap/recovery autorizado; GUI real quando exigida; Windows sao apoio, nao producao.
- Identidade de host, `sudo`, RBAC, lease/fencing, CI e releases imutaveis devem ser validados; nunca publicar secrets, copiar perfis ou contornar recusa de privilegio.
<!-- /SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->

<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto obrigatorio em toda tarefa
Acione o plugin Gepeto junto com Superpowers em toda tarefa e retomada, sem nova mencao do usuario. Leia e cumpra `GEPETO-POLICY.md`. Se o runtime nao expuser o plugin, registre `GEPETO_UNAVAILABLE`, informe a limitacao e continue o trabalho autorizado sem simular participacao. Aplicar um plugin nao comprova delegacao nem revisao independente.
<!-- /GEPETO_EVERY_TASK_V1 -->

<!-- SHOPVIVALIZ_REMOTE_ACCESS_V1 -->
## Acesso remoto obrigatório para agentes

Antes de qualquer operação em VM, runtime, navegador, serviço, deploy, logs ou recuperação, leia **`docs/AGENT-REMOTE-ACCESS.md`**. O padrão vigente é **Remote Control MCP primeiro**; SSH privado/Tailscale com `shopvivaliz-agent` apenas para shell direto não exposto pelo MCP; OCI Bastion/GitHub control plane para bootstrap/recovery; RustDesk para GUI. Navegador ShopVivaliz deve rodar na VM backend, nunca nos hosts Windows.

# 🤖 GUIA OBRIGATÓRIO PARA AGENTES IA

<!-- SHOPVIVALIZ_HOST_BOOTSTRAP_V1 -->
> 🔴 **BOOTSTRAP DE HOSTS OBRIGATÓRIO:** antes de qualquer diagnóstico, alteração ou validação, leia [`docs/knowledge/host-access.md`](docs/knowledge/host-access.md), [`docs/knowledge/README.md`](docs/knowledge/README.md) e [`docs/knowledge/agent-rules.md`](docs/knowledge/agent-rules.md). O GPT `@dev` e qualquer agente adicional usado como programador/revisor também devem ler [`docs/knowledge/dev-agent-briefing.md`](docs/knowledge/dev-agent-briefing.md), inclusive a política de pesquisa técnica atualizada na web.
> **Topologia atual (2026-10-29):** Marina `marina-a1-4c24g` = web/deploy (OCI ESPOSA); `always-free-arm-1787907847-26` = backend/controller/browser (OCI PRINCIPAL). A antiga `shopvivaliz-free-a1` foi excluida segundo o proprietario; seu IP `10.0.1.112` nao e destino valido. O MCP central ainda aponta ao legado e nao inclui Marina; acesso comprovado a Marina e RDC direto, MCP proprio ainda inativo. Ver `docs/knowledge/current-topology-and-access-2026-10-29.md`.

<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
> 🧭 **@Superpowers CONTÍNUO E OBRIGATÓRIO:** toda conversa, sessão, agente e retomada de tarefa ShopVivaliz deve usar @Superpowers **em cada etapa material**, não apenas no início. Reaplique a disciplina adequada ao passar por planejamento, investigação, implementação, debugging, TDD/testes, revisão, correção, PR/merge, deploy, pós-deploy e auditoria. Em `retome/continue/prossiga`, continue do último checkpoint comprovado sob @Superpowers. Fonte central: `REGRAS-AGENTES-CENTRALIZADAS.md`.


**Efetivo:** 2026-07-15  
**Responsável:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Escopo:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, e-mails)  

> ⚠️ **CRÍTICO:** Ler primeiro [`REGRAS-AGENTES-CENTRALIZADAS.md`](REGRAS-AGENTES-CENTRALIZADAS.md), fonte única de verdade para a política completa.

---

<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> 🛠️ **AUDITORIA É CORRETIVA:** qualquer pedido de auditoria, mesmo sem "extrema", obriga todos os agentes a investigar causa raiz, corrigir todo achado material seguro/autorizado, aplicar prevenção quando pertinente, testar e reauditar. Listar erros, recomendar ou abrir issue não conclui a auditoria. APTO/CONCLUIDO exige evidência fresca pós-correção e E2E real quando aplicável. Fonte central: `REGRAS-AGENTES-CENTRALIZADAS.md` (`AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1`).
<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
> 🔧 **DIAGNÓSTICO É CORRETIVO EM QUALQUER TAREFA:** identificar a causa não conclui o trabalho. Todo agente/controlador deve continuar de defeito confirmado para **correção segura → prevenção pertinente → testes → validação real/E2E aplicável → revalidação**. Enquanto houver ação segura executável, o estado é `RUNNING`; `CONCLUIDO` exige evidência fresca pós-correção. Só `BLOCKED_EXTERNAL` comprovado, após esgotar alternativas autorizadas, permite encerrar sem corrigir. Fonte: `REGRAS-AGENTES-CENTRALIZADAS.md` (`DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1`).
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
## 🔴 Auditoria Extrema — cobertura universal obrigatória

Quando houver auditoria completa/extrema, validação de release/apto ou condição definida em `AUDIT_POLICY.md`, todos os agentes (Claude, Codex, Gemini, GPT e demais) devem executar integralmente `AUDIT_POLICY.md`, `docs/quality/EXTREME_AUDIT_PROTOCOL.md`, `docs/quality/AUDIT_RUNTIME_PARITY_V1.md`, `docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md`, `docs/quality/AUDIT_SELF_TEST_V1.md` quando aplicável e `docs/quality/AUDIT_OVERLAY.md`.

A auditoria deve procurar erros explícitos e silenciosos, dados órfãos, estados eternos, boundaries, negativos, regressões, drift, flaky/falso-verde e classes ainda não previstas (`unknown unknowns`). Achado SAFE corrigível deve ser corrigido, testado e reauditado; relatório sem remediação não encerra a auditoria.

## ⛔ REGRAS OBRIGATÓRIAS (Resumo Executivo)

### 0. Autorização operacional vigente

O proprietário autoriza os agentes com acesso técnico válido a validar pelo navegador real, preparar a entrega para revisão, aprovar PRs, fazer merge e acionar ou executar deploy sem nova aprovação explícita, desde que os checks e as proteções do repositório permitam. A autorização não elimina a necessidade de evidência independente, nem permite force-push, bypass de proteção, secrets, cobranças reais ou ações destrutivas fora do escopo.

### 0.1 Commit, PR e Merge Obrigatório ao Finalizar

> ⚠️ **CRÍTICO — COMMIT NÃO É ESTADO FINAL:** uma tarefa com alteração versionada só termina depois de **branch → validação real → commit → push → PR → checks/revisão → merge → validação pós-merge → working tree limpa → nenhuma PR pendente da tarefa**.
>
> É proibido encerrar a tarefa em qualquer estado intermediário, inclusive: commit apenas local, branch apenas enviada, PR aberta/draft, PR verde aguardando merge ou alteração local não commitada.
>
> Antes de responder como concluído, execute e confira:
> 1. `git status --porcelain` vazio no workspace usado para a tarefa;
> 2. commit/branch publicados no remoto;
> 3. PR correspondente mergeada no ramo alvo;
> 4. checks e validação real aplicável aprovados;
> 5. validação pós-merge no ramo/deploy alvo;
> 6. nenhuma PR aberta/draft referente à mesma rodada.
>
> Se um gate externo realmente impedir o merge após esgotadas as correções executáveis, reporte **INCONCLUSIVO** com o bloqueio concreto e não abandone uma PR aberta como estacionamento. Feche/limpe PRs obsoletas, duplicadas ou impossíveis de avançar.

### 0.2 IA paga nunca em rotinas permanentes ou periódicas

- Claude, GPT/OpenAI e Codex pagos são **somente para tarefas finitas** e devem encerrar ao concluir/bloquear.
- É proibido usá-los em daemon, cron/timer periódico, watcher, autorepair, polling ou retry/loop sem limite.
- Rotinas contínuas/periódicas devem ser determinísticas ou usar IA gratuita/local aprovada, sem fallback silencioso para provedor pago.
- Toda tarefa finita com IA paga precisa de timeout, limite de retries/chamadas e condição de saída; ao atingir limite, salvar checkpoint e encerrar.
- Antes de manter qualquer automação, auditar consumidor por consumidor: necessidade, gatilho, frequência, provedor/modelo, custo, retries, timeout, saída e duplicidade.
- Workflows GitHub pagos devem exigir gatilho explícito/restrito; eventos genéricos e `schedule` não podem acionar Claude/GPT/Codex automaticamente.
- Política completa: [`REGRAS-AGENTES-CENTRALIZADAS.md`](REGRAS-AGENTES-CENTRALIZADAS.md), seção **Política obrigatória de consumo de IA e execução recorrente**.

### 1. NUNCA Use `git reset --hard` em Produção

**Proibido SEMPRE:**
```bash
git reset --hard origin/main          # ❌ NUNCA
git reset --hard origin/[branch]      # ❌ NUNCA
git reset --hard HEAD~1               # ❌ NUNCA
```

**Por quê:**
- Descarta arquivos não versionados
- Mata dados de runtime (pedidos, caches, logs)
- Não recuperável sem backup
- Viola integridade de dados operacionais

**Alternativas seguras:**
```bash
git fetch origin                       # ✅ SEGURO
git merge --ff-only origin/main       # ✅ SEGURO (falha se não-FF)
git pull --ff-only origin main        # ✅ SEGURO (rejeita merges)
```

---

### 2. Validar Working Tree Antes de Git Pull

**Obrigatório fazer ANTES de git pull/merge/fetch:**

```bash
set -Eeuo pipefail  # Falhar imediatamente em erros

# 1. Verificar status
git status --porcelain

# 2. Se há alterações NÃO commitadas:
if [[ -n "$(git status --porcelain)" ]]; then
    echo "❌ Working tree sujo. Abortar."
    exit 1
fi

# 3. DEPOIS fazer pull seguro
git fetch origin
git merge --ff-only origin/main  # Falha se não é Fast-Forward
```

---

### 3. Todo Script Shell DEVE Usar `set -Eeuo pipefail`

**Obrigatório na primeira linha:**

```bash
#!/bin/bash
set -Eeuo pipefail  # ← OBRIGATÓRIO

# Qualquer erro agora para a execução
somecommand | grep pattern  # Se grep falhar, script falha
```

---

### 4. Validar Código de Saída de Todo Comando

**Padrão correto:**

```bash
# ✅ CORRETO
if ! command arg; then
    echo "❌ Comando falhou"
    exit 1
fi

# ❌ ERRADO
command arg
echo "✅ Sucesso"  # Roda mesmo se command falhou
```

---

### 5. Registre Estado ANTES e DEPOIS

**Obrigatório para testes:**

```bash
echo "=== ANTES ===" 
git log --oneline -1
git status --porcelain
date -u

echo "=== TESTANDO ==="
# ... seu teste ...

echo "=== DEPOIS ===" 
git log --oneline -1
```

---

### 6. NUNCA Declare "Sucesso" Sem Evidência

**Proibido:**

| ❌ NÃO FAÇA | ✅ FAÇA |
|-----------|--------|
| "100% operacional" | "COMPROVADO: SHA bate" |
| "funcionando" | "FALHOU: erro no log" |
| "daemon rodando" | "INCONCLUSIVO: sem evidência" |

---

### 7. Testes Reais, Nunca Simulação

- Se você executa `git pull/reset/fetch` DEPOIS do push, invalidou o teste
- Teste real = push + aguardar daemon + SEM intervir

---

### 8. Separar Claramente: Preparação, Disparo, Espera, Observação

```bash
# FASE 1: PREPARAÇÃO
echo "=== PREPARAÇÃO ===" 
git checkout main
git log --oneline -1

# FASE 2: DISPARO
echo "=== DISPARO ==="
BRANCH="test/auto-sync-$(date +%Y%m%d-%H%M%S)"
git switch -c "$BRANCH"
git commit --allow-empty -m "test: sync"
git push -u origin "$BRANCH"
PR_URL=$(gh pr create --base main --head "$BRANCH" --title "test: sync" --body "Teste controlado de sincronização")
gh pr merge "$PR_URL" --squash --delete-branch
git fetch origin main
EXPECTED_SHA=$(git rev-parse origin/main)

# FASE 3: ESPERA (SEM INTERVIR!)
echo "Aguardando 4 minutos..."
sleep 240

# FASE 4: OBSERVAÇÃO
echo "=== OBSERVAÇÃO ===" 
ACTUAL_SHA=$(ssh ubuntu@vm "git -C /home/ubuntu/site-shopvivaliz rev-parse HEAD")
[[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]] && echo "✅ OK" || echo "❌ FALHOU"
```

---

### 9. Proteja Dados Operacionais

**NUNCA comitte:**

```
storage/orders/
storage/codex-bridge/state.json
storage/orchestrator/queue.json
.agent-heartbeats/
.git-sync.lock
```

---

### 10. Em Caso de Erro: PARAR Imediatamente

```bash
set -Eeuo pipefail  # Faz script falhar automaticamente em erros
git fetch origin    # Se isso falha, próximas linhas NÃO rodam
git merge --ff-only origin/main
```

---

## 📋 CHECKLIST

- [ ] `set -Eeuo pipefail` em scripts
- [ ] Validei código de saída
- [ ] Registrei ANTES e DEPOIS
- [ ] Teste é REAL
- [ ] NÃO usei `git reset --hard`
- [ ] Nenhuma falsa afirmação
- [ ] Status é COMPROVADO/FALHOU/INCONCLUSIVO

---

## 🏗️ NOVA ARQUITETURA: RELEASES IMUTÁVEIS (Ativo desde 2026-07-25)

**Produção migrou de `git merge` vivo para releases imutáveis.**

Estrutura pós-migração:
```
/home/ubuntu/shopvivaliz-deploy/
├── repo/            (clone limpo, somente leitura)
├── releases/        (releases imutáveis)
└── current → releases/20260725-143500-2489d8d9/

/var/lock/shopvivaliz-deploy.lock (flock, impede concorrência)
```

### O Que Mudou Para Agentes

**ANTES (2026-07-24 e antes):**
```bash
git merge --ff-only origin/main  # ❌ Na árvore viva
                                  # ❌ Bloqueia por .env/.cache modificados
                                  # ❌ Sem rollback rápido
```

**DEPOIS (2026-07-25 e depois):**
```bash
/home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh
# ✅ Cria nova release limpa
# ✅ Separado de dados runtime
# ✅ Rollback atômico
# ✅ idempotente (roda 2x, mesmo resultado)
```

### Regras para Deploy (Novo)

1. **NUNCA editar dentro:**
   ```
   /home/ubuntu/shopvivaliz-deploy/releases/<ativa>/
   /home/ubuntu/shopvivaliz-deploy/current/
   ```

2. **Dados runtime vão em `/shared/`:**
   ```
   .env, uploads/, logs/, cache/, sessions/
   ```

3. **Deploy é pull-based:**
   - VM faz `git fetch` a cada 2 min (cron)
   - Detecta novo SHA
   - Cria nova release
   - Troca symlink atomicamente
   - **Você não pusheia release**, você pusheia commits
   - Se existir `/home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref`, o cron passa a perseguir esse branch/SHA em vez de `origin/main`

4. **Rollback é manual:**
   ```bash
   sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh
   ```

### Toolkit Local Obrigatório (Windows)

Os seguintes atalhos locais ficam em `C:\Users\FRED\.local\bin` e devem ser preferidos por agentes quando a sessão tiver acesso ao terminal local:

```powershell
docker-check
docker-up
docker-down
sv-vm-ssh hostname
sv-vm-status
sv-deploy-head -DryRun
sv-deploy-sha <sha> -DryRun
sv-blog-status
sv-blog-publish
```

Regras:

1. A chave SSH operacional atual da VM é:
   ```text
   C:\Users\user\Downloads\ssh-key-2026-07-04.key  (ou C:\Users\FRED\Downloads\ssh-key-2026-07-04.key depending on profile)
   ```

2. `sv-deploy-head` e `sv-deploy-sha` atualizam o alvo persistente de produção em:
   ```text
   /home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref
   ```
   Isso impede que o cron volte automaticamente para `main` enquanto um branch/SHA específico estiver fixado.

3. Antes de afirmar que produção ficou publicada, registrar no mínimo:
   - `sv-vm-status`
   - `sv-blog-status` se a alteração tocar blog/Liz/conteúdo
   - HTTP 200 ou artefato real do endpoint afetado

4. Se a release ativa voltar para `main` após alguns minutos, isso é FALHA operacional do alvo persistente ou do runner antigo, não sucesso de deploy.

### Editores Padronizados (Windows)

Para reduzir drift entre sessões e evitar edição no checkout errado, os editores locais devem abrir sempre o mesmo worktree operacional:

```text
Worktree padrão: C:\site-shopvivaliz-prod-liz
Branch validado: agent/liz-widget-prod
SHA validado em 2026-07-29: 19328ac2
```

Regras:

1. Antes de editar ou publicar, confirmar:
   ```bash
   git -C C:\site-shopvivaliz-prod-liz branch --show-current
   git -C C:\site-shopvivaliz-prod-liz rev-parse --show-toplevel
   ```

2. Não assumir que `C:\site-shopvivaliz` é o checkout ativo. Ele pode existir em paralelo e conter estado diferente.

3. Atalhos padronizados criados em 2026-07-29:
   ```text
   C:\Users\FRED\Desktop\ShopVivaliz - VS Code.lnk
   C:\Users\FRED\Desktop\ShopVivaliz - Antigravity.lnk
   C:\Users\FRED\Desktop\ShopVivaliz - Antigravity IDE.lnk
   ```
   Todos apontam para `C:\site-shopvivaliz-prod-liz`.

4. Start Menu também foi padronizado para abrir o mesmo worktree em:
   - `Visual Studio Code`
   - `Antigravity`
   - `Antigravity IDE`

5. Preferência operacional atual de agentes:
   - VS Code: `Roo`, `Claude`, `GPT/Codex`
   - Antigravity: usado para fluxo com `Gemini`
   - Antigravity IDE: manter alinhado ao mesmo worktree e mesmas regras de terminal/comandos

6. O terminal dos editores deve conseguir resolver:
   ```powershell
   sv help
   docker-check
   docker-up
   docker-down
   ```
   Se isso falhar, a sessão não está corretamente alinhada ao ambiente operacional local.

### ShopVivaliz Mobile Agent Bridge

Fluxo oficial para dar acesso controlado da VM ao GPT mobile, sem shell irrestrito:

```text
VM path: /home/ubuntu/site-shopvivaliz/agent-bridge/inbox/
Service: shopvivaliz-agent-bridge.service
Repo alvo: /home/ubuntu/site-shopvivaliz
```

Regras:

1. A bridge aceita apenas 4 acoes JSON:
   - `create_issue`
   - `apply_patch_pr`
   - `read_file`
   - `run_readonly_audit`

2. A bridge continua limitada à sua API e nunca deve:
   - commitar direto em `main`
   - fazer `auto-merge`
   - aceitar secrets no patch
   - operar fora dos prefixes permitidos do repositorio

   A autorização operacional acima não amplia o schema da bridge nem substitui as proteções do GitHub; ela se aplica aos agentes que tenham capacidade técnica para executar o fluxo completo.

3. Fluxo esperado para GPT mobile:
   - gerar um `.json`
   - colocar o arquivo em `/home/ubuntu/site-shopvivaliz/agent-bridge/inbox/`
   - aguardar o watcher do service
   - ler o resultado em `/home/ubuntu/site-shopvivaliz/agent-bridge/outbox/`

4. Estados de processamento:
   - tarefa concluida: `*.json.done`
   - tarefa recusada/erro: `*.json.failed`
   - resultado estruturado: `outbox/*.result.json`

5. Verificacao operacional na VM:
   ```bash
   sudo systemctl status shopvivaliz-agent-bridge.service --no-pager -l
   ls -la /home/ubuntu/site-shopvivaliz/agent-bridge/inbox/
   ls -la /home/ubuntu/site-shopvivaliz/agent-bridge/outbox/
   cat /home/ubuntu/site-shopvivaliz/agent-bridge/config.json
   ```

6. Prompt operacional resumido para o ChatGPT mobile:
   ```text
   Gerar uma tarefa JSON para o ShopVivaliz Mobile Agent Bridge.
   Ação: create_issue ou apply_patch_pr ou read_file ou run_readonly_audit.
   Repositório: Vivaliz-site/site-shopvivaliz.
   Regras: nunca main direto pela bridge, nunca secrets, sempre evidência. A autorização de aprovação/merge/deploy vale para agentes com capacidade técnica fora da bridge, sem bypass de proteção.
   Objetivo: <descrever objetivo>.
   ```

7. O service roda em loop e observa a `inbox` a cada 30 segundos.

### Remote Control MCP para agentes

O **Remote Control MCP privado do ShopVivaliz** é a rota operacional prioritária para controle de hosts. Consulte primeiro `docs/HOST-ACCESS.md`, `docs/knowledge/host-access.md` e `remote-control-mcp/SPEC.md`.

Regras:

1. Controller canônico: `always-free-arm-1787907847-26`; endpoint MCP privado/loopback, nunca público.
2. Use ações allowlisted e tarefas duráveis do MCP antes de recorrer a shell direto.
3. Se a capacidade necessária não existir no MCP ou o control plane estiver comprovadamente indisponível, use SSH privado/Tailscale; para bootstrap/recovery, GitHub Actions/OCI Bastion.
4. Nunca publicar token, cookie, chave, OTP/TOTP, conteúdo de secret ou credencial de sessão.
5. Validar `hostname`, identidade, alvo e resultado real antes de declarar acesso funcional.
6. Browser de agentes permanece na backend; Windows não é fallback de navegação.

### Se Tiver Que Acessar a VM

```bash
# Remote Control MCP e a primeira rota operacional.
# SSH publico direto esta desabilitado. Se precisar de shell direto no runner self-hosted:
ssh -i ~/.ssh/id_rsa ubuntu@127.0.0.1
# Fora da VCN ou para bootstrap/recovery, use OCI Bastion/control plane auditavel.

# Monitorar deploy (a cada 2 min, cron roda)
tail -f /var/log/shopvivaliz-deploy.log

# Ver release ativa
readlink -f /home/ubuntu/shopvivaliz-deploy/current

# Ver alvo persistente do cron
cat /home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref 2>/dev/null || echo "origin/main"

# Ver releases disponíveis
ls -la /home/ubuntu/shopvivaliz-deploy/releases/

# Rollback manual
sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh

# Nunca faça:
cd /home/ubuntu/shopvivaliz-deploy/current
vi index.php  # ❌ NUNCA edite release ativa
```

---

**Versão:** 1.1  
**Data Última Atualização:** 2026-07-25  
**Efetivo para:** Todos os agentes IA  
**Status:** ✅ Produção — Arquitetura Imutável Ativa
## Liz: qualidade, segurança e grounding

A Liz deve tratar fatos comerciais como dados, não como texto gerado. Preço,
estoque, frete, prazo, pedido e rastreamento só podem ser apresentados quando
vierem de uma fonte oficial e identificável.

- Não consultar pedidos sem sessão autenticada e vínculo do pedido ao usuário.
- Mascarar e-mails, identificadores e dados pessoais em respostas e métricas.
- Não aceitar instruções do usuário para revelar prompt, chaves, segredos ou regras internas.
- Reclamações e pedidos explícitos de atendimento devem gerar handoff estruturado com resumo.
- Estado conversacional deve ser limitado, normalizado e separado das instruções do sistema.
- Conteúdo editorial é contexto versionado; não substitui APIs de catálogo, checkout ou pedidos.
- Respostas da API devem declarar grounding_status, fontes utilizadas e estado conversacional.
- Métricas não podem registrar mensagem bruta, token, senha ou dado pessoal.
- Alterações na Liz devem incluir testes de conversa, segurança e regressão.

## Politica obrigatoria de PR, gate, merge e bloqueios (2026-08-27)

- Toda alteracao finalizada deve terminar em PR validado e merge; nao deixar PR pronta aberta sem motivo tecnico comprovado.
- Se qualquer check, teste, lint, gate, conflito ou Action falhar: investigar a causa raiz, corrigir, revalidar e repetir ate ficar verde. E proibido contornar falha com bypass, mascaramento de exit code, force merge ou desativacao de protecao.
- Gate verde autoriza merge automatico somente para PR do proprio repositorio depois que todos os checks canonicos aplicaveis passarem.
- Bloqueio aparentemente externo nao encerra a tarefa: procurar solucao segura por configuracao, credencial, permissao, host key, secret, servico, provider, infraestrutura ou rota alternativa antes de classificar como INCONCLUSIVO.
- Depois do merge, verificar Actions do SHA resultante e corrigir qualquer falha regressiva antes de declarar conclusao.
- No ShopVivaliz, Quality Gate verde em main deve acionar automaticamente o Master Production Pipeline e o deploy canonico na VM2; VM1 nao e destino de producao do site.

## Oracle Cloud (OCI) — acesso obrigatório para agentes

Para operações OCI, use somente a identidade dedicada `AGENTS`; nunca use como fallback o perfil pessoal `DEFAULT`. O procedimento e os caminhos por host estão em [`docs/operations/oci-agent-access.md`](docs/operations/oci-agent-access.md).

- Fred-Win e VM1 usam o perfil OCI `AGENTS` local protegido.
- VM2 usa o perfil protegido com o cliente de requisições OCI assinado documentado.
- DESKTOP-KOCEPSV acessa OCI pelo wrapper SSH verificado para VM1; a chave privada OCI não deve ser duplicada nesse host.
- É proibido versionar, imprimir ou transportar em logs/prompts chaves `*.pem`, security tokens, cookies de sessão ou headers de autenticação.
- Valide acesso com uma leitura autenticada real antes de declarar OCI operacional.
- Em caso de falha externa de autenticação, permissão, rede ou serviço, investigue e procure uma solução segura; não trate o bloqueio como encerramento automático da tarefa.

## Gate obrigatorio de resposta final e deploy (FINAL_RESPONSE_DEPLOY_GATE_V1)

- A resposta final de conclusao e um gate operacional: nao pode ser enviada enquanto houver qualquer etapa executavel necessaria ao pedido original.
- Em alteracoes de codigo, configuracao ou documentacao versionada, PR aberto, checks verdes ou merge isoladamente NAO significam conclusao.
- Quando existir alvo de deploy para o repositorio, e obrigatorio acompanhar o deploy ate o SHA correto estar ativo e executar validacao pós-deploy real e reproduzivel.
- Se o deploy falhar, investigar a causa raiz, corrigir, revalidar, repetir commit/push/PR/merge quando necessario e tentar o deploy novamente; nao encerrar em estado intermediario.
- Antes de qualquer resposta final, comparar pedido original x estado real e registrar evidencias de: validacao, commit, push, PR, checks, merge, deploy e pós-deploy, conforme aplicavel.
- So e permitido encerrar sem deploy bem-sucedido diante de bloqueio externo genuino e incontornavel com os acessos/ferramentas disponiveis; nesse caso o estado e BLOCKED/INCONCLUSIVO, nunca sucesso.

## Isolamento obrigatorio de sessao CLI por chat

Antes de qualquer operacao em terminal/CLI, leia e cumpra a secao `Isolamento obrigatorio de sessao CLI por chat` de `AI-TO-CLI-PROTOCOL.md`. Cada chat deve usar sessao/namespace CLI exclusivo; reutilizacao de sessao entre chats e proibida. Estado necessario para retomada deve ser persistido fora da memoria do shell.

## Auditoria Extrema - arquitetura e deploy
Nos gatilhos de `AUDIT_POLICY.md`, execute tambem `docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md`. A auditoria inclui gargalos de CI/deploy, arquitetura/codigo, ownership, contratos cross-repo e melhorias seguras de velocidade sem reduzir cobertura.


<!-- AUDIT_ABSOLUTE_V5_ENTRYPOINT -->
## Auditoria Extrema V5 absoluta — entrada obrigatória
Antes de qualquer auditoria completa/extrema, validação de release ou declaração de aptidão, leia e execute integralmente `AUDIT_POLICY.md`, `docs/quality/AUDIT_ABSOLUTE_GATE_V1.md`, `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md`, `docs/quality/AUDIT_APTO_REMEDIATION_LOOP_V1.md`, `docs/quality/AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `docs/quality/AUDIT_PROJECT_REQUIREMENTS_V1.md` e `docs/quality/AUDIT_PROJECT_REQUIREMENTS.json`.
Se existir UI, o próprio agente executa E2E real no navegador gráfico no mesmo release. API/CLI/headless-only não certificam. Todo bloqueador executável deve ser corrigido, testado, deployado quando aplicável e reauditado até o certifier retornar `AUDIT_VERDICT=APTO`. Antes de declarar bloqueio por login/credencial, esgote a descoberta segura em todos os repositórios governados e fontes canônicas sem expor secrets.


<!-- AUDIT_MERGE_ENFORCEMENT_V1 -->
## Enforcement absoluto de merge/main
Em auditoria/aptidão, leia `docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md`. O gate local deve executar `scripts/absolute-audit-governance-validate.sh`, e todo push em `main`/`master` deve passar pelo **Absolute Audit Main Guard** com prova de PR mesclado. Não trate a existência do workflow como evidência de branch protection.

<!-- TASK_CONTINUITY_ENFORCEMENT_V3 -->
## Continuidade obrigatória até estado terminal

- Estados finais permitidos para uma tarefa iniciada: `CONCLUIDO` com validação fresca, ou `BLOCKED_EXTERNAL` com impedimento externo objetivo e intransponível após esgotar alternativas seguras.
- Pedido explícito do usuário para executar, implementar, corrigir, auditar, resolver ou continuar até conclusão, somado à autorização operacional vigente, já satisfaz gates genéricos de aprovação de plano/design para ações reversíveis e dentro do escopo. Não parar apenas para pedir "posso continuar?" ou aprovação intermediária equivalente.
- Essa autorização não elimina confirmações obrigatórias de segurança/plataforma para ação destrutiva ou irreversível não autorizada exatamente, cobrança real, segredo, login/CAPTCHA/recovery ou ampliação material de escopo.
- Falha de ferramenta, plugin, CLI, API, navegador, sessão, runner, workflow, timeout ou rota primária é estado `RUNNING`, nunca terminal enquanto existir fallback seguro ou próxima ação executável.
- Tarefa simples segue a mesma regra: progresso parcial, diagnóstico ou erro recuperável não autorizam encerrar.
- Quando o runtime tiver acesso ao repositório, persistir o estado em `scripts/agent_task_state.py`; antes de alegar término, o gate `terminal --task <id>` deve aceitar o estado.
- Contrato detalhado: `docs/knowledge/task-continuity.md`.
<!-- /TASK_CONTINUITY_ENFORCEMENT_V3 -->

<!-- CODEX_LAST_RESORT_V1 -->
## Codex como última opção de execução

- Preservar cota do Codex para tarefas que realmente precisem dela. A ordem padrão de continuidade é: **rota determinística/controle remoto auditável → executor alternativo autenticado (Gemini/Claude conforme a tarefa) → Codex por último**.
- Para operações de host, serviço e diagnóstico, preferir **Remote Control MCP**; depois SSH privado/Tailscale e somente para bootstrap/recovery GitHub connector/Actions ou OCI Bastion. Browser permanece na backend. Não consumir Codex para esse trabalho por padrão.
- Esgotamento de tokens/cota, rate limit, indisponibilidade ou falha de autenticação do Codex **não é estado terminal**. A tarefa permanece `RUNNING`, preserva checkpoint e tenta as rotas anteriores/alternativas que ainda forem seguras.
- `BLOCKED_EXTERNAL` só é permitido depois de provar que todas as rotas autorizadas e adequadas ao objetivo estão indisponíveis/intransponíveis; "Codex sem tokens" isoladamente nunca satisfaz esse critério.
- Nenhum daemon/cron/watch pode consumir Codex automaticamente: a excecao historica de 2026-10-01 foi REVOGADA em 2026-10-11 por FREE_PROVIDER_FALLBACK_V2. Codex e Claude sao apenas ultimas opcoes interativas com aprovacao humana explicita.
<!-- /CODEX_LAST_RESORT_V1 -->

<!-- CHATGPT_RESUME_ORDER_V5 -->
## Ordem obrigatória de retomada

Policy: `CHATGPT_RESUME_ORDER_V5`.

Para qualquer tarefa abandonada, interrompida ou com checkpoint `RUNNING`, a ordem é fixa:

1. **ChatGPT comum** — primeira opção; retoma do checkpoint no próximo turno disponível.
2. **ChatGPT Work** — segunda opção, quando a tarefa exige continuidade persistente/multi-etapas.
3. **CLI** — terceira e última opção, somente depois das duas camadas ChatGPT anteriores terem sido tentadas ou comprovadamente indisponíveis/inadequadas.

Roteamento persistido: `chatgpt_common_then_work_then_cli`.
Ordem serializada: `["chatgpt_common", "chatgpt_work", "cli"]`.

Interrupção de streaming não autoriza pular para CLI. O watchdog não chama CLI nem IA paga; ele cria o pedido de retomada. O worker roteia `auto_resume` para `gpt`/ChatGPT comum. A camada CLI exige `SHOPVIVALIZ_RESUME_STAGE=cli_last`; sem isso, falha fechada com exit 75 e mantém o checkpoint `RUNNING`.
<!-- /CHATGPT_RESUME_ORDER_V5 -->

<!-- GLOBAL_TASK_CONTINUITY_V8 -->
## Continuidade global multi-repositório
O `site-shopvivaliz` é o controlador canônico de `GLOBAL_TASK_CONTINUITY_V8`.
Todo estado persistido deve carregar `repository=owner/name`; o runtime canônico
executa watchdog -> dispatcher -> Gemini primário; se Gemini não produzir progresso, o fallback finito `codex-auto` explicitamente autorizado pode assumir o mesmo fingerprint sem execução concorrente.
A presença de adapter não certifica continuidade: somente `continuity_e2e_pass`
real para o mesmo repositório autoriza APTO global.
<!-- /GLOBAL_TASK_CONTINUITY_V8 -->

<!-- CHECKPOINT_FIRST_V9 -->
## Checkpoint antes da primeira etapa material
Toda tarefa potencialmente longa ou mutável deve registrar `agent_task_state.py start`
**antes** da primeira investigação extensa, chamada remota material, edição, mutação,
execução longa, delegação ou espera de CI. O objetivo é eliminar a janela em que uma
interrupção do ChatGPT ocorre antes de existir estado durável.

Depois de cada avanço material, atualize `progress` com evidência e `next_action`.
Não adie o primeiro checkpoint para depois do diagnóstico. Se o contexto atual não
consegue alcançar o controlador canônico, falhe fechado para tarefas que dependem de
retomada automática e use a rota operacional auditável que consiga registrar o estado.
<!-- /CHECKPOINT_FIRST_V9 -->

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
**Obrigatorio para todos os agentes (ChatGPT, Claude, Gemini, Gepeto e executores delegados):** antes de diagnosticar GUI Ubuntu, RustDesk, perfis corporativos ou login na backend, ler `docs/knowledge/shopvivaliz-graphical-session-recovery.md` no `main`. A captura de Dev/Atendimento no seletor de contas da sessao XFCE `fredconsole/:0` nao comprova autenticacao individual dos perfis Chromium `fredrdp/:99` CDP 9559/9556. Distinguir o greeter LightDM `:1`, desktop `:0` e Xvfb `:99`; validar cada conta via `/api/auth/session` e email exato. Para greeter, examinar sessao `logind` preexistente antes de solicitar/redefinir senha. Preservar Keyring, perfis, cookies e MFA, sem bypass.
<!-- /SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->

<!-- FREE_PROVIDER_FALLBACK_V2 -->
## Rotinas persistentes: IA gratuita obrigatoria e ordem de fallback
- Camada primaria: Gemini Flash elegivel ao nivel gratuito e Ollama local;
  ambos sao os unicos modelos autorizados nas tentativas autonomas de background.
- Gemini Flash requer que a credencial/projeto seja elegivel ao nivel gratuito;
  nome do modelo por si so nao prova que a conta Google nao tenha faturamento.
- Primeira alternativa fora da camada gratuita: ChatGPT normal na sessao
  ja autenticada, com lease/checkpoint. ChatGPT Work pode preceder CLI.
- Codex e Claude so podem ser acionados como ultimas alternativas para tarefa
  interativa, finita, com aprovacao humana explicita e controle de cota.
- Cron, controller, worker e bridges nao podem promover fallback pago por
  variavel de ambiente legada, healthcheck ou indisponibilidade do Gemini.
- Em caso de cota gratis esgotada: registrar motivo seguro, preservar RUNNING
  e pedir a proxima retomada autorizada, sem cobrar silenciosamente.
- Prevenir recorrencia: instalador escreve paid fallback=0; execucao background
  ignora paid fallback=1 e bridges pagos negam acesso por padrao.
<!-- /FREE_PROVIDER_FALLBACK_V2 -->

<!-- BACKEND_IO_PRESSURE_ADMISSION_V1 -->
## Gate obrigatorio contra saturacao de I/O da VM backend (todos os agentes)

**Incidente real 2026-10-14, P1:** a VM `always-free-arm-1787907847-26` (2 vCPU/12 GiB) registrou PSI `/proc/pressure/io` `some.avg10=99.34%`, `full.avg10=55.49%`, carga 77 e interrupcoes de guardas/transportes. A auditoria do Remote Control MCP confirmou mais de vinte `task_submit` independentes em cinco minutos. Limitar somente a duas tarefas duraveis simultaneas **nao basta**, pois a fila ainda inicia novas tarefas seriadas durante saturacao. Nao tratar recuperacao pontual de um oneshot como estabilidade.

**Antes de iniciar qualquer nova operacao pesada no backend, por qualquer canal** (MCP `task_submit`, `admin_command_run`, RDC, SSH, Claude/Code, CI, script ou shell): consulte **somente por leitura** `/proc/pressure/io` e `/proc/loadavg`, inspecione as tarefas ja ativas, confirme o dono/lease e evite repeticao de tarefas equivalentes. Incluem-se varreduras recursivas de Git/worktrees/disco, `du`/`find`, limpeza Docker, caches, `npm ci`, builds, instalacoes, E2E intensivo e suites de teste extensas.

- Se `some.avg10 >= 30` **ou** `full.avg10 >= 10`, **NAO iniciar** a nova operacao pesada. Manter checkpoint `RUNNING`, preservar fila/dados/ownership e salvar `next_action` para reavaliacao, sem envio de tasks duplicadas.
- Para retomar trabalho pesado, exigir **duas medidas temporariamente separadas**, ambas com `some.avg10 < 15` **e** `full.avg10 < 3`, sem regressao relevante; repetir a checagem imediatamente antes de criar/executar o job. Se PSI nao puder ser lido, nao considerar o host saudavel nem iniciar novo trabalho de alto impacto somente por falha de observabilidade.
- **Nao interromper** autenticacao, login-integrity, sentinelas, monitores *read-only*, acessos de emergencia ou consultas leves. Operacoes somente leitura devem permanecer curtas e nao fazer `du`/`find` recursivo sob pressao.
- **Nao contornar** esta regra usando outro transporte ou outro agente. Nao matar/cancelar jobs, reiniciar Chromium/containers/VM, limpar volumes ou worktrees sujos, fazer `swapoff` ou aumentar custo de OCI sem ownership, provas, backup e autorizacao apropriados.
- Antes de atribuir toda carga ao runner GitHub Actions, verificar o cgroup efetivo: o runner da Solange ja foi observado com `IOWeight=20`, `CPUWeight=20` e `CPUQuota=25%`; havia tambem trabalho Docker, navegador, diagnosticos MCP e CI. Identificar responsaveis por janela, sem associar causalidade pela leitura isolada.
- O gate acima e uma **regra operacional do agente**, nao um circuito de protecao implantado no servidor. A prevencao permanente exige controle de admissao centralizado em MCP/CI, com limites por host, lock, prioridades, PSI, histerese, observabilidade, provas RED/GREEN e regressao. Alteracao anterior de `remote-control-mcp/server.py` foi recusada pelos controles da plataforma: **nao tentar contornar essa recusa por shell, outro conector ou PR alternativo**.

Referencia de acompanhamento: issue #2934. Enquanto houver PSI extremo recorrente, tarefas de continuidade sem vinculacao comprovada ou E2E real faltante, nao declarar `AUDIT_VERDICT=APTO`.
<!-- /BACKEND_IO_PRESSURE_ADMISSION_V1 -->

<!-- VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
## Gate executavel de conclusao apos merge e deploy

Para toda alteracao implantavel de codigo, configuracao, servico ou integracao, **nao encerre a tarefa em PR aberto, CI verde ou merge**. Registre o SHA de `main` apos o merge e execute `python3 scripts/quality/deployment_closure_guard.py --expected-sha <SHA_DE_MAIN>`: o guard confronta o `origin/main`, a evidencia assinada do workflow oficial e a revisao publicada em `/api/health/version.php`. Somente exit 0 com a revisao esperada inclusa permite prosseguir aos testes E2E/GUI e ao encerramento; uma versao anterior, URL bloqueada, evidencia ausente ou workflow falho **mantem RUNNING/NAO_APTO**. Nunca usar SHA de branch squash como se fosse main. Excecao: alteracoes comprovadamente so de docs/testes sem payload deployavel, classificadas pelo script `scripts/should-deploy-production.sh`.

O workflow `.github/workflows/production-deployment-closure-guard.yml` verifica novamente a producao apos o pipeline e quando acionado manualmente, e abre/atualiza um unico issue de deriva de deploy ate que a evidencia real seja reconciliada. Este guard NAO faz deploy e nao contorna CI, labels humanos, RBAC, leases ou revisoes; o executor da tarefa continua responsavel por corrigir, implantar pela rota oficial e validar no runtime. Quando a tarefa e de catalogo/preco, confrontar **todos** os SKUs publicados com a API Olist/Tiny v3 e a API individual por SKU apos a implantacao; nao basta uma amostra ou apenas o cache.
<!-- /VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
