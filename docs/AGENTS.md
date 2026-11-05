<!-- OWNER_NO_UNAPPROVED_RESTRICTIONS_20261105 -->
> **Regra do proprietario (2026-11-05): nenhum agente cria restricao ou bloqueio sem aprovacao expressa do dono.** Isso vale para qualquer agente (Claude, GPT, Codex, Gemini, Copilot, workers e subagentes) e para qualquer lugar: instrucoes, documentacao, codigo, workflows, testes de contrato, configuracao de MCP, permissoes, politicas e grants. Nao adicionar, reintroduzir nem endurecer proibicao, trava ou "NAO USE" por conta propria; se achar que algo precisa de limite, propor ao dono em PR ou pergunta e esperar a aprovacao. Restricao que o dono diga que nao aprovou deve ser removida quando ele pedir. Protecoes que o dono ja aprovou continuam valendo, e o agente nao as enfraquece por conta propria. Contexto: a proibicao de SSH direto, RDC e Bastion foi colocada sem aprovacao e retirada em 2026-11-05.

<!-- OWNER_FOREGROUND_CONTINUE_NO_CONTROLLER_SUBSTITUTION_20261103 -->
## Execucao continua deste atendimento, sem atualizacoes intermediarias

**Instrucao expressa do proprietario, 03/11/2026:** quando disser "prossiga ate concluir", "siga sem pausas", "sem atualizacoes parciais" ou equivalente, o agente ChatGPT que recebeu a tarefa deve continuar executando **na propria interacao atual**, usando as ferramentas autorizadas, em vez de encerrar com uma promessa, um relatorio de bloqueios, uma issue de acompanhamento ou a proposta de usar uma controladora externa. Registrar checkpoint e util, mas nao substitui solucionar, testar, integrar e validar o que for executavel.

**Nao confundir com o controlador 24x7:** ele e um componente independente e opcional para esta ordem. Seu estado degradado, a falta de vinculo de sessao ou a ausencia de automacao em background NAO impedem a execucao direta do agente, mediante acessos e permissoes oficiais existentes. Nao condicionar a retomada de PRs, Marina ou outros reparos a consertar a controladora.


**Entrega:** obedecer ao pedido de nao enviar atualizacoes parciais; responder somente depois de completar a execucao viavel nessa interacao ou de comprovar precisamente a dependencia externa intransponivel, descrevendo acoes concretas e o que permanece aberto. Nao prometer que o mesmo ChatGPT continuara executando sozinho apos o turno terminar; isso exige mecanismo persistente realmente configurado e verificado. Nao declarar o trabalho completo com somente auditoria/diagnostico/checkpoint.

<!-- /OWNER_FOREGROUND_CONTINUE_NO_CONTROLLER_SUBSTITUTION_20261103 -->

<!-- OWNER_ONE_CLICK_MERGE_QUEUE_POLICY_P2_20261102 -->
## Regra aprovada por owner P2: uma revisao humana e fluxo automatico

Apos o merge formal da PR P2 de ativacao e comprovacao do ruleset nativo
merge_queue, a UNICA acao humana exigida numa PR protegida e o GitHub
APPROVED autenticado de shopvivaliz-human no HEAD exato. Apos isso, o
operador tecnico autorizado fredmourao-ai pode APENAS enfileirar
o HEAD imutavel na fila nativa. O GitHub merge queue bot realiza o merge
sem atualizar a branch aprovada e sem exigir que o proprietario habilite
auto-merge. O autorizador exige prova GraphQL de AddedToMergeQueueEvent
posterior a revisao humana, enqueuer permitido, HEAD idêntico, merge
por github-merge-queue[bot], grant vigente e escopo identico. Nunca
aceitar merge direto por agente, revisao simulada, checks nao oficiais,
force-push, bypass admin, permissao externa automatica ou falta de lease.

Enquanto o ruleset nativo nao estiver ativado pelo grant P2 oficial,
as regras antigas de integracao somente humana continuam vinculantes.
A aprovacao passada permanece valida somente para o HEAD exato e a
alteracao posterior da branch protegida exige nova revisao, nao uma
reaprovacao informal em chat.
<!-- /OWNER_ONE_CLICK_MERGE_QUEUE_POLICY_P2_20261102 -->

<!-- MARINA_CLOUDFLARE_EXISTING_TUNNEL_ROUTE_20261102 -->
> **Instrucao atualizada do proprietario (2026-11-02, prevalece sobre instrucoes anteriores de OpenAI Secure MCP Tunnel para Marina):** o **MCP independente de `marina-a1-4c24g` usara o Cloudflare Tunnel JA EXISTENTE nesta VM**, `shopvivaliz-oci-marina-admin` / `shopvivaliz-cloudflared-admin.service`, com **published application/hostname separado** `mcp-marina.shopvivaliz.com.br` encaminhando o caminho `/mcp` a `http://127.0.0.1:5580/mcp`. Reutilizar o mesmo tunnel ID e conector; **nao criar outro Cloudflare Tunnel e nao depender de `OPENAI_ADMIN_KEY`, `runtime-key`, `tunnel-profile.yaml` nem do tunnel-client OpenAI para esta rota**. Nao alterar `admin-marina.shopvivaliz.com.br`, outros ingress, tokens, site, DB ou workers.
> **Estado consultado 2026-11-02:** na Marina o `shopvivaliz-cloudflared-admin.service` e o `shopvivaliz-marina-mcp.service` estavam ativos com `NoNewPrivileges=yes`; MCP sem bearer retornou HTTP 401; `mcp-marina.shopvivaliz.com.br` nao resolvia no DNS. Assim, **CHATGPT_DIRECT_E2E=NAO_APTO**, nao existe ainda ingress MCP publicado/certificado. Historicos abaixo sao snapshots do caminho OpenAI anterior e NAO instrucoes para criar tunel novo. Plano e evidencias: `docs/ops/marina-mcp-existing-cloudflare-tunnel-2026-11-02.md` e issue #3152.
<!-- /MARINA_CLOUDFLARE_EXISTING_TUNNEL_ROUTE_20261102 -->
<!-- SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1 -->
## Governanca proporcional para operador humano unico (02/11/2026)

O proprietario informou ser o unico operador humano e titular das contas sob sua gestao. Isso **nao** funde identidades: `shopvivaliz-human` continua exclusivo para aprovacoes formais, enquanto agentes/servicos usam identidades e escopos proprios. **Aplicar a matriz P0/P1/P2 e a auditoria em `docs/ops/single-owner-governance-audit-2026-11-02.md`** em todo projeto, agente, CLI, MCP, VM e fluxo GitHub governados pela ShopVivaliz.

- **P0 (somente leitura/preparacao):** inspecao, diagnostico, diff, execucao de testes, investigacao de grants ja existentes. Executar autonomamente, sem PR, aprovacao humana ou novo checklist por comando isolado. Registrar evidencia nao secreta do resultado.
- **P1 (mudanca tecnica reversivel com permissao efetiva preexistente):** corrigir, testar, usar lease/fencing aplicavel, gerar PR tecnica comum e concluir merge apos gates proporcionais ao impacto; manutencao de servico ou arquivo so dentro de allowlist/grant vigente e comprovado. **Nao exigir novamente aprovacao humana para o mesmo grant e mesmo escopo**, nao converter falha transitoria em bloqueio definitivo.
- **P2 (novo poder/efeito protegido):** novo privilégio administrativo de MCP, publicacao de ingress, RBAC, secrets, permissoes, protecao de branch, acoes comerciais sensiveis ou irreversiveis: manter aprovacao formal em PR pelo `shopvivaliz-human` quando exigida, SHA/escopo/TTL e controles reais de provedor. A identidade de um agente nunca equivale a aprovacao humana, ainda que todas as contas tenham o mesmo titular.


<!-- /SOLO_OWNER_PROPORTIONAL_GOVERNANCE_V1 -->

<!-- PR_HUMAN_VISIBILITY_ROUTING_V1 -->
## Regra de PRs: visibilidade nao exige revisao humana
<!-- /PR_HUMAN_VISIBILITY_ROUTING_V1 -->

<!-- PROTECTED_PR_REVIEW_VISIBILITY_PROCEDURE_V1 -->
<!-- /PROTECTED_PR_REVIEW_VISIBILITY_PROCEDURE_V1 -->

<!-- MCP_CENTRAL_MARINA_AND_INDEPENDENT_ROUTE_20261101 -->
> O MCP ShopVivaliz **central** tambem deve alcancar `marina-a1-4c24g`, alem do MCP proprio da Marina, que deve permanecer independente do backend/RDC. Paridade com RDC exige capacidades por host e operacao comprovadas por E2E, sem shell/root universal. Rota central read-only em PR nao equivale a runtime conectado; nunca ativar sem grant oficial, lease e autorizacao. Fonte: `docs/ops/mcp-central-marina-dual-route-2026-11-01.md`.
<!-- /MCP_CENTRAL_MARINA_AND_INDEPENDENT_ROUTE_20261101 -->
<!-- MARINA_BROKER_SERVICE_RESTART_PATTERN_20261103 -->
> **Decisao do owner (2026-11-03): restart de servicos Marina por padrao de nome.** Na `marina-a1-4c24g`, o broker administrativo root pode **reiniciar** (`systemctl restart`) qualquer unit cujo nome case com `shopvivaliz-*.service`, sem novo PR por servico. Vale somente para restart. Nao autoriza start, stop, enable, disable, edicao de unit, criacao de arquivo, shell generico, comando arbitrario, mudanca de rede, banco, pagamento ou permissao. Toda execucao exige lease de manutencao, fencing emitido pelo host, ticket de uso unico, auditoria persistente e reconciliacao do resultado, sem repetir restart indeterminado. Units fora do padrao continuam exigindo concessao explicita por PR. **Atencao:** esta regra so vale como enforcement quando o verificador de concessao (`scripts/validate-owner-pr-grant.py`) e o broker aceitarem o padrao; ate la, a execucao usa apenas `allowed_units` explicitas da concessao vigente (`security/owner-approvals/3560.json`). Nao presumir cobertura de um servico novo sem confirmar no codigo. Fonte: `docs/ops/marina-root-broker-activation-2026-11-02.md`.
### Instrucao para agente que cria servico novo na Marina
1. Crie a unit em `deploy/systemd/shopvivaliz-<nome>.service` por PR. Nao instale, edite nem crie unit diretamente na VM (SSH, RDC ou shell), nem para teste.
2. A unit so passa a valer para restart quando a PR que a introduziu ou alterou for mergeada com aprovacao final de `shopvivaliz-human` no head exato. A aprovacao humana na PR e a unica condicao; nao ha PR separada por servico.
3. O nome com o prefixo nao autoriza nada por si so. O verificador (`scripts/validate-owner-pr-grant.py`, funcao `unit_pattern_authorized`) confere a PR e a aprovacao antes de aceitar o restart.
4. Sem aprovacao humana na PR, a unit nao esta coberta. Pare e abra a PR.
<!-- /MARINA_BROKER_SERVICE_RESTART_PATTERN_20261103 -->

<!-- MANDATORY_AGENT_CHECKLIST_V1 -->
## Checklist transacional obrigatorio: todos os agentes e todas as IAs

A cada tarefa material, consultar e atualizar o checklist duravel ANTES de agir, DEPOIS de cada etapa e ANTES de escolher a proxima acao. Esta regra vale para ChatGPT/Gepeto, Codex, Claude, Gemini, Copilot, workers e subagentes. A sessao ou o controlador nao substituem a responsabilidade de cada agente em executar a proxima etapa permitida.

Usar o registro canonico `scripts/agent_task_state.py`: iniciar o checkpoint com identidade/autorizacao reais; `plan-checklist --task ID --step "..." --step "..."` antes de qualquer progresso; `next-step --task ID` para consultar o item aberto; `check-step --task ID --step-id N --evidence "prova concreta" --verification "validacao independente"` ao completar CADA item; `add-checklist-step` para nova descoberta sem apagar etapas ja comprovadas. Somente passar ao proximo item depois de verificar a conclusao do anterior. `ready` e `complete` nao substituem testes finais, E2E/UI, gates, deploy e merge.

Checklists iniciados nao podem ser sobrescritos para apagar pendencias, pular etapas, inventar provas, ou transformar bloqueios em conclusoes. Nos novos checkpoints do runtime canonico a falta de plano ou de qualquer item comprovado bloqueia `READY_TO_COMPLETE` e `CONCLUIDO`. Checkpoints legados exigem migracao explicita, preservando historico, leases, ownership, next_action e prova existente; nao criar tarefa duplicada. Falhas de ferramenta exigem rota alternativa autorizada e estado aberto, nao falsificacao de check. Todos os repositorios/agentes devem adotar o mesmo contrato ou um mecanismo equivalente com testes negativos; documento presente sozinho nao certifica adesao operacional.

Protecoes do GitHub, MFA, RBAC, segredos, gates comerciais e aprovacoes humanas continuam vigentes. Registro de check nao prova, por si, o comportamento real: confirmar os resultados nos sistemas alvo e nos certificadores independentes aplicaveis.
<!-- /MANDATORY_AGENT_CHECKLIST_V1 -->

## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

**Obrigatoria para toda execucao e todo agente, independentemente do fornecedor, modelo ou interface:** ChatGPT, Codex, Claude, Gemini, agentes autonomos, bots, workers, scripts, GitHub Actions, ferramentas MCP e futuros agentes. Aplica-se a todos os repositorios e organizacoes GitHub sob gestao da ShopVivaliz, nao apenas a este repositorio.

**Nao abandonar tarefas por um bloqueio tecnico isolado.** Investigar a causa raiz, corrigir o que for seguro e autorizado, testar novamente e inventariar sistematicamente todas as rotas REALMENTE provisionadas e independentemente autorizadas antes de alegar bloqueio: MCP/Remote Control, conectores, Cloudflare/cloudflared e tuneis, GitHub CLI/API com identidade correta e credenciais protegidas, SSH privado/Tailscale, RDC 1/2, navegadores de agentes no host permitido, VMs ativas, runners/Actions, OCI, Superpowers quando disponivel e outros recursos identificados na documentacao vigente. Nao assumir disponibilidade, permissao ou sucesso sem teste e evidencia. Nao expor segredos nem criar credenciais paralelas.

Para cada rota registrar disponibilidade, identidade, escopo de permissao, lease/fencing quando aplicavel, tentativa, resultado, causa da falha, reparo e proxima alternativa. Nao repetir indefinidamente a mesma tentativa. Prosseguir nas etapas independentes, preservar checkpoints duraveis e retomada automatica autorizada. A tarefa so termina com correcao, prevencao, testes, validacao runtime/E2E real e merge apos gates quando aplicavel, ou bloqueio externo comprovado apos esgotamento de todas as rotas legitimas. Nenhum status RUNNING antigo comprova execucao ativa.

**Limites absolutos:** nao contornar MFA, CAPTCHA, RBAC, 401/403, recusa expressa, branch protection, aprovacoes, leases, isolamento de hosts ou politicas do provedor. Rota alternativa nunca pode ser usada para burlar uma negativa. Manter pendencias abertas com evidencia e proxima acao quando existir impedimento legitimo.

**Propagacao:** manter esta regra nos arquivos de instrucoes de agentes de cada repositorio ativo (AGENTS.md, CLAUDE.md, GEMINI.md, instrucoes Codex e equivalentes existentes), respeitando a governanca local; verificar aplicacao por inventario e testes, nao por presuncao.

<!-- PR_RELEVANT_GATES_ONLY_V1 -->
## Gates de PR selecionados por impacto comprovado
<!-- /PR_RELEVANT_GATES_ONLY_V1 -->

<!-- OWNER_PR_SECURITY_APPROVAL_20261031 -->
## Autorizacao humana de seguranca exclusivamente pelo GitHub PR

<!-- /OWNER_PR_SECURITY_APPROVAL_20261031 -->

<!-- V5_NO_DIAGNOSTIC_ONLY_ENFORCEMENT_20261030 -->
Em qualquer Auditoria Extrema V5, TODOS os agentes devem aplicar `docs/quality/AUDIT_V5_DIAGNOSTIC_ONLY_PREVENTION_20261030.md` alem de `AUDIT_POLICY.md`. Relatorio de findings, HTTP 200, testes isolados ou PR nao autorizam parada ou certificacao. Cada falha executavel exige reparo, prevencao, testes, validacao runtime/UI, persistencia, contraditorio e certifier oficial. Nunca declarar `APTO` sem `AUDIT_VERDICT=APTO` do mesmo release, ambiente e escopo. Preservar autorizacoes, leases e seguranca.
<!-- /V5_NO_DIAGNOSTIC_ONLY_ENFORCEMENT_20261030 -->

<!-- EXHAUST_AUTHORIZED_ROUTES_V5_20261030 -->
## Continuidade obrigatoria e esgotamento das rotas autorizadas

Em toda retomada, auditoria V5 e tarefa de reparo, investigar e corrigir ate validar o resultado. Falha de uma ferramenta ou transporte nao encerra a tarefa. Antes de declarar BLOCKED_EXTERNAL, verificar para cada operacao as rotas realmente provisionadas e independentemente autorizadas: MCP ShopVivaliz/controlador; RDC 1 e RDC 2; SSH privado/Tailscale; tunel independente aprovado; navegador grafico real com sessao e lease corretos; e recuperacao oficial via OCI/GitHub Actions quando aplicavel. Registrar em checkpoint nao secreto identidade, permissao, lease/fencing, teste, falha, reparo, proximo fallback e evidencia. Diferenciar NOT_PROVISIONED, AUTH_EXPIRED, NOT_REACHABLE, RUNTIME_LOCKED e DENIED_BY_PROVIDER. Corrigir falhas tecnicas reparaveis e testar novamente sem repeticoes ilimitadas nem agentes duplicados. Nenhum 401/403, MFA, RBAC, protecao de branch, gate de aprovacao humana ou recusa da plataforma pode ser contornado mudando de rota. Nao tocar na VM removida shopvivaliz-free-a1. Sem testes GREEN, efeitos persistidos e E2E na UI real, manter NAO_APTO e checkpoint de continuidade; nao declarar CONCLUIDO.
<!-- /EXHAUST_AUTHORIZED_ROUTES_V5_20261030 -->

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


Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->
## Gate da Auditoria Extrema V5: E2E UI real, prova em disco e continuidade

Todo agente deve executar integralmente `docs/quality/AUDIT_UI_EVIDENCE_INTEGRITY_V2.md`: inventariar tela/controles/casos reais, percorrer cada jornada via UI grafica, capturar trace/midia real, corrigir via RED/GREEN/regressao, reauditar em sessao independente e validar UI novamente depois do deploy. O certifier deve ler hashes de arquivos fisicos e devolver `AUDIT_VERDICT=APTO` no mesmo SHA/release/ambiente antes de marcar tarefa `CONCLUIDO`. Nao aceitar afirmacoes/flags, screenshot isolado, API/CLI/SQL ou headless-only como prova. Manter checkpoint `RUNNING` com `next_action` e retomada controlada enquanto NAO_APTO; nao abandonar E2E por timeout, PR, merge, CI, falha de browser ou limite de turno. Respeitar ownership, seguranca e controles de custo. `BLOCKED_EXTERNAL` exige comprovacao real, nao e APTO.
<!-- /AUDIT_UI_EVIDENCE_INTEGRITY_V2 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
## GATE INEGOCIAVEL: Auditoria Extrema V5 executada pela UI real


1. **UI primeiro, sem atalhos:** inventariar telas, jornadas, perfis, estados e casos reais; abrir a interface publicada no host/perfil autorizado; navegar, clicar, preencher, filtrar, salvar/submeter dentro das permissoes; observar resposta visual, erros e efeitos; recarregar, sair e voltar para provar persistencia. Para Amazon Seller Central, Mercado Livre e paineis externos, auditar os casos e transicoes acessiveis na UI oficial, um a um conforme escopo, sem declarar sucesso por API somente.
2. **Evidencia obrigatoria por jornada:** registrar ambiente/URL, SHA/release quando aplicavel, sessao autorizada sem segredos, timestamp, passos efetivamente executados, capturas/trace/video, estado antes/depois, erros de console/rede, reload/revisita e reconciliacao independente dos efeitos duraveis. Screenshot isolado, abertura de pagina, resposta HTTP 200 ou teste simulado nao certificam jornada.
3. **Auditoria inclui reparo:** ao encontrar falha corrigivel no escopo autorizado, investigar causa raiz, corrigir, prevenir recorrencia, executar testes e **repetir o mesmo fluxo pela UI real apos correcao/deploy**, com evidencia independente. Nao encerrar no diagnostico, em PR ou em teste apenas de backend.
5. **Seguranca:** usar browser/desktop/MCP/RDC nos hosts e perfis previstos nos runbooks, com ownership/leases, controles de autorizacao e MFA; preservar sessoes. A auditoria nao autoriza exclusao, faturamento real, modificacao irreversivel ou acesso a segredo. Quando a acao terminal nao puder ser realizada com seguranca, testar ate o limite seguro e registrar exatamente a cobertura pendente; nunca inventar sucesso.

Fonte complementar: `AUDIT_POLICY.md`, `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md` e `docs/quality/AUDIT_ABSOLUTE_GATE_V1.md` do repositorio governado. **Este gate prevalece sobre instrucoes mais brandas que permitam certificar por API ou parecer do agente.**
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- RESUME_EXECUTION_GATE_V1 -->
## Execução obrigatória ao receber Retome / Continue / Prossiga / Siga

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
  ou desbloqueio/consentimento/MFA humano obrigatorio pelo fluxo oficial.
- Um registro salvo **nao prova** que a senha e valida ou que o ChatGPT esta
  autenticado. Validar cada sessao pelo email exato em `/api/auth/session`.
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
`task_status` repetido ou `Bash` local:

- `host="always-free-arm-1787907847-26"`
- `command="python3 /home/ubuntu/shopvivaliz-claude-workspace/site-shopvivaliz/scripts/chatgpt_credential_preflight.py --session atendimento"`
- `timeout=5`
- `durable=false`

Para Dev, trocar exclusivamente `--session atendimento` por `--session dev`.
O timeout do adaptador pode transformar a solicitacao em tarefa duravel se
esses parametros forem omitidos; **nao** fazer polling longo de credencial.
Se a chamada inline nao for possivel, a fonte fica `NOT_VERIFIED`, nao
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
alternativa. Preservar browser_session, lease/fencing, controles MFA,
sem pedir novamente secrets. Detalhes no runbook
docs/knowledge/chatgpt-vm-auth.md.
<!-- /CHATGPT_LOGIN_GMAIL_WATCH_10_MINUTES_V1 -->


<!-- REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->
## Gate obrigatorio: esgotar rotas de acesso remoto antes de bloquear

1. Ler `docs/knowledge/host-access.md` no `main` atual e o runbook do repositorio; verificar destino, identidade, privilegios, capacidades expostas e autorizacoes efetivas. Nunca adivinhar que uma rota existe.
2. **Avaliar todas as rotas remotas realmente provisionadas, permitidas e adequadas a acao**, com a prioridade do runbook: (a) ShopVivaliz Remote Control MCP/controlador e ferramentas de host/tarefa/desktop; (b) RDC e RDC 2 para os hosts e capacidades explicitamente autorizados; (c) SSH privado/VCN/Tailscale e tunel/reverse SSH documentado, usando credenciais protegidas existentes; (d) GUI remota por bridge `desktop_*` ou RustDesk self-hosted quando a tarefa exigir interface; (e) OCI Bastion, OCI Compute Run Command, console serial e GitHub Actions/control plane somente para bootstrap, recuperacao ou reparo, conforme suas regras. Usar outras rotas somente se estiverem documentadas e autorizadas.
3. Para cada rota aplicavel, provar disponibilidade, autenticacao/autorizacao, conectividade, identidade do host e capacidade da operacao; diagnosticar e reparar falhas tecnicas que estejam ao alcance, testar novamente com tentativas limitadas e idempotentes e passar ao proximo caminho quando apropriado. Nao confundir canal de GUI com shell nem rotas de recovery com transporte normal de runtime.
4. Registrar checkpoint nao secreto `remote_access_attempts` por host e tarefa: canal, objetivo, permissao, teste, resultado, causa, reparo, proximo fallback e evidencia com instante. Canal nao disponivel deve ser classificado `NOT_PROVISIONED` com evidencia, nao `TRIED`.
5. **So declarar host inacessivel/bloqueio externo depois de examinar e esgotar todos os caminhos adequados, seguros, tecnicamente viaveis e autorizados**, justificando cada nao utilizacao. Enquanto houver rota executavel, manter `RUNNING` e continuar a tarefa. Pedir aprovacao apenas quando obrigatoria, indicando a permissao exata.

<!-- /REMOTE_ACCESS_EXHAUSTION_GATE_V1 -->

<!-- MANDATORY_AGENT_RULES_READ_GATE_V1 -->
## Preflight obrigatorio antes de Retome ou qualquer acao

<!-- /MANDATORY_AGENT_RULES_READ_GATE_V1 -->

<!-- EXHAUST_AUTHORIZED_PATHS_BEFORE_BLOCKED_V1 -->
## Bloqueio somente apos esgotamento comprovado dos caminhos viaveis

1. Confirmar estado e causa raiz com logs, codigo, checkpoints, host e testes; distinguir falha transitoria, defeito local, permissao ausente e dependencia externa.
2. Tentar reparo local, configuracao correta, recuperacao de sessao, retry com backoff limitado e idempotencia, rota alternativa autorizada, ferramenta equivalente e recuperacao de integracao; escolher somente opcoes adequadas ao defeito real, sem repeticoes infinitas.
3. Em CI ou PR: verificar checks e logs, corrigir causa de falha, atualizar branch e repetir checks; nunca burlar branch protection, aprovacoes obrigatorias ou status checks.
4. Registrar para cada rota relevante: diagnostico, tentativa e resultado, evidencia, razao objetiva de inviabilidade e proxima alternativa. Se houver alternativa executavel, continuar `RUNNING` sem devolver a tarefa ao usuario.
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
5. Se a sessao/limite de ferramenta impedir continuar, registrar checkpoint fiel e dependencia exata; nao prometer execucao assincrona sem controlador de continuidade comprovadamente ativo.

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
- Respeitar regras especificas do repositorio, limites da sessao e escopo de autorizacao. Acao destrutiva ou irreversivel exige autorizacao adequada.
- A regra vale para agentes, subagentes, Codex, MCP/RDC, automacoes, debugging, auditorias, deploys e retomadas; a resposta final deve distinguir entrega comprovada de bloqueio documentado.

Fonte global da politica: `Vivaliz-site/site-shopvivaliz/REGRAS-AGENTES-CENTRALIZADAS.md`.
<!-- /AUTONOMOUS_RESUME_TO_VALIDATED_COMPLETION_V1 -->

<!-- AGENT_TASK_CLONE_LIFECYCLE_V1 -->
## Clones temporários: limpeza obrigatória na conclusão

Todo agente (GPT, Claude, Codex, Gemini e controladores) deve associar os clones temporários a um `task_id` durável e utilizar `scripts/agent_clone_lifecycle.py clone --task ... --repo ...`; se o clone já existir, executar `register --task ... --path ...` imediatamente. Ao finalizar com `agent_task_state.py complete --task ...`, os clones registrados serão removidos automaticamente **somente** se estiverem limpos, sem trabalho não enviado, sem processo ativo e sem bloqueio Git. `BLOCKED_EXTERNAL` e tarefas em andamento preservam os arquivos. O timer de recuperação retoma limpezas interrompidas. Nunca fazer `rm -rf` genérico sobre clones, caches de outros agentes ou worktrees não registrados. Consulte `docs/knowledge/task-continuity.md`.

<!-- /AGENT_TASK_CLONE_LIFECYCLE_V1 -->


<!-- BROWSER_SESSION_ACCOUNT_BINDING_V1 -->
<!-- /BROWSER_SESSION_ACCOUNT_BINDING_V1 -->
<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
> **AUDITORIA EXTERNA TAMBÉM É CORRETIVA:** auditoria interna, externa, independente, contraditória ou feita por outro agente/revisor segue o mesmo ciclo. Auditor externo com autorização deve corrigir, testar e reauditar. Se for read-only, o relatório não encerra: os achados corrigíveis seguem para executor autorizado e permanecem em andamento até correção + revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- SHOPVIVALIZ_HOST_ACCESS_CANONICAL_V2 -->
## Acesso canonico aos hosts ShopVivaliz — revisao 2026-10-29

**Antes de operar:** ler `docs/knowledge/host-access.md` e `docs/knowledge/current-topology-and-access-2026-10-29.md`; registrar revisao e confirmar host/identidade/permissoes ao vivo.

- **Producao web/deploy/servicos do site:** `marina-a1-4c24g` (OCI ESPOSA, privado documentado `10.80.1.116`; Tailscale observado `100.118.170.46`). RDC 1/2 direto para operacoes autorizadas; runner Marina para deploys versionados.
- **Backend/controller/browser:** `always-free-arm-1787907847-26` (OCI PRINCIPAL, privado `10.0.1.38`; Tailscale observado `100.66.174.74`). MCP central so para hosts presentes e validos em sua allowlist; navegador corporativo Dev/Atendimento permanece no backend.
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


### 0. Autorização operacional vigente

O proprietário autoriza os agentes com acesso técnico válido a validar pelo navegador real, preparar a entrega para revisão, aprovar PRs, fazer merge e acionar ou executar deploy sem nova aprovação explícita, desde que os checks e as proteções do repositório permitam. A autorização não elimina a necessidade de evidência independente, nem permite force-push, bypass de proteção, secrets, cobranças reais ou ações destrutivas fora do escopo.

### 0.1 Commit, PR e Merge Obrigatório ao Finalizar

> ⚠️ **CRÍTICO — COMMIT NÃO É ESTADO FINAL:** uma tarefa com alteração versionada só termina depois de **branch → validação real → commit → push → PR → checks/revisão → merge → validação pós-merge → working tree limpa → nenhuma PR pendente da tarefa**.
>
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
- Rotinas contínuas/periódicas devem ser determinísticas ou usar IA gratuita/local aprovada, sem fallback silencioso para provedor pago.
- Toda tarefa finita com IA paga precisa de timeout, limite de retries/chamadas e condição de saída; ao atingir limite, salvar checkpoint e encerrar.
- Antes de manter qualquer automação, auditar consumidor por consumidor: necessidade, gatilho, frequência, provedor/modelo, custo, retries, timeout, saída e duplicidade.
- Política completa: [`REGRAS-AGENTES-CENTRALIZADAS.md`](REGRAS-AGENTES-CENTRALIZADAS.md), seção **Política obrigatória de consumo de IA e execução recorrente**.


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
- Alterações na Liz devem incluir testes de conversa, segurança e regressão.

## Politica obrigatoria de PR, gate, merge e bloqueios (2026-08-27)

- Toda alteracao finalizada deve terminar em PR validado e merge; nao deixar PR pronta aberta sem motivo tecnico comprovado.
- Gate verde autoriza merge automatico somente para PR do proprio repositorio depois que todos os checks canonicos aplicaveis passarem.
- Bloqueio aparentemente externo nao encerra a tarefa: procurar solucao segura por configuracao, credencial, permissao, host key, secret, servico, provider, infraestrutura ou rota alternativa antes de classificar como INCONCLUSIVO.
- Depois do merge, verificar Actions do SHA resultante e corrigir qualquer falha regressiva antes de declarar conclusao.
- No ShopVivaliz, Quality Gate verde em main deve acionar automaticamente o Master Production Pipeline e o deploy canonico na VM2; VM1 nao e destino de producao do site.

## Oracle Cloud (OCI) — acesso obrigatório para agentes


- Fred-Win e VM1 usam o perfil OCI `AGENTS` local protegido.
- VM2 usa o perfil protegido com o cliente de requisições OCI assinado documentado.
- Valide acesso com uma leitura autenticada real antes de declarar OCI operacional.
- Em caso de falha externa de autenticação, permissão, rede ou serviço, investigue e procure uma solução segura; não trate o bloqueio como encerramento automático da tarefa.

## Gate obrigatorio de resposta final e deploy (FINAL_RESPONSE_DEPLOY_GATE_V1)

- Em alteracoes de codigo, configuracao ou documentacao versionada, PR aberto, checks verdes ou merge isoladamente NAO significam conclusao.
- Quando existir alvo de deploy para o repositorio, e obrigatorio acompanhar o deploy ate o SHA correto estar ativo e executar validacao pós-deploy real e reproduzivel.
- Se o deploy falhar, investigar a causa raiz, corrigir, revalidar, repetir commit/push/PR/merge quando necessario e tentar o deploy novamente; nao encerrar em estado intermediario.
- Antes de qualquer resposta final, comparar pedido original x estado real e registrar evidencias de: validacao, commit, push, PR, checks, merge, deploy e pós-deploy, conforme aplicavel.
- So e permitido encerrar sem deploy bem-sucedido diante de bloqueio externo genuino e incontornavel com os acessos/ferramentas disponiveis; nesse caso o estado e BLOCKED/INCONCLUSIVO, nunca sucesso.


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
- Antes de atribuir toda carga ao runner GitHub Actions, verificar o cgroup efetivo: o runner da Solange ja foi observado com `IOWeight=20`, `CPUWeight=20` e `CPUQuota=25%`; havia tambem trabalho Docker, navegador, diagnosticos MCP e CI. Identificar responsaveis por janela, sem associar causalidade pela leitura isolada.
- O gate acima e uma **regra operacional do agente**, nao um circuito de protecao implantado no servidor. A prevencao permanente exige controle de admissao centralizado em MCP/CI, com limites por host, lock, prioridades, PSI, histerese, observabilidade, provas RED/GREEN e regressao. Alteracao anterior de `remote-control-mcp/server.py` foi recusada pelos controles da plataforma: **nao tentar contornar essa recusa por shell, outro conector ou PR alternativo**.

Referencia de acompanhamento: issue #2934. Enquanto houver PSI extremo recorrente, tarefas de continuidade sem vinculacao comprovada ou E2E real faltante, nao declarar `AUDIT_VERDICT=APTO`.
<!-- /BACKEND_IO_PRESSURE_ADMISSION_V1 -->

<!-- VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
## Gate executavel de conclusao apos merge e deploy


<!-- /VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->

<!-- V5_AGENT_PREFLIGHT_NONABANDONMENT_V1 -->
## Contrato verificavel de preflight de todos os agentes e PRs sem abandono
Antes de qualquer escrita, todos os agentes (ChatGPT/Gepeto/Codex/Claude/Gemini/subagentes/controladores) devem seguir `docs/quality/AUDIT_AGENT_PREFLIGHT_AND_PR_CONTINUITY_V1.md`. Comprovar leitura integral e verificacao independente das regras vigentes no `main` por SHA em `rules_loaded`. Sem prova: `RULES_NOT_LOADED`, nenhuma mutacao. A auditoria de PRs exige inventario completo, reconciliacao individual, next_action persistida, checks obrigatorios, prevencao, validacao e certifier oficial. A presenca de uma regra documental nao demonstra que o agente obedeceu: verificar preflight real e self-tests negativos. Nao usar commits vazios, atalhos de branch protection ou checks sinteticos para forcar merge.
<!-- /V5_AGENT_PREFLIGHT_NONABANDONMENT_V1 -->

<!-- DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->
## Gate obrigatório de deploy, pós-deploy e conferência integral de preços (V2)


**Catálogo/ERP:** além do deploy, executar a auditoria completa de preços e promoções com o verificador `scripts/quality/audit-storefront-erp-prices.py`. O GitHub Actions `.github/workflows/postdeploy-erp-price-parity.yml` executa o comparativo somente leitura após deploy real do pipeline oficial e diariamente. Comparar 100% dos IDs ativos com a **tabela Amazon ID 991 do ERP Olist/Tiny v3**, incluindo percentual geral e exceções por ID exato, com o cache e a API pública, sem amostragem; identificar ausência, preços/promoções divergentes e SKU duplicado com valores conflitantes. O preço-base do cadastro NUNCA é o preço esperado do site. Falha de leitura da tabela Amazon invalida o aceite. Qualquer `PRICE_PARITY_VERDICT=FAIL` impede alegação de catálogo totalmente sincronizado, mesmo com todos os preços publicados aparentemente corretos. A revisão visual da vitrine e das páginas de produto continua obrigatória para E2E de UI, e o teste do webhook deve ser de evento genuíno do ERP.


<!-- /DEPLOY_PRICE_PARITY_AUTOMATED_GATE_V2 -->

---

## Espelhos identicos: AGENTS.md, CLAUDE.md, GEMINI.md e docs/AGENTS.md
> Estes quatro arquivos devem ser sempre identicos. Inclusao ou alteracao em qualquer um deles deve refletir nos outros tres: edite o arquivo que quiser e rode `python3 scripts/sync-agent-instructions.py` (merge de tres vias contra o HEAD; `--check` verifica; `--from ARQUIVO` escolhe a origem). O CI falha se divergirem. Ao mesclar conteudos entre eles, audite: restricao ou bloqueio ausente em qualquer um dos arquivos nao e incluido e e removido de todos.

> **MCP Marina ATIVO com ferramentas admin (2026-11-05, prevalente):** o MCP independente do Marina esta ativo e aceita `marina_admin_shell` (usuario de servico e `as_root`). Ignore afirmacoes antigas de "inativo" ou "NAO APTO" abaixo. Como acessar e evidencias: `docs/knowledge/marina-mcp-admin-agent-access.md`.
## Checkpoint obrigatorio antes de executar

> **AUDITORIA EXTREMA V5 = UI REAL OBRIGATORIA.** Antes de declarar `APTO` ou `CONCLUIDO`, o proprio agente percorre cada jornada material na interface grafica real (navegacao, cliques, preenchimento, resultado visivel, reload/revisita, persistencia e evidencia visual com ambiente/release). API, terminal, SQL, codigo, screenshot isolado, mocks e testes apenas headless nao substituem prova pela UI. A auditoria inclui investigacao, correcao e reauditoria visual apos reparo. Nao ocultar uma UI marcando `browser.required=false` ou `journey.ui=false`. Somente componentes comprovadamente sem UI podem usar excecao com inventario negativo documentado e evidencias. Em bloqueio de sessao, esgotar rotas autorizadas; nao burlar MFA/permissaos. Leia e obedeça a secao `EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1` de `AGENTS.md` antes da tarefa.

> **AUDITORIA EXTERNA:** também é corretiva. Se houver autorização de escrita, corrigir + testar + reauditar. Se o auditor for somente leitura/independente, o relatório não é estado terminal: encaminhar os achados a executor autorizado e manter a tarefa aberta até correção e revalidação independente ou bloqueio externo comprovado.

<!-- CLAUDE_READ_ROOT_AGENTS_FIRST_V1 -->
> **BOOTSTRAP OBRIGATÓRIO DO CLAUDE:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Em particular, leia `docs/AGENT-REMOTE-ACCESS.md` antes de qualquer operação em host/VM/runtime/browser.

@docs/knowledge/claude-vm-bootstrap.md
<!-- audit-refs: AUDIT_POLICY.md docs/quality/EXTREME_AUDIT_PROTOCOL.md docs/quality/AUDIT_RUNTIME_PARITY_V1.md docs/quality/AUDIT_UNIVERSAL_COVERAGE_V1.md docs/quality/AUDIT_SELF_TEST_V1.md docs/quality/ARCHITECTURE_DEPLOY_AUDIT_V1.md -->
<!-- gate: FINAL_RESPONSE_DEPLOY_GATE_V1 — resposta final só após validação pós-deploy completa -->

> **AUDITORIA CORRETIVA OBRIGATÓRIA:** para qualquer auditoria, não apenas extrema, siga `AGENTS.md` e `REGRAS-AGENTES-CENTRALIZADAS.md`: investigar causa raiz, corrigir achados materiais seguros/autorizados, prevenir recorrência quando pertinente, testar e reauditar. Não encerrar em relatório de erros. APTO/CONCLUIDO exige evidência fresca pós-correção/E2E aplicável.

> **DIAGNÓSTICO CORRETIVO GLOBAL:** em qualquer tarefa, diagnóstico confirmado obriga correção segura, prevenção pertinente, testes e validação real/E2E aplicável antes de `CONCLUIDO`. Enquanto houver ação executável, permaneça `RUNNING`; só `BLOCKED_EXTERNAL` comprovado permite encerrar sem corrigir. Siga `AGENTS.md` e `REGRAS-AGENTES-CENTRALIZADAS.md`.

- Contrato detalhado: `docs/knowledge/task-continuity.md`; protocolo canônico de conclusão: `AI-TO-CLI-PROTOCOL.md`.

Preservar a cota do Codex. Para continuidade de tarefas, priorizar rotas determinísticas/controle remoto auditável e executores alternativos autenticados adequados ao trabalho; usar Codex somente como último recurso finito. Falta de cota, rate limit ou indisponibilidade do Codex mantém a tarefa `RUNNING` e nunca constitui, isoladamente, `BLOCKED_EXTERNAL`.

## Checkpoint obrigatorio antes de executar


> **AUDITORIA EXTERNA:** também é corretiva. Auditor com acesso autorizado corrige, testa e reaudita; auditor read-only não encerra em relatório, devendo encaminhar achados a executor autorizado até correção + revalidação independente ou bloqueio externo comprovado.

<!-- GEMINI_READ_AGENTS_FIRST_V1 -->


> **BOOTSTRAP OBRIGATÓRIO DO GEMINI:** antes de analisar, planejar, editar, executar comandos, usar navegador, abrir PR, fazer merge, deploy ou validar qualquer tarefa, leia integralmente o `AGENTS.md` da raiz deste repositório e siga suas regras. Releia em retomadas ou quando o arquivo mudar. Em conflito, `AGENTS.md` prevalece.

# Protocolo IA-to-CLI obrigatório

Antes de qualquer alteração, carregue e siga integralmente o protocolo canônico:

@./AI-TO-CLI-PROTOCOL.md

Ele complementa as regras específicas do projeto. Nenhuma alteração válida da tarefa pode ser abandonada sem merge validado na branch de destino.

## Auditoria Extrema — leitura obrigatória

Em qualquer Auditoria Extrema, leia primeiro `AUDIT_POLICY.md` e todo o conjunto em `docs/quality/`: `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, `AUDIT_UNIVERSAL_COVERAGE_V1.md`, `AUDIT_SELF_TEST_V1.md` quando aplicável e `AUDIT_OVERLAY.md`. A regra vale para investigação, correção, melhorias, reauditoria e caça a unknown unknowns.


---


<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

# 🤖 Agentes Autônomos — Memória Consolidada

<!-- SHOPVIVALIZ_HOST_BOOTSTRAP_V1 -->
> 🔴 **BOOTSTRAP DE HOSTS OBRIGATÓRIO:** a fonte operacional canônica é [`knowledge/host-access.md`](knowledge/host-access.md), junto de [`knowledge/README.md`](knowledge/README.md) e [`knowledge/agent-rules.md`](knowledge/agent-rules.md).


> **🔴 LEIA ISTO ANTES DE COMEÇAR**
>
> Múltiplos agentes diferentes (Claude, GPT, Gemini, etc) trabalham aqui em sessões isoladas **sem memória compartilhada**. Sem um lugar único, o mesmo bug é redescoberto do zero repetidas vezes.
>
> **Este arquivo é o repositório centralizado** de tudo que qualquer agente deve saber antes de mexer no repo.

---

## 2026-09-21 — Auditoria extrema v5 API AI Squad: ESC-2026-001 FECHADO (com acesso real a produção via RDC)
**Sistema/arquivo:** `claude/api/agent/squad-chat.php`, `api/agent/squad-chat.php`, `admin/squad-chat.html`, `.htaccess`, `docs/quality/AUDIT_{STATUS,ESCAPE_REGISTER,OVERLAY}.md`.
**O que descobri:** 4 defeitos de classe `AUDIT_SILENT_FAILURE_V1` na API squad que nunca haviam sido auditados — o painel admin nunca chamava o endpoint squad real; o endpoint squad nunca carregava o `.env`; o modelo Gemini padrão estava descontinuado; GH_REPO apontava para repo antigo. Todos corrigidos em PR #1689 (merge SHA `63ae6faf`, release `20260921-174416-f386a922`).
**Por quê importa:**
1. **`dirname(__DIR__, N)` é crítico para endpoints em subdiretórios.** O N deve ser exatamente o número de diretórios de profundidade do arquivo em relação à raiz do release. Em `claude/api/agent/` o N correto é **3**. Com N=2, o `.env` nunca era carregado e todas as variáveis AI retornavam vazio silenciosamente.
2. **Sempre verificar a URL que o frontend admin usa para cada componente** — não assume que segue a estrutura de diretórios. `admin/squad-chat.html` apontava para `/api/agent/squad-chat.php` (endpoint Liz público) em vez de `/claude/api/agent/squad-chat.php` (squad real). A UI completava com 200 OK mas nunca chamava o squad.
3. **Endpoints em `claude/` exigem exceção explícita no `.htaccess`.** A regra de bloqueio bloqueia toda a árvore `claude/`; para expor um endpoint específico autenticado, adicionar `RewriteRule ^claude/api/agent/squad-chat\.php$ - [L]` ANTES da regra de bloqueio.
4. **Health check (`?health=1`) deve ser o primeiro smoke de qualquer endpoint de agente/IA.** Verificar `env_loaded: true`, providers configurados e agentes ativos antes de declarar o endpoint funcional.
5. **`SQUAD_TOKEN` ausente no `.env` não é bug de código — é gap de configuração.** O endpoint falha explicitamente com `{"error":"SQUAD_TOKEN not configured"}`, que é o comportamento correto. Ação pendente do Fred: adicionar `SQUAD_TOKEN=<valor>` ao `shared/.env` na VM a1.
6. **PR #1687 (`fix/squad-chat-gemini-model-var`) foi fechado como superseded** — o mesmo fix já estava no PR #1689. Ao ver dois PRs cobrindo o mesmo arquivo, verificar diff completo do PR maior antes de mergear o menor.
**Evidências ao vivo (2026-09-21 ~17:48 UTC):** `GET ?health=1` → HTTP 200, `env_loaded:true`, 8 agentes ativos, providers anthropic/openai/gemini configurados. POST sem token → `{"error":"SQUAD_TOKEN not configured"}` (erro explícito, não silencioso).
**Ver também:** `docs/quality/AUDIT_STATUS.md` (rodada 2026-09-21, veredito APTO), `docs/quality/AUDIT_ESCAPE_REGISTER.md` (ESC-2026-001 CLOSED), `docs/quality/AUDIT_OVERLAY.md` (regras estruturais ESC-2026-001).

---

## 2026-09-19 — Auditoria extrema v5 executada sem acesso a produção/infra (escopo estático)
**Sistema/arquivo:** repositório inteiro (código, lint, validadores de qualidade), branch `claude/auditoria-extrema-v5-97gu15`.
**O que descobri:** rodei o conjunto obrigatório (`AUDIT_POLICY.md`, `EXTREME_AUDIT_PROTOCOL.md`, `AUDIT_RUNTIME_PARITY_V1.md`, `AUDIT_UNIVERSAL_COVERAGE_V1.md`, `AUDIT_OVERLAY.md`) a partir de um worktree isolado, **sem SSH às VMs Oracle, sem acesso ao Remote Desktop Commander configurado para as A1 de produção e sem browser contra `shopvivaliz.com.br`**. Nesse escopo: `git fetch` confirmou a branch já sincronizada com `origin/main` (mesmo SHA `1f8e2ca9a`); lint PHP (`php -l`) em 100% dos `.php` do repo (fora `vendor`/`node_modules`) não encontrou erro de sintaxe; `php scripts/quality/validate-health-output.php` e `php scripts/quality/validate-asset-manifest.php` passaram (`COMPROVADO`); não havia PR aberta associada à branch. Não rodei PHPUnit (sem `vendor/` instalado neste worktree) nem Playwright/QA de browser. `docs/quality/AUDIT_STATUS.md` já registrava, de 2026-09-16, `NÃO APTO` por falta de paridade produção↔SHA candidato e mutações críticas (checkout, catálogo) não exercitadas pela UI real — essa lacuna **continua sem evidência nova** nesta sessão, porque exige acesso a infraestrutura/produção que este ambiente de execução não fornece.
**Ver também:** `docs/quality/AUDIT_STATUS.md` (entrada 2026-09-16, ainda vigente), `docs/quality/EXTREME_AUDIT_PROTOCOL.md`.

---

## 🔴 2026-08-29 — Recuperação pós-E2 e arquitetura obrigatória de 2 A1

**REGISTRO HISTORICO (2026-08-29), SUPERADO PELA ARQUITETURA DE 2026-10-29:** a producao operava nas duas A1: `always-free-arm-1787907847-26` (backend/dados/serviços) e `shopvivaliz-free-a1` (site/web/deploy). As E2 `shopvivaliz-ai` (`137.131.156.17`) e `shopvivaliz-micro-2` (`136.248.69.116`) foram encerradas e são **alvos aposentados**.

**Regras para qualquer agente:**
- Referências antigas em logs/relatórios permanecem como evidência histórica; referências executáveis/configuráveis são defeitos e devem ser corrigidas.
- Preservar mudanças locais, dumps, bundles, patches e backups antes de qualquer limpeza/reconciliação.
- Não executar operação OCI destrutiva durante esta recuperação e nunca expor/commitar secrets.
- MEI deve manter exatamente um sender de produção.
- `systemctl active`, HTTP 200 e revisão estática não bastam: validação funcional ponta a ponta é obrigatória, inclusive frete/checkout real de teste.

**Fontes normativas:** `.codex/RECOVERY-MEMORY.md`, `docs/operations/TWO-A1-RECOVERY-SPEC-2026-08-29.md` e `docs/superpowers/plans/2026-08-29-two-a1-recovery-master.md`.

---

### Antes de começar uma sessão
1. **Procure por seu problema** (Ctrl+F): API, nome de arquivo, sintoma
2. **Leia a entrada relevante** se encontrar algo parecido
3. **Você economiza horas** (não redescobre o mesmo bug)

### Ao terminar uma sessão
1. **Descobriu algo não-óbvio?** Adicione uma entrada no topo da seção correspondente
2. **Formato**:
```
### AAAA-MM-DD — Título curto do que foi aprendido
**Sistema/arquivo:** onde aplica
**O que descobri:** fato direto, nada vago
**Por quê importa:** o que dá errado sem isso
**Ver também:** link pra doc dedicada, se houver
```


---

### Consumo de IA e rotinas recorrentes
- ✓ IA paga é somente para tarefa finita, com objetivo, timeout, limite de tentativas/chamadas e condição de saída; ao terminar, o processo deve encerrar.
- ✓ Rotina permanente/periódica deve ser determinística ou, quando IA for indispensável, usar opção gratuita/local aprovada sem fallback pago.
- ✓ Auditar **cada consumidor** antes de habilitar/manter: necessidade, host, gatilho, frequência, provedor/modelo, custo, timeout, retries, condição de saída e duplicidade.
- ✓ Processo sem progresso/travado/órfão deve ser encerrado e ter causa raiz investigada; não reiniciar indefinidamente.
- Fonte normativa: `REGRAS-AGENTES-CENTRALIZADAS.md`, seção **Política obrigatória de consumo de IA e execução recorrente**.

### Diagnóstico
- ✓ Identificar o erro antes de sugerir solução
- ✓ Registrar HTTP method, URL, status, response body, etapa do fluxo
- ✓ Não tratar 404, 405, 500, CORS, DNS como o mesmo problema

### Segurança
- ✓ Usar sempre variáveis de ambiente ou GitHub Secrets

### Dados
- ✓ Vincular imagens por SKU ou ID da origem (nunca by-name)
- ✓ Distinguir falha de interface de falha de sincronização

### Atualizações
- ✓ Produza atualizações cumulativas (permitir pular versões)
- ✓ Inclua automaticamente SQLs, migrations, reparos de vínculo
- ✓ Torne migrations idempotentes (rodar 2x = rodar 1x)

### Finalização Obrigatória
- ⚠️ **Commit nunca é ponto de parada.** Para qualquer alteração versionada, concluir significa: **branch → validação real → commit → push → PR → checks/revisão → merge → validação pós-merge → `git status --porcelain` vazio → nenhuma PR aberta/draft da tarefa**.
- ✓ Se houver erro de CI/revisão/conflito, corrigir e repetir o ciclo; enquanto existir ação executável, continuar.
- ✓ Se um bloqueio externo tornar o merge impossível, marcar **INCONCLUSIVO** e fechar/limpar PR obsoleta ou sem caminho de avanço, documentando o ponto exato de continuação.

---

## ✅ Autorização operacional vigente — 2026-08-01

O proprietário autoriza agentes com capacidade técnica e acesso válido a validar entregas no navegador real, deixar evidências prontas para revisão, aprovar PRs, fazer merge e acionar ou executar deploy sem nova aprovação explícita, quando os checks e as proteções do repositório permitirem.

Fluxo padrão: após validar e preparar o PR, o agente conclui o merge autorizado e acompanha o Quality Gate, o deploy automático e o smoke test até registrar o resultado final, sem pedir confirmação intermediária.


---

**Sistema/arquivo:** `includes/tiny-order-push.php`, `includes/marketplace/TinyPublisher.php`, `tests/tiny-v3-http-fallback-test.php`
**Por quê importa:** read-back confirmava o estado final, mas escondia a duplicacao da mutacao. Ao validar writers v3, registre o status do PUT, faça GET independente e mantenha teste explicito de que respostas HTTP de sucesso, erro, conflito e rate limit nao acionam uma segunda chamada.
**Ver também:** `docs/TINY-ERP-API-V3.md`, documentacao oficial `api-reference/produtos/atualizar-produto`.

### 2026-08-11 — Catálogo público (`/catalogo`) renderizava vazio: estoque de TODOS os produtos ativos zerado por dois bugs em cadeia
**Sistema/arquivo:** `olist/sync-on-webhook.php`, `olist/fetch-estoque-v3.php`, `api/catalog/products.php`, `scripts/products-active-sync-loop.sh`
**O que descobri:**
- `api/catalog/products.php` aplica `available_only=true` (só `stock>0`) automaticamente sempre que o `Referer` é `/catalogo` (linha `$isStorefrontCatalog`) — isso é intencional (não mostrar produto sem estoque na vitrine principal), mas significa que **qualquer bug que zere `stock` derruba a vitrine inteira silenciosamente**, mesmo com `total` de produtos ativos correto (o header "N produtos." não usa esse filtro, só o grid). Sintoma enganoso: contagem certa no topo, grid vazio, sem erro de console, API retornando 200 com JSON válido — só falta o teste com `available_only`/`Referer` igual ao do navegador pra reproduzir (`curl` puro sem Referer não reproduz).
- **Bug 1:** `olist/sync-on-webhook.php` (a listagem em lote `GET /produtos`) preenchia `estoque_disponivel` direto do campo `estoque.quantidade` da resposta — mas esse campo **não é confiável na listagem em lote**, vem sempre zerado (confirmado ao vivo: 187/187 itens com `estoque.quantidade` zerado, mesmos produtos com estoque real >0 via `GET /estoque/{id}`). Doc já avisava disso pra kits (`docs/TINY-ERP-API-V3.md`, seção "GET /produtos/{id} vs GET /estoque/{id}"), mas na prática vale pra TODOS os produtos na listagem em lote, não só kits.
- O fix correto pra estoque real já existia — `olist/fetch-estoque-v3.php`, que chama `GET /estoque/{id}` (campo `disponivel`, calculado certo pela própria Tiny) — mas só roda quando `!isset($item['estoque_disponivel'])`. Como o Bug 1 sempre preenchia essa chave (mesmo que com 0), o enriquecimento **nunca disparava**. Corrigido parando de preencher `estoque_disponivel` em `sync-on-webhook.php`, deixando a chave ausente pra `fetch-estoque-v3.php` sempre rodar.
- **Bug 2 (mascarava o Bug 1 mesmo depois de corrigido):** `fetch-estoque-v3.php` também usava `file_get_contents()`/`stream_context_create()` (mesma classe de bug já documentada abaixo pra `sync-on-webhook.php`) — mas o sintoma aqui não foi 401 direto, foi pior: a função `fev3_env()` carrega token do `.env` OK, mas depois carrega `storage/private/tokens.json` **sem checar `getenv() === false`** (diferente do loop do `.env`, que tem essa guarda) — sobrescrevendo incondicionalmente o token válido do `.env` com o token de `tokens.json`, que estava **expirado há 5h** (`expires_at` no passado). Resultado: 401 em 100% das 187 chamadas, sempre no token errado, mesmo com `.env` perfeito. Só foi visível adicionando `error_log` no ponto de falha (antes disso, falha silenciosa retornava `null` sem nenhum log). Corrigido com a mesma guarda `getenv($k) === false` no loop de `tokens.json`.
- `scripts/products-active-sync-loop.sh` nunca chamava `fetch-estoque-v3.php` (só `sync-on-webhook.php` + `sync-daemon-to-db.php`) — mesmo depois de consertar os dois bugs acima, o loop de produção não ia enriquecer nada sem essa chamada adicionada explicitamente. `olist/webhook-receiver.php` (webhook real da Tiny) já disparava os dois scripts corretamente em paralelo — só o polling manual (`products-active-sync-loop.sh`) estava incompleto.
- Resultado final confirmado ao vivo: 187/187 itens enriquecidos, 160 produtos `active=1` com `stock>0` no banco (era 1 antes), grid de `/catalogo` renderizando produtos reais com imagem/preço/estoque no navegador.
**Por quê importa:** os três bugs se mascaravam em cadeia — corrigir só um dos dois primeiros ainda dava "Enriquecidos 0" sem pista nenhuma do motivo real (parecia rate-limit pela demora de ~1s/item, mas era retry silencioso em request 401). Se `/catalogo` ou qualquer página com `available_only` voltar a mostrar grid vazio com contagem de produtos correta, comece checando `estoque_disponivel` no cache (`storage/products-cache-ativos.json`) antes de suspeitar do frontend.
**Ver também:** entrada abaixo "Sincronizacao de produtos ativos/inativos..." (mesma família de scripts, bug diferente — aquele é sobre `active`, este é sobre `stock`), `docs/TINY-ERP-API-V3.md`

### 2026-08-11 — Webhook de pedido/rastreio/nota fiscal (`api/webhooks/order-status-update.php`) rejeitava 100% das chamadas por falta de token
**Sistema/arquivo:** `api/webhooks/order-status-update.php`, `.env` (`shopvivaliz-deploy/shared/.env`), painel `erp.olist.com/integracoes#/ecommerce/edit/31816` (aba Notificações)
**O que descobri:** `OLIST_WEBHOOK_TOKEN`/`ERP_WEBHOOK_TOKEN` nao existiam no `.env` de producao — nenhuma das duas. O codigo faz `if (empty($webhook_token) || ...) { http_response_code(401); }`, entao TODA chamada era rejeitada, sempre, independente do token que a Tiny mandasse na query string (`?token=...` — e' assim que o token e' passado, porque o painel de webhooks da Tiny so aceita URL por evento, sem campo de header customizado). `logs/webhook.log` confirmou: eventos `estoque`/`preco`/`atualizacao_pedido` chegavam normalmente ate 2026-07-14/20, sumiram depois — bate com `docs/TINY-WEBHOOKS-SETUP.md` avisando que um token antigo "deve ser considerado comprometido e revogado no provedor" (alguem removeu o token comprometido do `.env` mas nunca colocou um novo). Corrigido gerando um token novo (`openssl rand -hex 32`), adicionando `OLIST_WEBHOOK_TOKEN=...` ao `.env`, e atualizando as 3 URLs correspondentes no painel Olist (`urls_webhook_terceiros.situacao_pedido`, `.rastreio`, `.nota_fiscal`) com `?token=<novo>` no final. Testado com curl real: `HTTP 404 "Order not found"` para um pedido fake confirma que a autenticacao passou (compare com 401 antes da correcao).
**Por quê importa:** rastreio, nota fiscal e status de pedido pararam de atualizar automaticamente por ~3 semanas sem nenhum erro visivel no admin — o pedido so ficava "parado" no status antigo. Se um agente futuro achar pedidos com status desatualizado, confira primeiro se `OLIST_WEBHOOK_TOKEN` existe no `.env` antes de investigar qualquer outra coisa.
**Ver também:** `docs/TINY-WEBHOOKS-SETUP.md`

### 2026-08-11 — Sincronizacao de produtos ativos/inativos com o Tiny/Olist estava completamente ausente
**Sistema/arquivo:** `sync-daemon-to-db.php`, `olist/sync-on-webhook.php`, `olist/webhook-receiver.php`, `api/webhooks/order-status-update.php`, `scripts/products-active-sync-loop.sh`, `daemon-token-renewer.py`
**O que descobri:**
- **Nenhuma versao da API do Tiny/Olist tem evento de webhook para produto ativado/desativado.** Confirmado nas docs oficiais: https://tiny.com.br/api-docs/api2-webhooks (v2: so vendas, pedidos enviados, rastreio, nota fiscal, precos, estoque, cotacao de frete) e https://olist.mintlify.app/documentacao/webhooks/webhooks (v3: so vendas, pedidos enviados, estoque, nota fiscal). Sincronizar `products.active` com o ERP so pode ser feito por polling periodico, nunca por webhook — nao perca tempo tentando achar esse evento de novo.
- **`sync-daemon-to-db.php` tinha nomes de coluna errados** (`olist_product_id` em vez de `olist_id`, `category`/`is_published` que nem existem na tabela `products`) e **nunca desativava produtos** que saiam da lista de ativos do Tiny — so fazia UPSERT dos que vinham no cache, nunca um DELETE/UPDATE dos que sumiram. Resultado real encontrado em 2026-08-11: 247 produtos com `active=1` no banco, sendo 197 sem nenhum `olist_id` (sem rastro de origem no ERP) e, dos 50 vinculados, 47 ja tinham saido da lista de ativos do Tiny havia tempo. Corrigido com um `UPDATE ... WHERE olist_id NOT IN (<lista atual de ativos>) AND active=1` apos o upsert.
- **`storage/products-cache-ativos.json` fica em `shared/storage/` (symlink), mas o arquivo especifico e' criado com owner `www-data:www-data` e permissao `644`** — um processo rodando como usuario `ubuntu` (ex: um systemd service com `Group=www-data`) NAO consegue escrever nele mesmo com o grupo certo, porque falta bit de escrita pro grupo. `chmod 664` resolve. Sintoma: `sync-on-webhook.php` sempre retorna exit code 0 mesmo falhando internamente (bug de design — nunca confie no exit code desse script, sempre confira o conteudo do cache).
- **CAUSA RAIZ real do 401 em `GET /produtos` (NAO e' permissao nem token):** `olist/sync-on-webhook.php` usava `file_get_contents()` com `stream_context_create()` para chamar a API v3 do Tiny, e essa combinacao especifica leva 401 da Cloudflare do Tiny **mesmo com token 100% valido e permissoes corretas** (confirmado: `GET /public-api/v3/info` e `GET /public-api/v3/produtos` com o MESMO token, via cURL, retornam 200 imediatamente). Antes de chegar nessa conclusao, verificamos e descartamos: token expirado (nao estava — JWT decodificado mostrava `roles.tiny-api` com `produtos-leitura`), permissao do app OAuth (`erp.olist.com/aplicativos_api#/edit/351`, todas as permissoes de "Produtos" ja estavam marcadas: Leitura/Incluir e editar/Excluir). **Corrigido trocando `file_get_contents`+`stream_context_create` por cURL** em `olist/sync-on-webhook.php`. Se outro endpoint do Tiny/Olist comecar a dar 401 sem motivo aparente, teste primeiro com cURL antes de suspeitar de token/permissao — o Tiny parece bloquear especificamente clientes HTTP que nao mandam os headers que um cURL/navegador manda por padrao (provavelmente `User-Agent` ou algo no handshake).
- **Bug secundario em `sync-daemon-to-db.php`:** o `ON DUPLICATE KEY UPDATE` nao incluia `olist_id=VALUES(olist_id)` — quando um produto ja existia no banco por SKU mas sem `olist_id` (os "orfaos" sem rastro do ERP), o UPSERT batia no UNIQUE KEY do `sku`, caia no branch UPDATE, e o `olist_id` continuava NULL para sempre, mesmo apos "sincronizar com sucesso". Corrigido adicionando `olist_id=VALUES(olist_id)` no UPDATE clause.
- Resultado final aplicado em 2026-08-11: 187 produtos ativos no Tiny, todos agora com `olist_id` vinculado e `active=1` no banco (bate com a contagem esperada de ~185-188). Restam 20 produtos "orfaos" genuinos (sem SKU correspondente no Tiny) e 48 inativos.
- Servico `shopvivaliz-products-active-sync.service` (`scripts/products-active-sync-loop.sh`, loop com `sleep 10800` = 3h) esta **ativo e rodando** — confirmado via `journalctl` completando um ciclo real com "Sincronizados: 187" e "Ciclo concluido; dormindo 10800s".
**Por quê importa:** o site nunca refletiu corretamente quais produtos estao ativos no ERP desde que o daemon foi escrito (nomes de coluna nunca bateram com o schema real, e depois de corrigir isso, um bug de rede diferente — `file_get_contents` vs cURL — continuava mascarando tudo como falha de credencial). Nao e' uma regressao recente, e' um recurso que nunca funcionou de ponta a ponta. Qualquer agente que "descobrir" produtos inativos aparecendo como ativos, ou um 401 aparentemente de token/permissao numa chamada `file_get_contents` para a API do Tiny, deve ler esta entrada antes de reinvestigar do zero.
**Ver também:** `docs/TINY-WEBHOOKS-SETUP.md`, `scripts/products-active-sync-loop.sh`

### 2026-08-11 — AI Image Studio + Catalog Optimization: bugs reais vs. bloqueios de conta (validados com chamadas diretas às APIs, fora do código)
**Sistema/arquivo:** `admin/ai-image-studio/*`, `admin/catalog-optimization/*`, `scripts/ai-routines-production-smoke.php`, `.env` (`shopvivaliz-deploy/shared/.env`)
**O que descobri:**
- **OpenRouter para imagem estava com bug real de código**: `admin_validate.php` (regenerar individual) e `scripts/ai-routines-production-smoke.php` chamavam `AiStudioOpenAiCompatibleClient` no endpoint `{base}/images/edits` — esse endpoint **não existe** no OpenRouter (sempre 404). O caminho correto, já implementado corretamente em `process_item.php` (fluxo principal do dashboard), é a classe dedicada `AiStudioOpenRouterImageClient` (`admin/ai-image-studio/src/OpenRouterImageClient.php`), que usa `POST {base}/images` com corpo JSON `input_references: [{type:"image_url", image_url:{url:"data:...;base64,..."}}]` — não é multipart, não é `/images/edits`. Resposta em `data[0].b64_json`, igual ao formato OpenAI. Doc oficial: https://openrouter.ai/docs/guides/overview/multimodal/image-generation
- **Groq nunca teve endpoint de edição/geração de imagem** — é inference de texto/áudio (Whisper) apenas. `ai_studio_image_provider_candidates()` em `process_item.php` já trata isso certo (Groq só otimiza prompt via chat/completions, o pixel final cai pra outro provider do fallback). O código antigo em `admin_validate.php` tentava chamar Groq como editor de imagem e sempre dava 404 — corrigido para lançar erro claro em vez disso.
- **Chave Gemini (`GEMINI_API_KEY`/`GOOGLE_IMAGEN_API_KEY`/`GOOGLE_GEMINI_API_KEY`) estava revogada**, não é bug de formato nem de código. Sintoma: `401 Request had invalid authentication credentials. Expected OAuth 2...` mesmo em `GET /v1beta/models` (endpoint que não custa nada, então não é rate-limit). Confirmado batendo a MESMA chave usada pela Liz (`api/liz-general.php`, `api/liz-intelligent.php`) — ela também falhava com o mesmo erro, provando que não é isolado do Image Studio. Chaves do Google AI Studio podem ter dois formatos válidos: `AIzaSy...` (clássico) e `AQ.Ab8...` (mais novo) — **os dois são válidos**, não presuma que `AQ.Ab8...` é token/OAuth errado só pelo formato; teste antes de descartar.
- **Tier gratuito do Gemini tem cotas SEPARADAS para texto (`generateContent` sem imagem) e imagem (`generateContent` com `responseModalities:["IMAGE"]`)** — texto se recupera rápido, imagem é bem mais restrita. Rodar a matriz de 55 combinações (`scripts/ai-routines-production-smoke.php`) mais de uma vez seguida estoura a cota de imagem por um tempo (`429 Your prepayment credits are depleted`) mesmo com chave válida — não é bug, é literalmente o limite do tier grátis. Não rode o smoke test em sequência sem espaçar.
- **Assinatura Claude Pro/Claude Code ≠ crédito de API standalone.** A chave `ANTHROPIC_API_KEY` usada pelo site é do console.anthropic.com (billing pré-pago separado), mesmo sendo a mesma conta/organização de quem tem Claude Pro. Confirmar com `GET /v1/models` (grátis, só autentica) vs `POST /v1/messages` (gasta crédito) para separar "chave errada" de "conta sem saldo".
- **Nomes de modelo "econômico" mudam — sempre confirme na API real antes de hardcodar.** `gemini-2.5-flash-lite` (que existia) foi descontinuado (`404 This model ... is no longer ...`); o alias `gemini-flash-lite-latest` é a forma estável de sempre pegar a versão lite atual sem quebrar de novo. Modelos "nano"/"mini"/"lite" dentro da MESMA geração são mais baratos, mas nem sempre a geração mais nova é mais barata que a anterior (ex.: `gpt-5-nano` $0.05/$0.40 por 1M tokens é mais barato que `gpt-5.4-nano` $0.20/$1.25) — confira preço real, não assuma pela versão.
- Auto-sync local (`git-auto-sync-master.py` ou equivalente neste ambiente) commita e faz push de edições em andamento a cada ~15-20s em commits `auto: sincronizar ...` separados — útil saber que `git status`/`git diff` local pode aparecer vazio mesmo logo após um `Edit` porque já foi commitado e enviado por outro processo, não que a edição sumiu.
**Por quê importa:** antes desta sessão, os 5 "provedores" de imagem do AI Image Studio nunca tinham sido validados com chamada real; a suposição era "conta sem crédito" para tudo, mas dois eram bugs de código genuínos (OpenRouter, Groq) e um era credencial morta (Google) — só OpenAI e OpenRouter (para imagem) são de fato bloqueio de billing puro hoje.
**Ver também:** `scripts/ai-routines-production-smoke.php` (matriz de validação real, não publica nada, roda com `AI_PRODUCTION_SMOKE_CONFIRM=RUN_ALL_PROVIDER_CHANNEL_SMOKES`)

### 2026-07-30 — Bug recorrente: endpoint le getenv() sem carregar .env primeiro
**Sistema/arquivo:** `api/blog/publish-scheduled.php`, `api/melhorenvio/webhook.php`,
`.github/workflows/autonomous-safe-operations.yml` (job `health-watch`)
**O que descobri:** três lugares diferentes liam `getenv('ALGUM_TOKEN')` (ou chamavam `gh issue`)
sem nunca ter carregado o `.env`/`checkout` antes — resultado e' falha silenciosa 100% das vezes,
sem nenhum sinal de que o problema e' configuração ausente e não bug de lógica. Confirmado ao
vivo: blog falhou em 15/15 execuções nas últimas 24h+ (token sempre lido como vazio);
`health-watch` detecta 403 real em produção mas nunca conseguiu abrir issue de alerta (`gh issue
list` retornava zero issues, apesar de falhas reais e repetidas).
**Por quê importa:** o padrão correto e' sempre `require_once __DIR__ . '/../../config/bootstrap-env.php';`
antes de qualquer `getenv()` que valide token/secret (ver `order-status-update.php` como exemplo
correto já existente). Ao criar um novo endpoint com auth via `getenv()`, checar isso primeiro —
sem o require, o endpoint "funciona" nos testes locais (onde a env já está no processo) mas falha
sempre em produção via Apache/PHP-FPM puro.
**Ver também:** PR #587, seção `### 🏗️ Arquitetura Real de Deploy` no `CLAUDE.md`

---

### 2026-08-05 — Regressão real no painel Conexões (Tiny/Olist) + chaves de IA já existentes com nome errado
**Sistema/arquivo:** `includes/integration-health.php` (`svih_olist_candidates`), `admin/ai-image-studio/config.php`, `admin/catalog-optimization/config_optimization.php`
**O que descobri:**
1. O commit `17a6c5ef7` (PR #747, "corrigir renovação OAuth de Olist e Melhor Envio", 2026-08-04) fragmentou a busca de `client_id`/`client_secret` do Tiny/Olist em 3 famílias isoladas (`olist`/`tiny`/`legacy`), exigindo que a MESMA família tivesse também o `refresh_token` persistido. Na conta real, o `client_id`/`client_secret` estavam sob os nomes legados (`CLIENT_ID_API_OLIST`/`CLIENT_SECRET_OLIST`) e o `refresh_token` persistido tinha `credential_family` diferente — nenhuma família batia com as 4 peças completas, e o painel `/admin/connections.php` reportava **"Credenciais OAuth incompletas ou placeholders"** mesmo com tudo configurado. **Isso é diferente e mais recente que o bloqueio de 3+ semanas do pipeline Shopee/GitHub Actions documentado abaixo — não confundir os dois.** Corrigido com um fallback único entre os 3 nomes pra `client_id`/`client_secret` (mantendo a separação por família só pra `refresh_token`/`access_token`). Validado ao vivo: painel voltou a "Conectado — API de produtos respondeu." em 2026-08-05.
2. `GEMINI_API_KEY` já existe e funciona em produção (é a chave real usada por `api/liz-intelligent.php`) — mas `admin/ai-image-studio/config.php` e `admin/catalog-optimization/config_optimization.php` (módulos novos deste mesmo dia) esperavam `GOOGLE_IMAGEN_API_KEY`/`GOOGLE_GEMINI_API_KEY`/`CLAUDE_API_KEY`, nomes que nunca foram configurados. Adicionado fallback pra `GEMINI_API_KEY`/`ANTHROPIC_API_KEY` nos dois módulos. **Confirmado ao vivo que `OPENAI_API_KEY` e `ANTHROPIC_API_KEY` genuinamente NÃO estão configuradas em produção** (erro explícito "não configurada" mesmo após o fallback, e o mesmo nome de variável já é usado em ~8 outros arquivos do projeto sem sucesso) — não é bug de nome, falta a chave real mesmo.
3. Descoberto também ao vivo: `gemini-2.5-pro` e `gemini-1.5-flash` retornam HTTP 404 (modelo descontinuado/não disponível) com a `GEMINI_API_KEY` real deste projeto — `gemini-2.5-flash` é o que funciona agora (mesmo default de `scripts/validate-gemini-credentials.php`). `admin/catalog-optimization/config_optimization.php::CATALOG_AI_GEMINI_MODEL` ajustado para esse valor.
**Por quê importa:** ao investigar "Tiny/Olist falhou" no painel, checar primeiro se é regressão recente de código (`git log` em `includes/integration-health.php`) antes de assumir que é o bloqueio antigo de 3+ semanas do Shopee — são sintomas parecidos, causas diferentes. E antes de pedir chave de API nova pro Fred, sempre grep primeiro por `getenv('` no resto do projeto — quase sempre já existe uma chave real sob outro nome.
**Ver também:** commits `46000f1`, `01428a6` (branch `main`); seção Shopee abaixo (bloqueio diferente, ainda não resolvido).

---

### 2026-08-26 — CORREÇÃO CRÍTICA: VM1 (137.131.156.17) é DEV, não produção — VM2 (136.248.69.116) é a produção real
**Sistema/arquivo:** VM Oracle `shopvivaliz-ai` (`137.131.156.17`) e `shopvivaliz-micro-2` (`136.248.69.116`), `CLAUDE.md` seção "🔴 VM1 vs VM2 — não confundir"
**O que descobri:** A entrada de 2026-08-25 logo abaixo (mantida por histórico) e o `CLAUDE.md`
da época afirmavam que `137.131.156.17` ("VM1", chamada de "original") era a produção real e que
`136.248.69.116` ("VM2", `shopvivaliz-micro-2`) era só um destino temporário planejado, com corte
de DNS ainda pendente. **Isso estava errado.** Fred corrigiu diretamente nesta sessão: a VM2 já é
a produção real (`shopvivaliz.com.br`) e a VM1 é só o ambiente **dev**, usado pra envio de e-mail
(`mei-mg-email`). Verificado ao vivo em 2026-08-26: (1) Apache de **ambas** as VMs tem
`ServerName dev.shopvivaliz.com.br` — nenhuma delas se identifica como produção pelo próprio
vhost, então esse campo sozinho não serve pra decidir; (2) `shopvivaliz_access.log` da VM2 mostra
tráfego real via IPs Cloudflare (`104.22.x`, `172.68-71.x`) pedindo páginas de produto e recebendo
webhooks do Mercado Livre — o da VM1 não; (3) o header `Content-Security-Policy` enforced ao vivo
na VM2 bate exatamente com o `.htaccess` do repo (Rodada 10.1); (4) `shopvivaliz-queue-worker.service`
(fix da fila de pagamentos, R9-1/R9-2) está `active (running)` na VM2 há 15h+ sem erro de permissão
recente, fila em dia (0 pendentes); (5) o release atual da VM2 (`readlink current` →
`20260826-034203-c10cda5a`) tem os 10 commits das Rodadas 1-10 + o fix da fila de pagamentos na
ancestralidade, confirmado via `git merge-base --is-ancestor <cada commit> c10cda5a` — todos "SIM".
Ou seja: **como o deploy de ambas as VMs puxa do mesmo `main` via cron a cada 2min, todo o
trabalho das 10 rodadas + fix da fila chegou na produção real (VM2) mesmo com a validação ao vivo
de várias rodadas anteriores tendo sido feita (erroneamente) contra a VM1.**
**Por quê importa:** Qualquer agente que valide algo "ao vivo" numa VM Oracle deve **primeiro
confirmar contra qual domínio real está testando** (`shopvivaliz_access.log` com tráfego
Cloudflare real é o sinal mais confiável — `ServerName` do vhost NÃO é, porque as duas VMs usam o
mesmo nome dev por trás de Cloudflare Authenticated Origin Pull). Não assuma que "VM original" ou
"primeira VM que aparece no `~/.bash_history`" é produção. Quando a `shopvivaliz-free-a1` for
provisionada, o corte futuro deve partir da VM2 (produção real atual), não da VM1.
**Ver também:** `CLAUDE.md` (seção "🔴 VM1 vs VM2 — não confundir"), entrada abaixo (2026-08-25, histórico da migração original — papéis planejados nela estão desatualizados, mas o contexto do incidente de memory thrashing continua válido)

---

### 2026-08-25 — VM Oracle original travou por memory thrashing; migração de 3 VMs em andamento
**Sistema/arquivo:** VM Oracle (`137.131.156.17`), `CLAUDE.md` seção "🚧 Migração de VM em andamento"
**O que descobri:** A VM original (`shopvivaliz-ai`, shape `E2.1.Micro`, só 954Mi RAM) hospeda
shopvivaliz + `mei-mg-email` + `solange-rolla-consultorio` simultaneamente. Em 2026-08-25 ficou
totalmente sem resposta (SSH incluído) por ~12min — journal mostra `systemd-journald: Under
memory pressure, flushing caches` repetido e lag de 5+ min entre a app escrever um log e o
journald recebê-lo, sem nenhum OOM-killer disparado (RAM+swap esgotados = thrashing total, pior
que um OOM-kill porque nada morre pra liberar memória). Iniciei provisionamento de 2 VMs novas no
mesmo tenancy Oracle (`fredmourao`, Always Free) pra separar as cargas: `shopvivaliz-micro-2`
(`136.248.69.116`, E2.1.Micro, já no ar) como destino temporário "limpo" pra loja em produção, e
`shopvivaliz-free-a1` (Ampere A1.Flex, 2 OCPU/12GB) como destino final quando a capacidade da
Oracle liberar (erro "Out of host capacity" recorrente na região sa-saopaulo-1, retry automático
em andamento).
**⚠️ CORREÇÃO (ver entrada de 2026-08-26 acima):** os papéis descritos aqui (VM1=produção,
VM2=temporária) estavam errados — VM2 já era a produção real. O restante desta entrada (contexto
do incidente de memory thrashing) continua válido como histórico.
**Credenciais:** chave SSH única para as 3 VMs (`ssh-key-2026-07-04.key`, mesmo caminho de sempre),
API OCI configurada em `~/.oci/config` local e secrets `OCI_CLI_*` no GitHub para gerência via CLI.
**Ver também:** `CLAUDE.md` (seção "🔴 VM1 vs VM2 — não confundir"), [[feedback_local_autosync_bypasses_pr]] (memória de sessão — mesmo daemon de auto-sync que já causou problemas de merge continua ativo nesta VM local)

---

### Shopee/Tiny OAuth2 — PARADO HÁ 3+ SEMANAS + workflows removidos (2026-07-27)
**Status:** Requer ação manual (regenerar client OAuth2 na Tiny) **e** recriar workflows
**Arquivos:** `.github/workflows/{fetch,optimize}-shopee-listings.yml` — **não existem mais no repo**, aparentemente removidos como colateral da consolidação "99→10 workflows" de 2026-07-26 (`CLAUDE.md`); nenhum workflow ativo restante referencia Shopee. Os agentes (`agents/v9.2.85/app/ShopeeListings*Agent.php`) continuam no repo, só falta o workflow que os chama.
**O problema:** (1) Credencial `TINY_*` do GitHub Secrets expirou/revogada — todo ciclo retornava `"Falha OAuth2: Invalid client or Invalid client credentials"`. (2) Desde ~2026-07-26, os dois workflows nem existem mais, então nem o erro acima roda: nenhum artefato novo em `listings/` desde `20260726-080756`/`20260726-060921`.
**O que precisa:** (1) Usuário vai a `accounts.tiny.com.br` → regenera client OAuth2 → atualiza `TINY_CLIENT_ID`/`TINY_CLIENT_SECRET`/`TINY_REFRESH_TOKEN` em GitHub Secrets. (2) Recriar `fetch-shopee-listings.yml`/`optimize-shopee-listings.yml` com trigger `schedule` (ver `docs/HISTORICO-AGENTES-SHOPEE.md` seção 9.10 para detalhes e formato esperado dos relatórios).
**Enquanto isso não for feito:** o pipeline de otimização Shopee não roda de forma alguma, nem para gerar erro — não há mais workflow que dispare.

---

## 🟢 Resolvido na Rodada 2 (2026-08-18) — catálogo sem imagem, deploy-webhook, rate limit de login

**Catálogo público sem imagem de produto (o item de maior impacto do backlog):** causa raiz era
uma única linha em `catalogo.php:208` (`sv_catalog_products()`), que preenchia `image_url` com o
logotipo da loja *antes* dos enriquecedores (`svp_enrich_products()`, `svcie_enrich_images()`)
rodarem. Como esses dois enriquecedores só preenchem campos que estão **vazios**, o pré-preenchimento
com o logo os neutralizava silenciosamente — o card renderizava o logo primeiro e as fotos reais
(que ficavam em `images`, não em `image_url`) depois. Corrigido removendo o default nessa linha; o
fallback correto para o logo já existe no momento do render (linha ~613), depois do enriquecimento.
Isso também destravou `sitemap.xml` (que descartava os 176 produtos porque exigia `image_url` não
vazio) e o JSON-LD do catálogo (lia o mesmo campo envenenado). **Padrão a vigiar em outros lugares:**
qualquer função que aplica um default "de apresentação" antes de um passo de enriquecimento que só
preenche campos vazios vai quebrar esse enriquecimento — o default precisa vir depois, não antes.

**`deploy-webhook.php`:** estava inerte em produção (falhava fechado com 503 por falta de
`DEPLOY_SECRET` no `.env`, confirmado ao vivo antes da remoção), mas o risco latente era sério — se
alguém configurasse `DEPLOY_SECRET` um dia, o script faria `git pull` via `exec()` com
`GITHUB_TOKEN` embutido na URL do remote (vazando pro `git config` e pro log), ou copiaria arquivo
por arquivo por cima do webroot dentro do release imutável, quebrando o modelo de releases do
`deploy-production.sh`. Removido (neutralizado com 410) — o único caminho de deploy é
`/usr/local/lib/shopvivaliz/deploy-production.sh` (cron 2min na VM Oracle). Não criar webhooks de
deploy no webroot.

**Rate limit de login era burlável:** `includes/rate-limiter.php::RateLimiter::isAllowed()` guardava
o contador em `$_SESSION`, mas a chave normalmente incluía o IP — um cliente que não enviasse o
cookie `PHPSESSID` recebia sessão nova a cada request e o contador reiniciava sempre. Ou seja, não
havia proteção real contra força bruta em `auth/login.php`, `auth/register.php` nem
`api/cart/add.php`. Reescrito como wrapper fino sobre `svorl_allow()`
(`includes/order-rate-limit.php`), que já é baseado em arquivo com `flock`, chaveado por
pública mantida — nenhum call-site precisou mudar.

**Vazio de implementação de frete grátis (registrado, não corrigido):** cupons do tipo `shipping`
não zeram frete em lugar nenhum do código (`api/orders/process-validated.php` soma `shippingTotal`
sempre), mas `sv_active_coupon_offer_text()` anunciava "FRETE GRÁTIS" na navbar para esse tipo.
`/api/settings/free-shipping.php` também retorna `enabled:false` sempre — não há nenhum código que
aplique o threshold de frete grátis à cotação. Protegido preventivamente (cupom `shipping` agora é
recusado com `coupon_unsupported_type`, e não é mais anunciado), mas a feature de verdade (frete
grátis real) precisa de decisão de negócio do Fred antes de qualquer implementação — mexe no
fingerprint HMAC da cotação de frete.

**Ver também:** relatório completo da Rodada 2 (achados B1–B10, itens estruturais #1–#12, E1–E3) —
gerado nesta sessão, não commitado como arquivo separado no repo; resumo acima cobre o essencial
para futuros agentes.

---

## 🟢 Resolvido na Rodada 3 (2026-08-19) — relay de e-mail aberto, segredo Olist exposto na raiz

**Relay de e-mail aberto e não autenticado:** `api/emails/test-send.php` calculava
`$expected_token` mas **nunca comparava** com `$_GET['token']` — qualquer visitante na internet
conseguia disparar e-mails transacionais reais (confirmação de pedido, boleto gerado, pedido
enviado) para qualquer destinatário, usando o SMTP e o domínio da loja. Confirmado ao vivo (HTTP
200, envio real, sem credencial nenhuma). Risco principal: queima de reputação SPF/DKIM (e-mails de
pedidos de clientes reais passam a cair em spam) e phishing assinado pelo domínio real. Neutralizado
(410) + regra `.htaccess`. **Padrão a vigiar em outros lugares:** `grep` por variáveis chamadas
`$expected_token`/`$expected_*` que nunca aparecem de novo num `hash_equals()`/`===` depois — é o
sintoma de uma checagem de auth que foi escrita mas nunca ligada.

**`SECRETS-TEMP-COLE-AQUI.txt` publicava um `OLIST_CLIENT_SECRET` em texto puro na raiz do site.**
O `.htaccess` bloqueia `.md`/`.sh`/`.ps1`/`.bat`/`.log`/`.sql`/`.bak` na raiz, mas **`.txt` não
estava na lista** — e a raiz do repositório é a raiz pública do site (releases imutáveis são cópias
do repo servidas pelo Apache). Corrigido: negado `.txt` por padrão, com allow-list explícita para
`robots.txt`/`llms.txt` (únicos `.txt` que precisam ficar públicos hoje). **Ação pendente do Fred:**
confirmar se o client_secret do Olist/Tiny que estava neste arquivo (prefixo `pZU4...`) foi
revogado/rotacionado — o arquivo já foi neutralizado, mas se a credencial ainda for válida ela
precisa ser trocada. **Padrão a vigiar:** qualquer arquivo novo criado por um agente na raiz do repo
é conteúdo público por padrão até alguém adicionar uma regra — isto reforça o item #7 (`.htaccess`
default-deny) que segue aguardando aprovação do Fred desde a Rodada 1/2.

**PHP não é o gargalo de performance desta aplicação.** Medição ao vivo (Rodada 3): o próprio
`index.php` reporta 3-5ms de execução PHP, mas o TTFB medido de fora é de 3,5s (p50) a 4,5s (p90).
O gargalo está entre o TLS handshake e o primeiro byte — fora do código da aplicação. Hipóteses
prováveis: saturação de workers do Apache (mod_php/MPM prefork), contenção com o deploy a cada 2min
+ automação, disco a 86% de uso, ou invalidação de OPcache a cada release novo. **Precisa de SSH na
VM pra diagnosticar — não tentar otimizar consultas/cache da aplicação achando que vai resolver.**

**Ver também:** relatório completo da Rodada 3 (R3-1 a R3-7, itens A1-A4 aguardando aprovação) —
gerado nesta sessão, resumo acima cobre o essencial para futuros agentes.

---

## 🟢 Resolvido na Rodada 4 (2026-08-19) — Cache-Control duplicado, endpoints sem auth, latência recaracterizada

**A caracterização de latência da Rodada 3 estava errada — corrigida.** A R3 mediu p50=3,49s de
TTFB e concluiu "overhead constante de ~3,5s entre Cloudflare e PHP em todos os endpoints". A
Rodada 4 não reproduziu isso: 40 amostras novas deram p50=0,275s, p90=0,692s. O padrão real é
**degradação episódica** — em janelas específicas (ex: ~01:42 UTC numa sessão) tudo fica lento ao
mesmo tempo, inclusive `/sobre/` (sem consulta de catálogo) e um `.css` estático (sem PHP nem
banco). Isso aponta para contenção de recurso na VM (Apache/PHP-FPM saturado, I/O do disco a
86%+, ou um cron pesado concorrente), não para query lenta de produto. **Se investigar lentidão de
novo, comece pela VM (SSH necessário), não pela aplicação.**

**`css/home-bundle.php` (178 KB de CSS render-blocking da home) nunca era cacheado pela Cloudflare**
porque é `.php` (Cloudflare só cacheia extensões estáticas por padrão) — toda visita nova ia à
origem, 0,3-4,5s de TTFB medidos. Além disso o `Cache-Control` chegava malformado (dois `max-age`
na mesma diretiva) porque o `mod_expires` do `.htaccess` (`ExpiresByType text/css`) acrescentava um
segundo valor sobre o que o PHP já tinha definido — corrigido com um `<FilesMatch>` desligando
`mod_expires` só para esse arquivo. **Padrão a vigiar:** qualquer CSS/JS servido via `.php` (em vez
de arquivo estático com hash de conteúdo, como já é feito para `visual-polish-v5.*.min.css`) paga
esse mesmo custo de cache-miss constante na borda — ainda não convertido para estático (fica para
rodada futura, é mudança de build/deploy).

**Endpoints públicos sem autenticação encontrados e corrigidos:** `api/stock-alerts/subscribe.php`
(sem rate limit, ao contrário do `newsletter/subscribe.php` vizinho — rate limit adicionado; double
opt-in real ainda falta, fica pendente), `api/simple-agent-chat.php` **e** seu alvo real
`claude/api/monitor/simple-chat.php` (gravação ilimitada em disco, CORS `*` — os dois precisaram
ser neutralizados porque `claude/` é servido publicamente por `.htaccess:210-211`, então o wrapper
sozinho não bastava), `api/generate-test-order.php` (disparava chamadas reais à API do Mercado Pago
sem auth) e `api/sync/full-sync.php` (só protegido por denylist do `.htaccess`; ganhou
`sv_require_agent_key()` como segunda camada + removido `display_errors`/vazamento de `db_error`).

**`/api/health.php` reduzido: payload detalhado (versão PHP, % disco, quais secrets configurados)
agora só sai com a chave de agente válida; sem chave, resposta mínima `{ok, status, generated_at,
health_score_percent}`.** Suficiente para monitores de uptime externos continuarem funcionando sem
expor fingerprint de infra a qualquer visitante. `api/monitor/api.php`/`dev-status.php` têm o mesmo
problema em escala maior (19 KB de estado interno) e ficaram pendentes para uma rodada futura.

**Ver também:** relatório completo da Rodada 4 (R4-1 a R4-10, itens A4-1 a A4-4 aguardando
aprovação) — gerado nesta sessão, resumo acima cobre o essencial para futuros agentes.

---

### Tiny ERP API v3
**Última atualização:** 2026-07-19

- ✓ **Pedidos pagos** nascem com `situacao=3` (Aprovada), não `0`
- ✓ **Forma de pagamento real** vem de `order.mercadopago.payment_type_id` (Pix/boleto/cartão), NÃO do campo `payment_method` (sempre "mercado_pago")
- ✓ Campos `listaPreco`, `naturezaOperacao`, `intermediador` só se houver ID real configurado
- ✓ Usar Bearer token OAuth de `storage/private/tokens.json`, não `OLIST_INTEGRADOR_TOKEN` (v2 legada)
- ✓ **API v2 foi removida inteiramente** — qualquer grep de `api/v2` ou `api2/` é bug, não feature

**Ver:** `docs/TINY-ERP-API-V3.md`

### Olist API v3
**Última atualização:** 2026-07-18

- ✓ Token OAuth armazenado em `storage/private/tokens.json` com `expires_at`
- ✓ Auto-renova via refresh_token a cada 3 horas (`refresh-token.php`)
- ✓ **Status do daemon:** verificar arquivo de log ou chamar `/api/daemon-status` (health check)

**Ver:** `docs/OLIST-API-V3.md`

### Domínio Canônico
**Última atualização:** 2026-07-19

- ✓ **Domínio principal:** `https://shopvivaliz.com.br` (apex, sem www)
- ✓ Legados (`www.*`, `dev.*`) podem redirecionar `GET/HEAD` → apex apenas
- ✓ Todos os callbacks, feeds, OAuth, Mercado Pago devem apontar para apex

**Ver:** `.htaccess`, `scripts/update-production-env.py`

### Produtos e Catálogo
**Última atualização:** 2026-07-26

- ✓ Filtro de exibição: `situacao` = 'A'|'ATIVO'|'ACTIVE' OU `is_published` = true
- ⚠️ **Cache não atualiza automaticamente** ao desmarcar produto no admin — requer manual clear ou webhook
- ✓ Fonte de verdade: `storage/products-cache-ativos.json` (Olist/Tiny) → fallback: `api/catalog/fallback-products.json`

### Deploy
**Última atualização:** 2026-07-30 (corrige entrada de 2026-07-26, que estava desatualizada)

- ✓ **Produção real:** `/usr/local/lib/shopvivaliz/deploy-production.sh` via cron a cada **2min**,
  monta release imutável em `/home/ubuntu/shopvivaliz-deploy/releases/<ts>-<sha>/` e troca o
  symlink `current`. Apache `DocumentRoot` aponta para `current` — confirmar sempre com
  `grep DocumentRoot /etc/apache2/sites-enabled/*` antes de assumir qual diretório serve o site
- ✓ **`.env` real de produção:** `/home/ubuntu/shopvivaliz-deploy/shared/.env` (symlinkado em
  cada release) — **não** `/home/ubuntu/site-shopvivaliz/.env`
- ⚠️ **Dois diretórios ativos, papéis diferentes** — `shopvivaliz-deploy/` serve o site;
  `site-shopvivaliz/` roda daemons/systemd (`shopvivaliz-24x7`, `agent-bridge`, `auto-sync`,
  `orchestrator`, `mcp`, etc.) e crons de sync (Olist, Google Ads, IndexNow). Editar `.env` no
  lugar errado não tem efeito nenhum no que você está tentando corrigir — ver `CLAUDE.md` seção
  `### 🏗️ Arquitetura Real de Deploy` pro diagrama completo
- ✓ GitHub Actions reduzido de 99 para 10 workflows críticos

### 2026-07-29 — Toolkit local e alvo persistente de deploy
**Sistema/arquivo:** produção Oracle, `scripts/deploy-production.sh`, wrappers locais em `C:\Users\FRED\.local\bin`
**O que descobri:** os atalhos operacionais válidos são `docker-check`, `docker-up`, `docker-down`, `sv-vm-ssh`, `sv-vm-status`, `sv-deploy-head`, `sv-deploy-sha`, `sv-blog-status` e `sv-blog-publish`. A chave funcional da VM nesta máquina é `C:\Users\FRED\Downloads\ssh-key-2026-07-04.key`.
**Por quê importa:** o alias antigo do WSL para `bash` e a chave `shopvivaliz_vm_agent` causavam falso negativo. Sem um alvo persistente (`/home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref`), o cron voltava para `main` e desfazia deploy manual por SHA/branch.
**Ver também:** `AGENTS.md`, `docs/VM-SSH-ACCESS.md`

### 2026-07-29 — Worktree e editores padronizados
**Sistema/arquivo:** worktree local, atalhos Windows, `AGENTS.md`, `.vscode/`
**O que descobri:** o checkout operacional que deve ser usado pelos agentes locais é `C:\site-shopvivaliz-prod-liz`, validado no branch `agent/liz-widget-prod` com SHA `19328ac2` em 2026-07-29. Os atalhos `ShopVivaliz - VS Code`, `ShopVivaliz - Antigravity` e `ShopVivaliz - Antigravity IDE` foram criados no desktop e o Start Menu dos três editores foi ajustado para abrir o mesmo worktree.
**Por quê importa:** havia risco real de abrir o editor em `C:\site-shopvivaliz` ou em um diretório genérico e editar o checkout errado. A padronização reduz drift entre VS Code, Antigravity e Antigravity IDE e facilita validação de branch antes de deploy.
**Ver também:** `AGENTS.md`, `site-shopvivaliz.code-workspace`, `.vscode/settings.json`

### 2026-07-29 — ShopVivaliz Mobile Agent Bridge
**Sistema/arquivo:** VM Oracle, `/home/ubuntu/site-shopvivaliz/agent-bridge/`, `AGENTS.md`
**O que descobri:** a bridge correta para uso do GPT mobile observa `/home/ubuntu/site-shopvivaliz/agent-bridge/inbox/` e expoe apenas `create_issue`, `apply_patch_pr`, `read_file` e `run_readonly_audit`. O service associado e `shopvivaliz-agent-bridge.service`.
**Por quê importa:** isso entrega acesso controlado da VM ao GPT mobile por fila JSON, sem shell irrestrito e sem permitir commit direto em `main` ou merge automatico.
**Ver também:** `AGENTS.md`, `agent-bridge/README.md`, `deploy/systemd/shopvivaliz-agent-bridge.service`

---

### 2026-08-19 — Rodada 5 de melhoria contínua (Sonnet, implementação)
**Sistema/arquivo:** `api/ml/*` (optimizer.php, order-sync.php), `includes/melhorenvio-oauth.php`, `api/melhorenvio/webhook.php`, `admin/.htaccess`, `includes/catalog-runtime.php`, `index.php`, `facebook-shop-feed.php`, `google-shopping-feed.php`, `produto.php`, `api/checkout/endereco.php`, `includes/security-bootstrap.php`/`security-headers.php`/`csrf-protection.php`, `js/product-conversion-v5.js`, `404.php`.
**O que descobri:**
- `api/ml/{me,token,status,products,optimizer}.php` não exigiam nenhuma autenticação (um deles expunha CNPJ/e-mail/telefone da empresa via proxy anônimo pro `/users/me` do ML; outro deixava qualquer POST anônimo forçar rotação do refresh token, que o ML invalida por uso único — um vetor plausível pra derrubar a integração inteira). Todos os cinco ganharam o guard `sv_require_agent_key()` já usado em `api/maintenance/opcache-reset.php`. `api/ml/order-sync.php` **não** precisa do guard — é só uma biblioteca de funções incluída por `webhook.php` (chamado pelo ML) e por um script CLI de backfill; não tem nenhum código de topo que leia `$_GET`/produza saída quando acessado direto, então o item do relatório da Rodada 5 que o citava como 6º arquivo era impreciso.
- `me_validate_oauth_state()` (Melhor Envio) tinha um bug fail-open: sessão vazia (o caso exato de quem nunca passou pelo fluxo de connect) retornava `true`. Corrigido pra `false`. O corpo de erro completo do exchange de token (que vazava pro público) agora só vai pro `error_log`.
- 5 painéis `.html` soltos em `admin/` (monitor-*.html, squad-chat.html, trio-dashboard.html) não tinham nenhuma autenticação — o `.php` gêmeo de cada um tinha guard, o `.html` estático não. `admin/.htaccess` não existia; criado bloqueando `\.html$`.
- **Bug real de produção, não só falta de guard:** `facebook-shop-feed.php` chamava `sv_home_catalog_source_rows()`, que só existia dentro de `index.php` — e este feed nunca inclui `index.php`, só `includes/catalog-runtime.php`. Resultado: fatal error ("Call to undefined function") toda vez que o feed do Facebook/Instagram Shop era gerado. Corrigido movendo a função pra `includes/catalog-runtime.php` (guardada com `function_exists()` nos dois lugares pra não redeclarar).
- **Outro bug real:** `google-shopping-feed.php` usava `svcr_products()` puro, sem a chamada de enriquecimento de imagem ERP (`svcie_enrich_images()`) que os feeds Google agora usam via `includes/catalog-image-enrich.php`. Sem imagem válida, o filtro do próprio feed descartava quase todo produto — o feed publicava zero (ou quase zero) produtos no Google Shopping por tempo indeterminado, sem nenhum erro visível. O helper legado o helper legado de imagens foi removido para impedir fallback paralelo.
- Canonical/`og:url` de `produto.php` emitiam o slug cru (UTF-8, sem percent-encoding) enquanto o resto do site (JSON-LD, links internos) usa `rawurlencode()`. Mesmo bug replicado em `facebook-shop-feed.php`. Corrigido nos dois.
- `api/checkout/endereco.php` tinha um `require_once __DIR__ . '/../includes/viacep-integration.php'` que resolve pra `api/includes/...` (nunca existiu) em vez de `includes/...` (raiz do repo, dois níveis acima) — fatal error garantido em todo request. Confirmado via grep que nenhum arquivo do repo referencia esse endpoint; neutralizado (410) em vez de corrigido, já que não tem uso real conhecido.
- **Correção da Rodada 4 (R4-10) tinha sido aplicada em código morto:** `includes/security-headers.php` só é incluído por `includes/security-bootstrap.php`, que por sua vez **nenhum arquivo do repo inclui** (confirmado via grep). O edit da Rodada 4 no header `X-XSS-Protection` teve zero efeito real em produção. Os três arquivos (`security-bootstrap.php`, `security-headers.php`, `csrf-protection.php`) agora têm comentário explícito marcando isso — não foram apagados (`rm`/`unlink` seguem bloqueados neste ambiente em `C:\site-shopvivaliz`, só `mv`/sobrescrita de conteúdo funcionam). Os headers de segurança reais em produção vêm de outro lugar (Apache/.htaccess ou outro bootstrap) — não investigado nesta rodada.
- `js/product-conversion-v5.js` tinha `formatMoney()` sem separador de milhar (`R$ 1234,56` em vez de `R$ 1.234,56`) — únicos dos 3 arquivos citados no relatório da Rodada 5 que ainda tinham o bug; `js/cart-shipping-v7.js` e `js/mixed-cart-promo-v1.js` já estavam corrigidos no momento da implementação (provavelmente por outro agente concorrente entre o scan do Opus e esta implementação). Também corrigido no mesmo arquivo: `formatDelivery()` injetava `option.company`/`option.name` (dado de terceiro via `/api/melhorenvio/shipping-check-v2.php`) via `innerHTML` sem escape — baixo risco (não é entrada de usuário direta), mas era o único ponto do frontend público nessa situação.
- `404.php` chamava `session_start()` sem nunca ler/escrever `$_SESSION` — toda visita de bot/scanner numa URL inexistente criava um arquivo de sessão órfão em disco (VM medida em ~86% de uso na Rodada 4). Removido.
- `/produto.php` sem nenhum parâmetro (`?id=`/`?slug=`/`?sku=`) caía na condição `$lookupRequested = false` → `$notFound = false` → servia um placeholder fake (`sku=sem-sku`, `price:0`) com HTTP 200, cacheável e indexável, cujo próprio canonical apontava pra uma URL que dá 404. Corrigido: sem nenhum parâmetro de lookup agora também é tratado como `$notFound = true` (mesmo 404 com `Cache-Control: no-store` que já existia pra produto inexistente).
**Por quê importa:** dois desses (facebook-shop-feed fatal error, google-shopping-feed zero produtos) são bugs de produção genuínos que vinham falhando silenciosamente — nenhum dos dois emite erro visível pro operador, só param de funcionar. O achado sobre `security-headers.php` é a segunda vez nesta série de rodadas que uma correção de rodada anterior precisa ser auditada quanto a efeito real em produção antes de ser dada como resolvida — vale manter esse hábito nas próximas rodadas.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 2, 3 e 4, acima), `CHANGELOG.md`

---

### 2026-08-19 — Rodada 6 de melhoria contínua (Sonnet, implementação)
**Sistema/arquivo:** `api/webhooks/tiny-product-price-sync.php`, `api/orchestrator/status.php`, `api/monitor/dev-status.php`, `config/official-site.php`, `scripts/quality/validate-official-site-reference.php`, `.github/workflows/shopvivaliz-qa.yml`, `.htaccess`, `public_html/.htaccess`, `tests/commercial-policy-regression-test.php`.
**O que descobri:**
- **Achado mais grave de toda a série até agora:** `api/webhooks/tiny-product-price-sync.php` aceitava POST anônimo e reescrevia `precos.preco`/`preco`/`preco_venda`/`price` de qualquer SKU em dois caches autoritativos (`storage/products-cache-ativos.json` e `api/catalog/fallback-products.json`), sem nenhuma verificação de emissor. Confirmado ao vivo com SKU falso (não destrutivo): a requisição foi processada até a busca por SKU, só não gravou porque o SKU não existia. O webhook irmão de estoque (`api/tiny/stock-webhook.php`) já usa `sv_webhook_secret_gate()` de `includes/webhook-secret.php` exatamente para esse risco — faltava aplicar em preço. Corrigido com o mesmo gate (que já embute rollout em duas etapas: fica permissivo e só loga enquanto `TINY_WEBHOOK_SECRET`/`OLIST_WEBHOOK_SECRET` não estiver definido no `.env`, passa a exigir automaticamente assim que o Fred configurar o segredo — não precisa de outro deploy). Adicionado também um limite de sanidade independente do gate: recusa preço `<= 0` ou variação `> 70%` do preço atual, só logando. **Pendente do Fred:** definir o segredo no `.env` da VM (`shopvivaliz-deploy/shared/.env`) e atualizar a URL de notificação de preço no painel Tiny — sem isso o gate continua em modo permissivo.
- `api/monitor/dev-status.php` era público (mesma família de R3-4/`api/health.php` e R4-4/`api/monitor/api.php`, terceiro endpoint dessa classe) e devolvia o caminho absoluto da release em produção (usuário `ubuntu`, layout `shopvivaliz-deploy/releases/<timestamp>-<sha>`, SHA do commit rodando). Guardado com `sv_require_agent_key()`. Também trocado o `http_response_code(207)` (WebDAV, não reconhecido como "atenção" pela maioria dos monitores) por `200` com `status:"attention"` no corpo.
- `config/official-site.php` tinha 3 de 4 rotas em `navigation` (about/help/contact) devolvendo 404 em produção — só `terms` estava certo. Corrigidas pros destinos reais confirmados via `.htaccess` (`/sobre/`, `/faq/`, `/contato/`; `/sobre` e `/contato` têm diretório físico no docroot, por isso o 301 pra versão com barra antes do rewrite interno). O validador dedicado (`scripts/quality/validate-official-site-reference.php`) só conferia que as chaves não estavam vazias — qualquer string passava — e nunca rodava no CI. Agora faz `HEAD` real contra cada URL e falha em status `>= 400` (com fallback de aviso, não erro, se a rede do runner estiver indisponível), e foi adicionado ao `shopvivaliz-qa.yml`. Terceira ocorrência da mesma assinatura de R5-8/R6-3: existe um artefato de qualidade com o nome exato do problema, que não testava o problema e não rodava.
- `tests/commercial-policy-regression-test.php` lê o **fonte** de `index.php` pra garantir que o cupom legado `VIVALIZ10` não é anunciado — mas o banner de anúncio é montado em runtime a partir do banco (`includes/navbar.php` + `sv_primary_active_coupon()`), nunca aparece literalmente no fonte. Confirmado ao vivo: a home e `/catalogo/` anunciam VIVALIZ10 hoje, com o teste passando verde. Causa raiz: `includes/account-schema.php` semeia VIVALIZ10 com `display_in_navbar=1`/`display_in_popup=1`. Não corrigi o site nem o teste — é decisão comercial (o cupom pode ou não ser anunciado publicamente?), não técnica. Só documentei o problema com comentário explícito no teste pra o próximo agente não se enganar com o verde. Nota lateral: `js/sales-conversion-v1.js` anuncia mínimo de R$100 pro desconto enquanto o cupom tem `min_order_value=0`, e `config/coupons.json` ainda lista `PRIMEIRA10` como válido apesar do comentário em `account-schema.php` dizer que foi removido por duplicata — duas fontes de verdade divergentes sobre quais cupons existem.
- **Confirmação por `curl -I` de que R5-8 não chegou a produção:** `x-xss-protection: 1; mode=block` continua sendo servido em `https://shopvivaliz.com.br/`. A correção do Round 5 foi aplicada em `includes/security-headers.php` (código morto). Busquei o header em todo o repo: não está em nenhum arquivo ativo — só na dead code já marcada e em `public_html/.htaccess` (árvore de FTP desativada por `CLAUDE.md`). Ou seja, vem de configuração Apache da VM fora do alcance deste repo (sem SSH). Como paliativo que não depende de acesso à VM, adicionei `Header always unset X-XSS-Protection` + `Header always set X-XSS-Protection "0"` no `.htaccess` real (o que serve produção) — Apache aplica `Header` de `.htaccess` por cima do que vier do vhost, então isso deve neutralizar o header sem precisar mexer na config da VM. **Só confirmável com `curl -I` pós-deploy** — próximo agente: valide assim, não por leitura de código (é exatamente o erro que gerou R5-8).
**Por quê importa:** R6-1 é provavelmente o achado mais sério desta série de 6 rodadas — escrita de preço de e-commerce sem autenticação nenhuma, combinando mal com R5-9 (`api/orders/create-v2.php` confiando no preço enviado pelo cliente) ainda não resolvido. R6-2, R6-5 e R6-6 repetem um padrão que já apareceu 3 vezes nesta série (R5-4, R5-7, e agora R6-2/R6-5): arquivo com nome de validador/guardião que na prática não valida nem guarda nada, e que ninguém percebe porque não está no caminho de execução real (não roda no CI, ou lê fonte em vez de output, ou aponta pra arquivo inexistente). Vale manter esse padrão de busca nas próximas rodadas: sempre que um arquivo se chama "validate-*" ou "test-*", confirmar que ele de fato roda em algum workflow E que testa o comportamento observável, não só a existência de uma string.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 2–5, acima), `CHANGELOG.md`, relatório completo em `outputs/rodada6-diagnostico.md` (6 itens aguardando decisão do Fred: segredo do webhook de preço, política do VIVALIZ10, remoção de `catalogo-v2.php`, destino de `api/orchestrator/queue.php`, execução de teste de `scripts/generate-covers.php` via SSH, e confirmação de `CRON_SECRET`/conteúdo de `logs/tiny-webhook-price.log` em produção).

---

### 2026-08-19 — Rodada 7 de melhoria contínua (Sonnet, implementação)
**Sistema/arquivo:** `.htaccess`, `claude/api/agent/olist-zero-id-repair.php`, `claude/api/shopee/atualizar-completo.php`, `includes/order-rate-limit.php`, `api/cart/add.php`, `api/orders/create-v2.php`, `auth/register.php`, `auth/login.php`, `api/hotspot-endpoint.php`, `.github/workflows/test-inventory.yml`.
**O que descobri:**
- **Achado dominante da rodada: a árvore `claude/` inteira (46 arquivos PHP) estava publicada em produção sem NENHUMA autenticação.** O deploy real usa `git archive` da árvore completa (`scripts/deploy-production.sh`) — tudo que está no git vai pro DocumentRoot — e a única defesa é uma lista enumerada em `.htaccess` que não incluía `claude`; pior, havia duas `RewriteRule` explicitamente roteando pra lá. Dentro da árvore: um endpoint que rodava `ALTER TABLE` + `UPDATE` em massa em `olist_products`/`olist_product_images` via GET público (`claude/api/agent/olist-zero-id-repair.php` — hoje falhava fechado só porque não achava bootstrap de banco; bastava criar `claude/config.php` pra virar uma ferramenta de destruição de dados aberta ao público), um endpoint que disparava OAuth do Olist com credenciais reais (`claude/api/olist/auto-sync.php`), uma vitrine fantasma indexável com 3 produtos/preços inventados + checkout/carrinho paralelos, um painel que expunha publicamente que o CI autônomo estava parado há 47 dias e as 3 chaves de IA (Anthropic/OpenAI/Gemini) sem crédito, e um script que fabricava sucesso falso em log sem chamar a API da Shopee (`claude/api/shopee/atualizar-completo.php` — "198/198 produtos" era literal no código). Adicionei `claude` e `web` (mesmo problema: painel Vercel institucional indexável) à lista de bloqueio do `.htaccess` e removi as duas rotas de rewrite que apontavam pra `claude/`. Os dois endpoints de mutação (`olist-zero-id-repair.php`, `atualizar-completo.php`) também foram neutralizados individualmente como defesa em profundidade. **Isso fecha o painel `claude/dashboard/` — se o Fred usa esse painel no dia a dia, ele pode pedir pra reabrir só `claude/index.php`/`claude/dashboard/` atrás de `sv_require_agent_key()`** (opção "b" do relatório da Rodada 7); a decisão de *como* reabrir é dele, mas *fechar agora* não é opcional dado que um dos endpoints é DDL+mutação em massa sem autenticação.
- Confirmado por `curl -I` em 8 rotas distintas que os headers de segurança realmente servidos em produção **não vêm deste repositório**: `Strict-Transport-Security` tem `includeSubDomains` (o `.htaccess` não define isso), `Referrer-Policy` é `same-origin` (o `.htaccess` diz `strict-origin-when-cross-origin`), e `X-XSS-Protection` continua `1; mode=block` apesar do fix da Rodada 6 (`Header always unset` + `set "0"` no `.htaccess`) — ou seja, **a correção do R6-8 não teve efeito nenhum em produção**, confirmando que existe uma camada de headers fora do repo (vhost da VM e/ou Cloudflare — o `robots.txt` servido tem um bloco "Cloudflare Managed Content" que não está no arquivo do repo). Não tentei mais nada via `.htaccess` para este header específico — o relatório da Rodada 7 é explícito: nenhuma correção de código resolve isso, é preciso acesso a SSH/Cloudflare que não tenho. **Próximo agente: não repita a tentativa do R6-8. Antes de qualquer mudança de header, valide com `curl -I` pós-deploy, não por leitura de `.htaccess`.**
- 4ª ocorrência do padrão "guarda que não guarda" (depois de R5-8, R6-2, R6-5): 50 dos 119 arquivos em `tests/` nunca são invocados por nenhum dos 315 workflows, incluindo toda a suíte pytest (`pytest.ini` configura a suíte, mas nenhum workflow deste repo roda `pytest` — só um workflow de OUTRO repo, `mei-mg-email`) e a família inteira de guardas anti-regressão `no-*.php` (escritos depois de incidentes, especificamente pra impedir que voltem). `shopvivaliz-qa.yml` cita testes um a um por nome, então a lista diverge silenciosamente conforme testes novos são adicionados. Criei `.github/workflows/test-inventory.yml`: roda por glob (`tests/*.php` + `pytest`) em modo `continue-on-error: true` — só inventaria quantos passam/falham, não bloqueia nenhum PR. Promover a bloqueante e decidir o que fazer com os que falharem é decisão do Fred.
- `storage/rate-limit/<scope>/` crescia sem nenhuma rotina de limpeza — um diretório por escopo, um arquivo por IP+User-Agent (`svorl_client_key()` já é `hash(IP|UA)`), mas 4 dos 7 call-sites do wrapper legado (`includes/rate-limiter.php`) colocavam o IP *também* no identificador/escopo (`'login_' . $clientIp` etc.), multiplicando diretórios sem necessidade — o IP já estava na chave do arquivo. Corrigido em `auth/login.php`, `auth/register.php`, `api/cart/add.php`, `api/orders/create-v2.php` (identificador voltou a ser só o nome da funcionalidade: `'login'`, `'register'`, `'cart_add'`, `'order'`). **Não mexi nos outros 3 call-sites** (`api/liz-general.php`, `api/liz-intelligent-knowledge.php`, `api/liz-router.php`) porque `tests/liz-router-product-search-test.php:31` faz match exato da string `"RateLimiter::isAllowed('liz-search:' . \$ip, 10, 60)"` no fonte de `api/liz-router.php` — mudar o identificador quebraria esse teste. Se algum agente futuro quiser fechar esses 3 também, precisa atualizar o teste junto. Adicionei também um GC probabilístico em `svorl_allow()` (`includes/order-rate-limit.php`): 1/100 das chamadas apaga arquivos de rate-limit mais velhos que 10x a janela do escopo — mesmo padrão do GC de sessão do próprio PHP.
- `api/hotspot-endpoint.php` (recuperação de carrinho abandonado) estava 100% morto: nenhum rewrite leva a `/api/hotspot/track` ou `/api/hotspot/conversion` (as únicas rotas que o arquivo trata), e `public/hotspot-tracker.js` (que faria os POSTs) nunca é incluído em nenhuma página. Mesmo se o roteamento fosse consertado, o código tinha caminho relativo ao CWD (`.cart-abandonments.jsonl` dentro do release imutável) e um read-modify-write sem `LOCK_EX` em `.email-queue.json` — sem nenhum consumidor dessa fila no repo, o e-mail nunca seria enviado de qualquer forma. Neutralizado com stub 410. **Nota para quem ler a entrada de tarefas antigas do agente que diz "E-mail de carrinho abandonado: completed"**: pode haver outro mecanismo real em outro lugar do sistema — este arquivo específico nunca esteve conectado a nada; não investiguei se existe um caminho alternativo que funciona.
**Por quê importa:** R7-1 a R7-6 são coletivamente o achado mais grave desde o R6-1 (webhook de preço sem auth) — uma superfície de 46 arquivos, incluindo DDL em banco de produção, que ninguém tratava como pública. O padrão "validador/guarda que existe mas não roda" já apareceu 4 vezes em 7 rodadas (R5-8, R6-2, R6-5, R7-8) — vale tratar isso como categoria própria de auditoria daqui pra frente: sempre que um arquivo se chamar `validate-*`, `test-*` ou `no-*`, confirmar que roda em CI E que testa comportamento observável.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 2–6, acima), relatório completo em `outputs/rodada7-diagnostico.md` (5 itens pendentes de decisão do Fred: como reabrir `claude/dashboard/` se ele usa, quem controla os headers de verdade — SSH/Cloudflare, promover `test-inventory.yml` a bloqueante, inverter `.htaccess` pra default-deny — item estrutural repetido da Rodada 1, e checagem direta se o CI autônomo está mesmo parado há 47 dias / créditos de API zerados).

---

### 2026-08-19 — Rodada 8 de melhoria contínua (Sonnet, implementação)

**Sistema/arquivo:** `api/olist/sync-company-profile.php`, `api/gamification/status.php`, `.htaccess`, `agents/v9.2.85/scripts/fetch-shopee-listings.php`, `agents/v9.2.85/scripts/optimize-shopee-listings.php`, `agents/v9.2.84/installer/sync-runner.php`, `agents/v9.2.84/installer/agent-handoff.php`, `agents/v9.2.85/api/agent/update-status.php`, `api/webhooks/olist-company-sync.php`, `api/viacep-proxy.php`, `api/health.php`.
**O que descobri:**
- `api/olist/sync-company-profile.php` aceitava POST público sem nenhuma autenticação e reescrevia `config/company-profile.php` — consumido por 20 arquivos, incluindo o endereço de origem usado em etiquetas de envio; GET também vazava o perfil fiscal completo (CNPJ, inscrição estadual, endereço) sem guarda. Adicionei `sv_require_agent_key()` logo após os headers (mesmo padrão de `config/require-agent-key.php` usado desde a Rodada 6). Optei pela opção mais conservadora do relatório (gate em vez de remover o POST inteiramente).
- `api/gamification/status.php` também estava aberto e recebeu o mesmo guard de `sv_require_agent_key()`. **Efeito colateral aceito conscientemente**: o único consumidor, `/gamificacao.php`, busca esse endpoint via JS client-side sem header de agent-key e sem guarda de sessão própria — então o widget de leaderboard admin vai quebrar (ficar sem dados) até o Fred decidir como reabrir isso corretamente (guarda de sessão admin nos dois arquivos, não reabrir a API publicamente). Escolhi fechar agora porque um leaderboard de gastos de clientes exposto publicamente é o problema maior; a decisão de negócio sobre se esse painel deveria ser público ou admin-only é dele.
- Reincidência do padrão "protegido por acidente, não por controle" (visto em R7-2): `agents/v9.2.84/installer/sync-runner.php` passa `$_REQUEST` direto pro `GitHubPullUpdateAgent` (gatilho de deploy) e só não executava por faltar `app/GitHubPullUpdateAgent.php` no repo; `agents/v9.2.85/scripts/fetch-shopee-listings.php` já executa hoje, só falha por faltar as credenciais `TINY_*` — que é literalmente a tarefa pendente registrada em `docs/MEMORIA-AGENTES.md`. **Aviso pro próximo agente que for restaurar `TINY_*`: fazer isso sem primeiro revisar este achado ativa silenciosamente um endpoint de extração de dados do ERP sem autenticação.** Adicionei guarda `PHP_SAPI !== 'cli'` nos 4 scripts que o relatório apontou especificamente (`fetch-shopee-listings.php`, `optimize-shopee-listings.php`, `sync-runner.php`, `agent-handoff.php`) e estendi a lista de bloqueio do `.htaccess` (mesmo regex já usado pra `claude`/`web` desde a Rodada 7) pra também cobrir `agents`, `ops`, `agent-bridge` e `.ai`. **Não** apliquei o guard CLI-only em `agents/v9.2.85/api/agent/update-status.php` — esse arquivo fica em `api/agent/` (não em `scripts/`/`installer/`) e o relatório não recomendou o guard especificamente pra ele; pode ser um endpoint HTTP legítimo (agente reportando status). Fica protegido só pelo bloqueio de diretório no `.htaccess`.
- Confirmado via `curl` (requisitar um arquivo inexistente dentro de um diretório interno) um método de discriminação útil pra rodadas futuras: **404 = a própria regex do `.htaccess` deste repo bloqueou; 403 = bloqueio externo (vhost Apache da VM ou Cloudflare), fora do alcance de qualquer fix neste repo.** Descobri nesta rodada que existe uma camada de bloqueio no vhost Apache da VM (fora do repo, sem acesso SSH) que já bloqueia certas extensões (`.md/.py/.ps1/.csv/.toml`) e diretórios (`includes/`, `olist/`) mesmo sem estarem no `.htaccess` deste repo — e que o header `Content-Security-Policy-Report-Only` do repo passa intacto até produção (prova que o bloco `<IfModule mod_headers.c>` do `.htaccess` É aplicado), mas especificamente `X-XSS-Protection`, `Referrer-Policy` e `Expect-CT` são sobrescritos por uma camada posterior (provavelmente Cloudflare Transform Rules). **Raiz provável do padrão de 3 rodadas seguidas (R6-8, R7, R8) de diretórios internos vazando em produção**: `.gitattributes` não tem nenhuma linha `export-ignore` — `git archive` (usado pelo deploy real, `scripts/deploy-production.sh:447`) copia a árvore inteira, e o `.htaccess` é uma lista de bloqueio opt-out que precisa lembrar de cada diretório novo. **Não apliquei o fix de `.gitattributes`** — um `export-ignore` errado pode derrubar silenciosamente um asset servido de verdade no próximo deploy (cron roda a cada 2min); fica pra aprovação explícita do Fred, junto com o dump do vhost Apache e das Transform Rules do Cloudflare, que são os dois acessos que faltam pra resolver a divergência de headers de uma vez.
- `api/webhooks/olist-company-sync.php`: gravava o corpo cru de qualquer POST anônimo em log ANTES de checar auth (vetor de esgotamento de disco) e comparava o token com `!==` (timing-unsafe). Reordenado pra: rate-limit (`svorl_allow(30, 60, 'olist-company-sync')`) → checagem de auth com `hash_equals()` → só então grava log. Token aceita `OLIST_WEBHOOK_TOKEN` dedicado como prioridade, com `OLIST_SELLER_ID` como fallback (seller ID é identificador de baixa entropia, não deveria ser a única defesa — mantido só pra não quebrar a integração real antes do Fred rotacionar o segredo).
- `api/viacep-proxy.php`: `Access-Control-Allow-Origin: *` transformava a loja num proxy ViaCEP gratuito pra qualquer site terceiro, consumindo a quota/IP da própria VM — restringido a `https://shopvivaliz.com.br`. Sem rate limit, um varredor podia enumerar o espaço de CEPs válidos — adicionado `svorl_allow(60, 3600, 'viacep')`. Cache SQLite (`cep_cache`) nunca expurgava linhas antigas (mesma família do GC de `order-rate-limit.php` na Rodada 7) — adicionado GC probabilístico (1/200 chance, apaga linhas com mais de 60 dias).
- `api/health.php` tinha 3 checks puramente decorativos (`is_file()` só confirmando presença de arquivo, sem nenhuma sonda de comportamento): `Gamificacao pagina presente`, `Admin dashboard JS presente`, `Monitor admin presente`. Removidos — a presença desses arquivos já é garantida em tempo de deploy (git archive), não é um sinal de saúde em tempo de execução. Mantidos os checks que gate features de fato consumidas (fila, catálogo, GraphQL, gamificação API).
**Por quê importa:** R8-1 fecha um endpoint que podia reescrever silenciosamente o endereço de origem usado em etiquetas de envio de qualquer pedido — impacto operacional direto, não só teórico. R8-3/R8-7/R8-8 são a terceira rodada seguida encontrando a mesma classe de problema (diretório interno publicado em produção) com causas cada vez mais profundas identificadas — R6 corrigiu sintoma no `.htaccess`, R7 achou mais diretórios e a divergência de headers, R8 achou a causa raiz provável (`.gitattributes` sem `export-ignore`) e uma segunda camada de defesa (vhost Apache) que nenhuma rodada anterior sabia que existia. Vale manter o método de discriminação 404-vs-403 documentado aqui pra rodadas futuras não perderem tempo tentando corrigir via `.htaccess` algo que na verdade está bloqueado (ou não) numa camada fora do repo.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 2–7, acima), relatório completo em `outputs/rodada8-diagnostico.md` (itens pendentes de decisão do Fred: aprovar/revisar `.gitattributes` `export-ignore`; fornecer dump do vhost Apache da VM + Transform Rules do Cloudflare pra resolver a divergência de headers de uma vez; decidir o modelo de acesso do leaderboard de gamificação — público vs. admin-only — antes de reabrir `/gamificacao.php`).

### 2026-08-19 — Rodada 9 de melhoria contínua (Sonnet, implementação)

**Sistema/arquivo:** `api/webhook-mercadopago.php`, `api/webhook-infinitepay.php`, `includes/webhook-job-dispatcher.php`, `scripts/queue-worker.php`, `checkout-return.php`, `api/checkout/track-abandonment.php`, `auth/forgot-password.php`, `auth/reset-password.php`, `api/account/password-change.php`, `api/health.php`, `api/blog/comment.php`.
**O que descobri:**
- ⚠️ **ACHADO DE MAIOR IMPACTO DE TODAS AS 9 RODADAS ATÉ AGORA — NÃO IMPLEMENTADO, SINALIZADO AO FRED IMEDIATAMENTE (fora da cadência normal de fim-de-rodada, por ser dinheiro real em jogo).** O refactor de 2026-08-09 (`1e67242f0`, "harden ai studio queues and secrets") transformou os dois webhooks de pagamento de processamento síncrono para enfileiramento — e **nenhum processo consome essa fila em produção** (nenhuma unit systemd, nenhum cron, nenhum timer; só `docker-compose.yml`/`package.json`, que não rodam na VM). Mesmo se o worker subisse, o dispatcher (`includes/webhook-job-dispatcher.php`) implementa só uma fração do fluxo antigo — falta push pro Tiny/Olist, espelho MySQL, e-mail de confirmação/boleto/Pix, e conversão GA4/Ads (todos ainda existem, comentados, em `api/webhook-mercadopago.php:155-421`, a única documentação do que se perdeu). Combinado com R9-3 abaixo: **desde 09/08, todo cliente que paga não recebe confirmação, o pedido não sai de "aguardando pagamento", nada chega ao ERP, e a tela de retorno do checkout fica recarregando por 30s sem nunca confirmar.** Detalhes completos, evidência e passo-a-passo de confirmação (comandos SSH não-destrutivos) em `outputs/rodada9-diagnostico.md`, R9-1 e R9-2. **Não implementei nada disso** — subir um worker novo em produção e decidir a janela de reprocessamento retroativo são decisões que precisam da sua confirmação e, no caso da conciliação de pedidos pagos desde 09/08, do seu acesso ao painel do Mercado Pago.
- `checkout-return.php:27` montava o caminho do pedido com um shard por data (`substr($orderNumber, 2, 8)`) que nenhum writer real usa — corrigido pra usar `svmp_find_order_path()` (helper já existente, usado pelo webhook, cobre o diretório de fallback em `/tmp`). Fix de uma linha, sem risco, mas sozinho não resolve a tela de confirmação — depende também do R9-1/R9-2 (o pedido precisa chegar a `status === 'payment_approved'` no JSON, o que só acontece se o dispatcher rodar).
- `api/checkout/track-abandonment.php` aceitava POST anônimo sem rate limit, checagem de origem ou honeypot — um script externo podia inserir linhas arbitrárias e o cron `send-abandoned-cart-emails.php` (`*/30`) enviava e-mail real do domínio da loja pro endereço informado (~4.800/dia possíveis). Adicionei os mesmos três controles que `api/newsletter/subscribe.php` e `api/contact.php` já usam (checagem de origem, `svorl_allow(5, 3600, 'abandonment')`, honeypot).
- `auth/forgot-password.php` era o único fluxo público de e-mail sem rate limit (login e register já tinham desde as Rodadas 2/7) — adicionado `RateLimiter::isAllowed('forgot-password', 3, 3600)`, mesmo padrão do register. Também passou a invalidar qualquer token de reset anterior ainda pendente da mesma conta antes de emitir um novo (`UPDATE password_resets SET used_at = NOW() WHERE user_id = ... AND used_at IS NULL`) — sem isso, cada envio criava um token adicional válido por 1h sem nunca expirar os anteriores. Réplica do mesmo `UPDATE` em `auth/reset-password.php` (invalida os *outros* tokens pendentes ao trocar a senha com sucesso, não só o usado).
- `api/account/password-change.php` não regenerava o ID de sessão após trocar a senha (a troca acontece dentro de uma sessão já autenticada) — adicionado `session_regenerate_id(true)`, mesma prática que `auth/login.php` já usa no login. **Não** implementei invalidação de sessões em *outros* dispositivos/navegadores — isso exige uma coluna nova em `users` (`sessions_valid_after`) checada no bootstrap de sessão, mudança estrutural que afeta todo request autenticado; deixada pra aprovação do Fred.
- `api/health.php`, função `sv_health_probe_queue()`: o check `'Fila total operacional'` somava 4 contadores não-negativos e comparava com `>= 0` — sempre `true` por construção, nunca detectou nada. Trocado por um limiar real (`queued < 50`). É a 4ª/5ª ocorrência do padrão "guarda que roda e não detecta o comportamento que interessa" (depois de R6-4, R6-5, R8-9) — e desta vez o custo foi direto: se o check fosse real, o R9-1 teria sido pego no dia 09/08 por alarme automático, não 10 dias depois por auditoria manual. **Não** adicionei `oldest_queued_age_seconds` (sugestão do relatório) — exigiria tocar em `core/queue/queue.php` nos dois backends (arquivo e SQLite/PDO) sem conseguir testar contra produção real; fica pra quando o worker for corrigido junto (R9-1).
- **Verificado e sem problema** (ver relatório completo): zero SQL injection em toda a árvore `api/`/`admin/`/`includes/`/`auth/`/`olist/`/`blog/`; CSRF+sessão corretos nos endpoints de conta; preço/frete do checkout já são autoritativos no servidor no caminho realmente usado (`api/orders/create-validated.php` — o R5-9 não se aplica a esse caminho, só ao `create-v2.php` legado); `storage/` confirmado 403 pela própria regra do repo; nenhum segredo em JS do front-end.
**Por quê importa:** R9-1/R9-2 são categoricamente diferentes de tudo que as Rodadas 1-8 encontraram — não é exposição de dados nem desperdício de recurso, é o caminho de dinheiro do e-commerce quebrado silenciosamente há 10 dias, sem alarme (por causa do próprio R9-6). Vale tratar como P0 assim que você ler isso, antes de qualquer outra prioridade deste backlog.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 2–8, acima), relatório completo em `outputs/rodada9-diagnostico.md` (6 itens pendentes de decisão do Fred: subir o worker da fila — item de maior impacto do backlog inteiro; decidir até que data reprocessar a fila acumulada; reconciliar manualmente pedidos pagos desde 09/08 que não entraram no Tiny/Olist; uniformizar mensagens de erro do `register.php` que vazam existência de CPF/CNPJ; coluna `sessions_valid_after` pra invalidar sessões em outros dispositivos; reconfirmação dos itens estruturais já pendentes — `.gitattributes` export-ignore, `.htaccess` allow-list, acesso a vhost Apache/Cloudflare).

### 2026-08-19 — Correção crítica pós-Rodada 9: fila de pagamentos (Sonnet, fora da cadência de rodadas, autorizado por Fred)

**Sistema/arquivo:** `includes/webhook-job-dispatcher.php`, `includes/mercadopago-gateway.php`, `api/webhook-mercadopago.php`, `deploy/systemd/shopvivaliz-queue-worker.service`, `scripts/deploy-production.sh`.
**O que descobri e corrigi:**
- Confirmado via SSH (autorizado por Fred especificamente para este fix) que R9-1 estava certo: nenhuma unit systemd, timer ou cron consumia `shared/storage/queue.sqlite`. `systemctl list-units | grep queue` só retornava um serviço não relacionado (mei-mg-email). `queue_jobs` tinha 3 linhas, todas `status=queued`, nenhuma nunca processada.
- **Antes de reprocessar qualquer coisa, investiguei o conteúdo real da fila e da conta Mercado Pago — e o cenário é bem menos grave do que o R9-1/R9-2 fazia parecer:** os 3 jobs enfileirados são webhooks de **sandbox/teste** do Mercado Pago (`live_mode: false`, `external_reference` no formato `TST-QUALITY-*`/`SVTEST*`, IDs `ORDTST*`), não pagamentos reais. Consultei a API do Mercado Pago em modo leitura (`GET /v1/payments/search`, só consulta, nenhuma chamada de mutação) por todos os pagamentos `status=approved` desde 09/08 (86 no total) e **nenhum tem `external_reference` no formato de pedido do site (`SV\d{17}`)** — são todos transações de outro uso da mesma conta MP (maquininha/Point, "POTS_*", ~68 são numéricos sem relação). Cruzei com os arquivos de pedido locais: todo pedido `SV*.json` criado desde 09/08 está em `pending_confirmation` ou `payment_pending`, nenhum em `payment_approved`. **Conclusão: não há nenhum pedido real do site que tenha sido pago e ignorado desde 09/08** — bate com o que o Fred reportou (nenhum e-mail de venda recebido). Os 3 jobs na fila são ruído de teste, não passivo de conciliação.
- `php -l` limpo nos 3 arquivos (validado numa cópia em `/tmp` na própria VM, sem tocar produção).
- Criada `deploy/systemd/shopvivaliz-queue-worker.service` (`WorkingDirectory=/home/ubuntu/shopvivaliz-deploy/current` — não `site-shopvivaliz`, exatamente a armadilha que o R9-1 apontou) e adicionada à lista `RUNTIME_SERVICES` de `scripts/deploy-production.sh`, que já reinicia serviços a cada deploy — o worker agora acompanha automaticamente todo deploy futuro, sem precisar de intervenção manual.
**Por quê importa:** fecha o único gap de infraestrutura que impedia pedidos futuros de completar o fluxo pós-pagamento. Igualmente importante: a investigação mostrou que o risco financeiro imediato era menor do que a Rodada 9 sinalizou — vale registrar isso pra não reabrir a mesma investigação de reconciliação à toa numa rodada futura.

### 2026-08-19 — Validação ao vivo final: worker da fila de pagamentos confirmado estável (Sonnet)

**Sistema/arquivo:** `shopvivaliz-queue-worker.service` (systemd, VM produção).
**O que confirmei:** depois do fix de permissões (usuário `www-data` em vez de `ubuntu` no
systemd unit, ver entrada anterior), reinstalei a unit atualizada, reiniciei o serviço e
confirmei `active (running)` com o processo PHP de fato rodando como `www-data` (`ps -o
user,cmd`). Rodei `api/webhook-post-processor.php` diretamente como `www-data` contra um
pedido seguro (`payment_pending`, não aprovado — não dispara e-mail/GA4 real) e confirmou
que o erro `Permission denied ... Order lock unavailable` que existia antes do fix não
ocorre mais. `journalctl` mostra os 3 jobs de teste (sandbox MP) sendo processados e
corretamente rejeitados por `auth_not_validated` (comportamento esperado — são jobs
anteriores ao marcador de autenticação), sem crash-loop desde então. Fila sem backlog
pendente (`queue_jobs` só tem os 3 `failed` de teste).
**Por quê importa:** fecha o ciclo de validação do R9-1/R9-2 — o worker não só existe, como
está de fato escrevendo nos arquivos de pedido corretamente, o que era o segundo bug (além
da ausência de worker) que impedia o fluxo pós-pagamento de completar.
**Ver também:** entradas anteriores de 2026-08-19 sobre a fila de pagamentos.

### 2026-08-19 — Rodada 10 (final): XSS refletido em JSON-LD + guardas de autenticação faltantes (Opus + Sonnet)

**Sistema/arquivo:** `catalogo.php`, `produto.php`, `blog/index.php`, `blog/artigo.php`,
`faq/index.php`, `api/ml/login.php`, `api/ml/callback.php`,
`api/generate-boleto-email.php`, `api/send-order-confirmation-email.php`,
`api/liz-web-search.php`, `api/cnpj-proxy.php`, `CLAUDE.md`.
**O que descobri e corrigi (relatório completo em `outputs/rodada10-diagnostico.md`):**
- **R10-1 (CRÍTICO) — XSS refletido em `/catalogo` via JSON-LD:** `json_encode(...,
  JSON_UNESCAPED_SLASHES)` sem `JSON_HEX_TAG` nos blocos `<script type="application/ld+json">`
  permitia que o termo de busca (`?q=`) fechasse a tag `</script>` literalmente e injetasse
  HTML ativo — confirmado ao vivo em produção com payload inerte (`<svg onload=1>`), sem
  qualquer WAF/CSP bloqueando (a CSP real só tem `frame-ancestors`/`object-src`/`base-uri`; a
  `script-src` está só em modo Report-Only, não bloqueia nada). Trocado para
  `JSON_HEX_TAG | JSON_HEX_AMP` nos 5 arquivos que montam JSON-LD a partir de input do
  usuário (catálogo, produto, blog, FAQ), e adicionado limite de 120 caracteres no termo de
  busca como defesa em profundidade.
- **R10-2 (ALTO) — OAuth do Mercado Livre sem guarda:** a Rodada 5 fechou
  `api/ml/{me,status,token}.php` com `sv_require_agent_key()`, mas `api/ml/login.php` e
  `api/ml/callback.php` — os dois que efetivamente **iniciam e gravam** a credencial —
  ficaram abertos. Qualquer visitante anônimo podia autorizar o app com a própria conta ML
  e o callback sobrescrevia `ml-tokens.json` com os tokens dele. Adicionado `admin-guard`
  (mesmo padrão de `api/melhorenvio/connect.php`) nos dois arquivos. **Não** implementei a
  verificação de `user_id` esperado sugerida no relatório — fica pra decisão do Fred.
- **R10-3 (ALTO) — `api/generate-boleto-email.php` sem autenticação:** irmão do
  `api/generate-test-order.php` (fechado na Rodada 4), cria preferência real de R$99,90 na
  conta de produção do Mercado Pago + manda e-mail a cada request anônimo, sem ter recebido
  o mesmo tratamento. Adicionada a mesma guarda (`sv_require_agent_key()`). Também removi o
  fallback de e-mail pessoal hardcoded (`fredmourao@gmail.com`) — agora exige `ADMIN_EMAIL`
  no `.env`.
- **R10-4 (MÉDIO-ALTO) — `api/send-order-confirmation-email.php` sem guarda de CLI:** dos 3
  scripts de CLI em `api/`, era o único sem `PHP_SAPI !== 'cli'` — e é o que envia e-mail.
  Adicionada a guarda (mesmo padrão de `api/webhook-post-processor.php` e
  `api/melhorenvio/generate-label-background.php`). **Fica pra confirmação do Fred na VM:**
  se `register_argc_argv` estiver `On` sob PHP-FPM, o endpoint era um relay de e-mail aberto
  de fato (destinatário/corpo via query string) — a guarda de CLI resolve isso independente
  do resultado da verificação.
- **R10-5 (MÉDIO-BAIXO) — `api/liz-web-search.php`:** bloco `?test=1` roteável por HTTP
  disparava chamada paga à Google Custom Search sem autenticação. Bloco removido.
- **R10-6 (BAIXO) — `CLAUDE.md` desatualizado:** cabeçalho da seção de workflows dizia "59
  arquivos"; são 316 (e o número muda continuamente). Trocado por instrução de sempre rodar
  `ls .github/workflows/*.yml | wc -l` em vez de citar um número fixo.
- **R10-7 (BAIXO) — `api/cnpj-proxy.php`:** `Access-Control-Allow-Origin: *` sem rate limit
  (sem SSRF — validação de entrada já era correta). Removido o CORS aberto e adicionado rate
  limit (`svorl_allow`, mesmo padrão de `api/stock-alerts/subscribe.php`).
- **Não implementado, fica para o Fred** (ver `outputs/rodada10-diagnostico.md`, seção
  "Precisa de aprovação"): confirmar `register_argc_argv` na VM (R10-4); decidir sobre
  promover a CSP `script-src` de Report-Only para enforced (risco real de quebrar
  conversão/checkout, não é decisão pra agente); decidir o destino final de
  `api/generate-boleto-email.php` (manter com guarda vs. 410); confirmar no painel do
  Mercado Livre qual `user_id` está vinculado hoje, já que o endpoint esteve aberto (R10-2).
  **Não** adicionei entradas em `.htaccess` (sugestão de defesa em profundidade do
  relatório) — nesta rodada outro agente já tinha alterado esse arquivo concorrentemente;
  as guardas em código já são a correção primária e cobrem o release publicado
  independente do `.htaccess`.
**Por quê importa:** R10-1 é o primeiro XSS de execução real encontrado no ciclo inteiro,
numa página de tráfego orgânico/pago compartilhável por URL. R10-2/R10-3/R10-4 seguem o
mesmo padrão já mapeado nas rodadas anteriores — correção aplicada num arquivo e não no
irmão idêntico — o que sugere que vale, numa rodada futura, grepar por padrões de guarda
(`admin-guard`, `require-agent-key`, `PHP_SAPI`) e comparar contra todos os arquivos do
mesmo diretório, em vez de auditar arquivo por arquivo.
**Ver também:** relatório completo em `outputs/rodada10-diagnostico.md`, entradas anteriores
de 2026-08-19 sobre a fila de pagamentos (R9-1/R9-2), `docs/AGENTS.md` (Rodadas 1-9, acima).

### 2026-08-19 — Rodada 10.1: itens estruturais pós-Rodada-10 (aceitos por Fred)

**Sistema/arquivo:** `.htaccess` (CSP), `auth/register.php`, `.gitattributes`.
**Contexto:** depois do relatório final da Rodada 10, Fred aceitou todos os itens
pendentes de decisão e autorizou implementar o que fosse seguro fazer sozinho. Isto
cobre 3 desses itens; os que ficaram de fora (com justificativa) estão listados abaixo.

- **CSP promovida de Report-Only para enforced:** consolidado num único header
  `Content-Security-Policy` (a lista de domínios de terceiros — GA4/Ads/Meta/TikTok/
  Mercado Pago/InfinitePay/Cloudflare Insights — vinha sendo observada em modo
  report-only desde a Rodada 1 sem nenhuma violação inesperada). Mantém
  `'unsafe-inline'`/`'unsafe-eval'` em `script-src` porque os próprios scripts de
  analytics/checkout dependem disso hoje — **isso significa que esta CSP não bloqueia
  injeção de script/handler inline** (a classe do R10-1), só bloqueia script de domínio
  não listado e `<object>`/plugins. O fix do R10-1 continua sendo o `JSON_HEX_TAG`, não
  a CSP. Adicionado um segundo header `Report-Only` mais restrito (sem
  unsafe-inline/unsafe-eval) só de observação, pra acumular dados sobre o próximo passo
  (migração pra nonce) sem arriscar quebrar nada agora.
- **`auth/register.php`:** as duas mensagens de erro que confirmavam explicitamente
  "Este CPF/CNPJ já está cadastrado em outra conta" permitiam enumerar quais
  documentos (dado sensível) têm conta no site testando um a um. Trocadas por uma
  mensagem genérica que não confirma a existência do documento específico. A mensagem
  equivalente pra e-mail (`Este email já está cadastrado`) foi deixada como está —
  prática comum, risco bem menor que CPF/CNPJ.
- **`.gitattributes` `export-ignore`:** adicionado pra `docs/`, `outputs/`, `reports/`,
  `tests/` e `.claude/` — confirmei antes (busca em todo o repo) que nenhum `require`/
  `include` de código PHP aponta pra esses diretórios, então excluí-los do `git archive`
  usado pelo deploy real não deveria quebrar nada. Fiquei deliberadamente conservador:
  não toquei em `scripts/`, `agents/`, `.github/` nem em qualquer diretório que possa
  ser chamado por cron/CI/workflow via SSH — a raiz do problema (modelo opt-out do
  `.htaccess`, apontada desde a Rodada 7) continua sem resolução completa, isso reduz
  a superfície mas não a elimina.

**Itens levantados nas Rodadas 1-9 e NÃO implementados nesta passada (com motivo):**
- `.htaccess` allow-list → default-deny: continua exigindo acompanhamento ao vivo de
  alguém que conhece todas as rotas legítimas — risco real de bloquear algo em
  produção sem ninguém olhando. Fica pra quando o Fred puder acompanhar em tempo real.
- Política do cupom `VIVALIZ10` (anunciar publicamente ou não): decisão comercial, não
  técnica — documentado desde a Rodada 6, sem mudança de código possível sem essa
  decisão primeiro.
- Remoção de `catalogo-v2.php`: arquivo legado sem link interno, mas pode estar
  indexado no Google ou linkado externamente — apagar sem saber quebra SEO/backlinks
  silenciosamente. Fica pra decisão do Fred (posso implementar um redirect 301 pro
  `/catalogo/` se ele confirmar que quer aposentar o arquivo).
- `api/orchestrator/queue.php`: o arquivo já não existe mais no repo (removido em
  algum momento entre as Rodadas 6 e 10 por outro agente) — `api/orchestrator/status.php`
  hoje sempre falha com 500 por causa do `require` que aponta pra ele. Não é
  `queue.php` ou remover `status.php` de vez.
- `CRON_SECRET`: confirmado via SSH que **não está definido** no `.env` real da VM —
  `TINY_WEBHOOK_SECRET`/`OLIST_WEBHOOK_SECRET` (o outro item pendente da Rodada 6) **já
  estão configurados** (64 caracteres cada, confirmado sem expor o valor) e o log
  `logs/tiny-webhook-price.log` mostra o gate funcionando de verdade em produção. Não
  inventei um valor pra `CRON_SECRET` porque isso depende da decisão acima
  (`orchestrator/queue.php`) — sem ela, não dá pra saber se o secret ainda serve pra
  algo.
- `claude/dashboard/`: decisão de uso (alguém ainda acessa isso?), não técnica.
- Divergência de headers (`X-XSS-Protection`, `Referrer-Policy`, `Expect-CT`) entre
  `.htaccess` do repo e o que chega em produção: continua exigindo acesso ao vhost
  Apache da VM ou às Transform Rules do Cloudflare — nenhum dos dois está disponível
  por SSH/código. **Fica explicitamente pro Fred confirmar manualmente**: painel da
  Cloudflare do domínio `shopvivaliz.com.br` → Rules → Transform Rules (verificar se
  há alguma regra reescrevendo esses 3 headers específicos).
- `test-inventory.yml` promovido a check obrigatório: isso é uma configuração de
  branch protection do GitHub (Settings → Branches → main → Require status checks),
  não uma mudança de arquivo no repo — não tenho acesso a essa configuração via
  código/SSH. **Fica pro Fred fazer manualmente** (ou autorizar explicitamente o uso
  de um token/conector do GitHub com permissão de admin no repo).
- `sessions_valid_after` (invalidação de sessão em outros dispositivos): exige migração
  de schema (`ALTER TABLE users ADD COLUMN`) mais checagem em todo bootstrap de sessão
  autenticada — toquei em código que roda em 100% dos requests logados, e não tenho
  como testar interativamente contra o banco de produção antes de aplicar. Prefiro
  tratar como uma rodada dedicada, não um item a mais numa lista, dado o raio de
  impacto se algo sair errado (todo usuário logado seria afetado).
- Modelo de acesso do leaderboard de gamificação: já está seguro (guardado com
  agent-key desde a Rodada 8, efetivamente admin-only). A decisão que resta — tornar
  público ou não — é de produto, não de segurança; o estado atual não corre risco.
**Ver também:** `docs/AGENTS.md` (entradas das Rodadas 1-10, acima), relatório da
Rodada 10 (`outputs/rodada10-diagnostico.md`), resumo entregue ao Fred no chat.


**Sistema/arquivo:** `agents/policy-engine/index.js` (`isVisualFile()`/`validateVisual()`),
workflow `enforce`.
**Contexto:** PR com fix de normalização de hash (SHA256) do Facebook Pixel Advanced
Matching em `includes/analytics-tracking.php` foi bloqueado pelo check `enforce` com
"validação visual expirada" — mesmo a mudança sendo puramente server-side (normaliza
email/telefone antes do hash, sem nenhum efeito em layout renderizado).

- `isVisualFile()` decide por **path + extensão**, não por conteúdo do diff: qualquer
  arquivo `.php`/`.js`/`.css`/etc sob `includes/`, `public/`, `templates/`, `views/` ou
  `pages/` é tratado como visual, com exceções só para 2 arquivos específicos
  (`includes/product-seo.php`, `includes/catalog-authoritative-stock-carry.php`) e
  `includes/marketplace/*`. Isso é falso-positivo pra qualquer outra lógica puramente
  server-side dentro de `includes/` (helpers de tracking, normalização de dados, etc).
  propósito). Se a sessão não tem navegador conectado pra gerar `visual-proof.json`
  (screenshot + reviewer + timestamp <1h), separe a mudança visual-flagada num PR próprio
  e sinalize como bloqueada pendente de QA visual humana — não force merge, não edite
  `isVisualFile()` pra adicionar exceção sem autorização do Fred (mesmo que a exceção
  pareça óbvia — é uma decisão de política, não técnica).
- Outro achado no mesmo PR: 3 checks (`Audit agents with evidence`, `Audit repository
  automation`, `Resolve same-repository PR conflicts with private Oracle Gemini pool`) já
  estavam falhando repetidamente em `main` havia horas, sem relação com o diff — confirmado
  via `gh run list --branch main --workflow "<nome>"`. Antes de tratar uma falha de check
  como bloqueio do seu PR, confirme se ela já falha em `main` também.
- `gh pr edit` (gh CLI 2.45.0) falha sempre com `GraphQL: Projects (classic) is being
  deprecated...` mesmo sem nenhum campo de projeto envolvido — bug conhecido da versão.
  Workaround: `gh api repos/<owner>/<repo>/pulls/<n> -X PATCH -f title=... -f body=...`
  funciona normalmente.
- Branch protection está **desabilitada** neste repo (`gh api .../branches/main/protection`
  → 404 "Branch protection has been disabled"). `mergeStateStatus: UNSTABLE` não bloqueia
  merge de fato — é só informativo sobre checks não-obrigatórios pendentes/falhando.

## 🤖 Agentes Autônomos Ativos

| Agente | Tipo | Commits | Status |
|--------|------|---------|--------|
| Agente Autonomo ShopVivaliz | Primary | 2,542 | ✅ Ativo |
| fredmourao-ai | Developer | 1,573 | ✅ Ativo |
| Frederico Mourao | Developer | ~1,668 | ✅ Ativo |
| CI Summary Bot | Automation | 158 | ✅ Ativo |
| Claude Autonomo | AI | 212 | ✅ Ativo |
| Codex | AI | 135 | ✅ Ativo |
| CI Autônomo | CI | 142 | ✅ Ativo |

**Total histórico:** 11,600+ commits automáticos
**Frequência:** Auto-sync a cada 20-60 minutos

---

## 📄 Documentação Associada

- `docs/TINY-ERP-API-V3.md` — Schema completo do Tiny
- `docs/OLIST-API-V3.md` — Endpoints Olist v3
- `KNOWN_ISSUES.md` — Bugs conhecidos / em investigação
- `CHANGELOG.md` — Histórico de mudanças e fixes
- `CLAUDE.md` — Instruções gerais do projeto

---

**Última consolidação:** 2026-07-26 (entradas das Rodadas 2, 3, 4 e 5 de melhoria contínua adicionadas em 2026-08-18/19)
**Consolidado por:** Claude Code
**Próxima revisão:** Quando houver novo achado não-óbvio

## Regra ERP Olist/Tiny v3 para pedidos e NF

- Pedido local em `storage/orders` e na tabela `orders` é somente espelho/idempotência do checkout.
- Antes do pagamento aprovado, o pedido local deve ficar marcado como `local_storage_role=pre_payment_draft_mirror`.
- Pedido aprovado por Mercado Pago ou InfinitePay deve tentar `POST /public-api/v3/pedidos` via `includes/tiny-order-push.php`.
- Quando o ERP retornar id, grave `tiny_order_id`, `tiny_push=ok`, `erp_authority=tiny_v3_canonical_after_payment_approval` e mantenha o local como `payment_webhook_mirror`.
- NF, rastreio e etiqueta devem usar o webhook Tiny/NF e `tiny_order_id`; não recriar autoridade em JSON, MySQL ou arquivo temporário.
- Antes de PR/deploy envolvendo pedido, pagamento, NF ou etiqueta, rode `php scripts/quality/validate-order-erp-authority.php`.

## Amazon Returns & SAFE-T Recovery

- Fonte financeira: SP-API Orders/Finances; Gmail e rastreio sao sensores/evidencias, nunca verdade financeira isolada.
- Recebimento fisico em `admin/amazon-returns/intake.php` prevalece sobre tracking para confirmar que a devolucao chegou.
- Politicas D+45/D+60 ficam versionadas em `amazon_return_policies`; nao espalhar prazos em condicionais ad hoc.
- `UNKNOWN` para programa/iniciador ou pedido multi-item ambiguo bloqueia escrita externa ate reconciliacao oficial.
- Negativa SAFE-T automatica repetida e nao substantiva deve escalar para Ajuda uma unica vez por fingerprint; nao criar loop de respostas identicas.
- SAFE-T aprovada so fecha financeiramente quando o credito correspondente aparecer no ledger SP-API.

### 2026-09-02 — Reports (GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE) e programFromOrder (Sonnet)

- **Achado:** `createReport` sozinho nunca preenchia `amazon_return_cases` — faltava o
  poll (`GET /reports/2021-06-30/reports/{id}`) + download do documento (`GET
  /reports/2021-06-30/documents/{id}`, presigned URL sem headers SP-API, GZIP
  opcional) + parse. Implementado em `SvAmazonReturnsSpApi::getReportStatus/
  getReportDocument/downloadReturnsReport` + `SvAmazonReturnsReportParser` (parser
  puro) + `SvAmazonSpApiEventSink::persistReturnsReportRow` + orquestração em
  `daemon.php::pollAndIngestReturnsReport` (poll limitado a ~2min por tick; se não
  finalizar a tempo, o próximo tick de `returns_report` pede um relatório novo —
  não há necessidade de persistir report_id entre ticks).
- **Layout real confirmado** (TSV, cabeçalho com nomes exatos, não documentado
  como golden fixture em nenhum outro lugar do repo): `Order ID`, `Order Item ID`,
  `A-to-Z Claim` (Y/N), `Resolution` (ex.: `RefundAtFirstScan`), `Return Reason`,
  entre outras. `refund_initiator` só é classificado com confiança quando
  `A-to-Z Claim=Y` (→ `A_TO_Z`) ou `Resolution` contém `RefundAtFirstScan` (→
  `AMAZON_AUTOMATIC`); qualquer outro valor fica `UNKNOWN` — não adivinhar.
- **Bug real em `SvAmazonSpApiEventSink::programFromOrder`:** o código checava
  `$order['fulfillment']['channel']`/`fulfillmentChannel`, mas o campo real
  retornado pela Orders API v2026-01-01 é `fulfilledBy` (`AMAZON`|`MERCHANT`).
  Resultado: **todo pedido FBA padrão (o caso mais comum) caía em `program=UNKNOWN`**,
  mesmo com dados de fulfillment presentes — não era falta de dado, era nome de
  campo errado. Corrigido para checar `fulfilledBy` primeiro, mantendo `channel`/
  `fulfillmentChannel` como fallback (não quebra fixtures antigas). Confirmado
  com chamada real contra dois pedidos de produção antes de corrigir.
- **Por que importa:** esses dois bugs juntos explicavam os 15 casos presos em
  `unclassified` mesmo com Finances já autorizado e `seller_debit_at` populado
  corretamente — `refund_initiator`/`program` nunca tinham de onde vir. Antes de
  investigar "por que unclassified não zera", confirme se é falta de fonte de
  dado ou nome de campo errado lendo uma resposta real da API (não assuma pelo
  nome do campo no código).
- **Limite real de janela do `GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE`:**
  confirmado empiricamente em produção que a Amazon aceita no máximo ~30 dias
  entre `dataStartTime`/`dataEndTime` para este relatório — janelas de 90 ou
  120 dias voltam com `processingStatus=FATAL` (não documentado de forma clara
  nos docs públicos da SP-API). Backfill histórico precisa rodar como vários
  requests sequenciais de até 30 dias cada.
- **Depois de corrigir `programFromOrder` e rodar o backfill do relatório em
  produção (2026-09-02), os 15 casos que estavam com `unclassified=1` desde
  antes desta sessão tiveram `program` corrigido para todos, mas
  `refund_initiator` continuou `UNKNOWN` para 13/15** mesmo testando janelas
  de relatório cobrindo maio-agosto/2026 — nenhuma linha do relatório bateu
  com esses `amazon_order_id`. Hipótese mais provável: são exatamente o tipo
  de caso que justifica um SAFE-T (Amazon reembolsou o cliente sem existir um
  "return request" físico registrado — item perdido no fulfillment, por
  exemplo), então não há linha correspondente no relatório de devoluções por
  definição. `refund_initiator=UNKNOWN` nesses casos é o comportamento correto
  do sistema (bloqueia escrita externa até reconciliação oficial, por design),
  não um bug a "corrigir forçando" um valor. Se precisar fechar esses casos
  específicos, a evidência tem que vir de outra fonte (ex.: revisão manual via
  Seller Central, ou um e-mail de Ajuda que confirme o motivo) — não adivinhar
  a partir de Finances/Orders/Reports, que não expõem essa informação para
  este cenário.

### 2026-09-02 — Bridge do Seller Central em 401 permanente: Apache/mod_php não repassa Authorization (Sonnet)

- **Sintoma:** `POST /api/amazon-returns/bridge.php` sempre respondia
  `{"status":"UNAUTHORIZED"}` (HTTP 401), não importa qual valor de
  `SELLER_CENTRAL_BRIDGE_TOKEN`/`bridge.token` (Windows) fosse usado — inclusive
  com o token 100% correto e verificado byte a byte dos dois lados.
- **Causa raiz real:** Apache com `mod_php` (`php_module`, não PHP-FPM) **não
  estava populando `$_SERVER['HTTP_AUTHORIZATION']`** para este endpoint —
  confirmado com um script de debug temporário que imprimiu
  `apache_request_headers()` vs `$_SERVER`: o header `Authorization` chegava
  normalmente do Cloudflare até o Apache (visível em `apache_request_headers()`),
  mas nunca aparecia em `$_SERVER`. Isso é um comportamento conhecido (não um
  bug de config específico deste site) de Apache+mod_php em várias combinações
  de versão/distro — Apache trata `Authorization` como reservado para seus
  próprios módulos de auth (`mod_auth_basic` etc.) e não o repassa ao CGI/SAPI
  automaticamente.
- **Como foi diagnosticado:** ~2h perdidas comparando hashes SHA-256 do token
  de formas inconsistentes (`grep|cut|sha256sum` incluindo `\n` final vs
  `trim()` do PHP/Node) antes de perceber que o token *nunca* foi o problema.
  **Lição para o próximo agente:** se um Bearer token continua falhando mesmo
  depois de confirmar que client e servidor têm o **mesmo valor exato**
  (compare sempre com `trim()` dos dois lados, nunca hash de string com/sem
  newline final misturado), o próximo passo é confirmar que o header
  *chega* no `$_SERVER` do PHP antes de suspeitar do valor do token de novo.
- **Correção aplicada:** `SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader()`
  (novo método) tenta `$_SERVER['HTTP_AUTHORIZATION']` /
  `REDIRECT_HTTP_AUTHORIZATION` primeiro e cai para `apache_request_headers()`
  quando vazio. `api/amazon-returns/bridge.php` usa esse método em vez de ler
  `$_SERVER` diretamente. Qualquer outro endpoint deste projeto que dependa de
  `Authorization: Bearer ...` sob Apache/mod_php deve considerar o mesmo padrão.
  diagnóstico, um arquivo temporário na raiz do documento (`/debug-*.php`) foi
  bloqueado por uma regra de firewall da aplicação ("Área protegida", 403) —
  arquivos soltos na raiz são tratados como não autorizados. Um arquivo dentro
  de `api/amazon-returns/` (mesma pasta do `bridge.php`) passou normalmente.
  Qualquer arquivo de debug ad hoc deve ser removido do release/produção assim
  que o diagnóstico terminar — nunca commitado.

### 2026-09-03 — Fase 1 (nenhum SAFE-T write oficial), merge/deploy do fix do bridge, e bug de CI no diff base do `workflow_dispatch` (Sonnet)

- **Confirmado com evidência (não suposição):** a Finances API `v2024-06-19` expõe
  só `GET /finances/2024-06-19/transactions` (leitura; inclui dados tipo
  `SAFETReimbursementEvent` para observar crédito já concedido). Nenhum dos 53
  modelos oficiais em `amzn/selling-partner-api-models` (checado em
  2026-09-03) tem `claims`/`safe-t`/`a-to-z`/`returns`. Não existe submissão de
  SAFE-T via SP-API — o desenho já aprovado neste repo (adapter de browser do
  Seller Central) é o canal correto, não um workaround.
- **PR #1381 mesclada e implantada:** o fix de `HTTP_AUTHORIZATION` (entrada
  de 2026-09-02 acima) estava commitado e no `origin` desde ontem mas sem PR
  aberta — só depois disso o bridge Windows (`seller-central-bridge-worker.mjs`,
  polling `api/amazon-returns/bridge.php` a cada ~30s) parou de logar
  `worker_error` a cada tentativa. Validado ao vivo pós-deploy com
  `curl -H "Authorization: Bearer $(cat bridge.token)"` contra produção:
  passou de 401 para 400 `INVALID_OPERATION` (token aceito, só a ação de
  teste é que é inválida — prova real, não inferência de log).
- **Bug real encontrado no próprio CI:** `.github/workflows/repository-governance.yml`,
  step "Resolve comparison base" — o fallback para `workflow_dispatch`
  (`base="$(git rev-parse HEAD^)"`) pega só o primeiro-pai de um commit de
  merge, um ponto arbitrário anterior ao merge com `main` em vez de onde a
  branch realmente divergiu. Como esse workflow também roda via
  `workflow_dispatch` como "run sombra" quando a run real de `pull_request`
  fica presa em `action_required`, isso gerava falso-positivo de "changed
  automation surfaces" em arquivos que a PR nunca tocou (ex.:
  `master-production-pipeline.yml`, reescrito por outro agente no `main` na
  mesma janela). Corrigido para `git merge-base HEAD origin/main` nesse
  fallback. Qualquer novo caso de "check falhando em arquivo que a PR não
  mexeu" merece conferir esse mesmo padrão antes de assumir que é regressão
  real.
- **Estado real em produção 2026-09-03 ~04:43 UTC:** 15 casos rastreados,
  `pending_outbox=0`, todos os 15 com decisão do scheduler mas **nenhum**
  ainda `SAFE_T_READY`/enfileirado — não por bug, mas porque `refund_initiator`
  segue `UNKNOWN` em 13/15 (ver entrada 2026-09-02 acima: são exatamente os
  casos sem linha correspondente no relatório de devoluções, por definição).
  Write flags (`SAFE_T_WRITE`/`APPEAL_WRITE`/`SUPPORT_WRITE`) seguem `0` em
  produção — piloto controlado ainda não foi habilitado, isso é intencional
  (ver rollout no spec), não uma lacuna deste fix.
