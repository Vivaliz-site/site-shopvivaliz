## Regra universal de continuidade e esgotamento de rotas — todos os agentes e repositorios (2026-10-31)

**Obrigatoria para toda execucao e todo agente, independentemente do fornecedor, modelo ou interface:** ChatGPT, Codex, Claude, Gemini, agentes autonomos, bots, workers, scripts, GitHub Actions, ferramentas MCP e futuros agentes. Aplica-se a todos os repositorios e organizacoes GitHub sob gestao da ShopVivaliz, nao apenas a este repositorio.

**Nao abandonar tarefas por um bloqueio tecnico isolado.** Investigar a causa raiz, corrigir o que for seguro e autorizado, testar novamente e inventariar sistematicamente todas as rotas REALMENTE provisionadas e independentemente autorizadas antes de alegar bloqueio: MCP/Remote Control, conectores, Cloudflare/cloudflared e tuneis, GitHub CLI/API com identidade correta e credenciais protegidas, SSH privado/Tailscale, RDC 1/2, navegadores de agentes no host permitido, VMs ativas, runners/Actions, OCI, Superpowers quando disponivel e outros recursos identificados na documentacao vigente. Nao assumir disponibilidade, permissao ou sucesso sem teste e evidencia. Nao expor segredos nem criar credenciais paralelas.

Para cada rota registrar disponibilidade, identidade, escopo de permissao, lease/fencing quando aplicavel, tentativa, resultado, causa da falha, reparo e proxima alternativa. Nao repetir indefinidamente a mesma tentativa. Prosseguir nas etapas independentes, preservar checkpoints duraveis e retomada automatica autorizada. A tarefa so termina com correcao, prevencao, testes, validacao runtime/E2E real e merge apos gates quando aplicavel, ou bloqueio externo comprovado apos esgotamento de todas as rotas legitimas. Nenhum status RUNNING antigo comprova execucao ativa.

**Limites absolutos:** nao contornar MFA, CAPTCHA, RBAC, 401/403, recusa expressa, branch protection, aprovacoes, leases, isolamento de hosts ou politicas do provedor. Rota alternativa nunca pode ser usada para burlar uma negativa. Manter pendencias abertas com evidencia e proxima acao quando existir impedimento legitimo.

**Propagacao:** manter esta regra nos arquivos de instrucoes de agentes de cada repositorio ativo (AGENTS.md, CLAUDE.md, GEMINI.md, instrucoes Codex e equivalentes existentes), respeitando a governanca local; verificar aplicacao por inventario e testes, nao por presuncao.

<!-- CURRENT_TWO_VM_AND_RECOVERY_GATE_20261031 -->
> **Regra vigente (31/10/2026, confirmada pelo proprietario):** existem apenas duas VMs OCI operacionais: `marina-a1-4c24g` (web/deploy/banco/workers) e `always-free-arm-1787907847-26` (backend/MCP/controller/browser). `shopvivaliz-free-a1` foi encerrada: NAO acessar, pesquisar como destino, monitorar, recuperar, recriar ou redirecionar aliases para ela. Referencias historicas abaixo nao representam destino ativo.
>
> **Continuidade obrigatoria contra bloqueios:** uma falha isolada de terminal, usuario de execucao, GitHub CLI, SSH, plugin, runner ou browser NAO comprova ausencia de acesso e NAO encerra a tarefa. Ler documentacao de acesso vigente, conferir usuario/contexto correto e fontes protegidas ja provisionadas, testar rotas alternativas independentemente autorizadas (MCP, RDC 1/2, SSH privado/Tailscale, GitHub via perfil autorizado, runner/OCI apenas para recuperacao), corrigir falhas tecnicas, preservar checkpoints e prosseguir ate teste, merge, deploy automatico aplicavel e validacao posterior. Exemplo confirmado: `gh` estava autenticado sob `ubuntu`, nao sob `root`; executar via identidade autorizada sem revelar tokens. Proibido contornar MFA, RBAC, recusas explicitas, controles de branch ou leases. Se todas as rotas legitimas falharem, registrar evidencia e proxima acao, sem declarar sucesso.
<!-- /CURRENT_TWO_VM_AND_RECOVERY_GATE_20261031 -->

<!-- PR_RELEVANT_GATES_ONLY_V1 -->
## Regra permanente: gates somente por impacto comprovado da PR
Para qualquer PR da ShopVivaliz, os agentes devem relacionar arquivos alterados, dependencias, contratos compartilhados, superficies de seguranca e areas de impacto **antes** de escolher testes e workflows. **Gates especializados de site, API, worker, catalogo, estoque, precos, carrinho, MCP, agentes ou continuidade so podem executar quando houver relacao tecnica demonstravel com a mudanca.** Testes de um subsistema nao relacionado nao devem ser acionados apenas por existir uma PR ou por alteracao generica de AGENTS.md.

O seletor deve usar comparacao verificavel base...head com delecoes incluidas, criterios positivos estritos por path/dependencia e evidencias. Escopo vazio, diff indisponivel, caminho desconhecido, politica transversal, alteracao de CI/seguranca, dependencia compartilhada ou qualquer ambiguidade exigem *fail-closed* e suite ampliada; nunca omitir um check que possa comprovar regressao material. **Mandatory Validation Gate, Repository Governance, bridge Absolute Audit V5 e Main Guard permanecem obrigatorios conforme a fase** (PR/main); sua validacao interna pode escolher testes pertinentes com guardrails universais preservados.

Implementacao inicial no repositorio site: `scripts/ci_impact_selection.py`, `scripts/ci_continuity_impact.py`, `scripts/ci_scoped_validate.sh` e `tests/test_ci_relevant_specialized_gates.py`. Bloquear regressao de workflow especializado sem filtro `paths`; gates globais de seguranca sao excecao expressa. Registrar no PR quais gates foram selecionados e por que; comparar duracao de fila vs job e evitar reexecutar suites caras duplicadas sem relacao com o diff. Proibido trocar conclusao de teste por warning, enfraquecer check requerido, contornar branch protection ou alegar APTO sem certificador.
<!-- /PR_RELEVANT_GATES_ONLY_V1 -->

<!-- TASK_SESSION_CONTINUITY_V1 -->
## Regra operacional: concluir a tarefa nao depende do fim da sessao (2026-10-30)

**Ordem do proprietario:** uma tarefa autorizada e ainda executavel nao pode ser pausada ou declarada concluida por habito do agente, termino da resposta, troca de conversa, timeout, diagnostico, entrega de relatorio, abertura de PR ou sucesso de CI isoladamente. O agente executa o proximo passo permitido, corrige as causas, testa, valida e registra o resultado. O controlador de continuidade e contingencia para travamentos, **nao substitui a obrigacao de execucao do agente principal**.

- **Tarefa diferente de sessao:** limites de duracao da sessao, cotas de IA paga e politicas de anti-loop controlam execucoes abandonadas, repetitivas, sem progresso ou de custo indevido. Nao sao prazo de validade nem autorizacao para abandonar uma tarefa com progresso verificavel. Quando a sessao legitima precisar terminar, preservar `RUNNING`, evidencias, ownership/lease valido, `next_action` especifica e destino de retomada efetivamente comprovado; nao prometer atividade em segundo plano sem executor real.
- **Estado terminal exige prova:** `CONCLUIDO` somente apos correcoes persistidas, testes GREEN, regressao e E2E/UI real quando aplicavel, no SHA/ambiente efetivo. Se houver impedimento externo comprovado, manter a pendencia documentada em `BLOCKED_EXTERNAL` ou `AWAITING_APPROVAL` com autorizacao exata faltante, sem tratar o bloqueio como sucesso. PR em rascunho, checks verdes ou proposta administrativa nao equivalem a aplicacao em `main`.
- **Leitura de instrucoes antes de executar:** ler `AGENTS.md`, `docs/knowledge/atendimento-chatgpt-bootstrap.md`, `host-access.md`, `README.md`, este `agent-rules.md` e os documentos especificos da tarefa no `main` vigente. Registrar `rules_loaded` com caminho/ref/SHA/instante e obter `read -> acknowledge -> verify` do preflight real quando aplicavel. Um recibo de entrega ou simples ACK nao substitui leitura e interpretacao; falta de prova bloqueia a mutacao, nao a investigacao segura somente-leitura.
- **Revisao humana especifica:** quando o proprietario determinar que ele mesmo fara o merge, deixar a branch/PR em revisao, sem auto-merge e sem deploy; continuar os testes, reparos e atividades independentes autorizadas sem fechar a tarefa artificialmente.
- **Protecoes preservadas:** nenhuma exigencia de continuidade dispensa MFA, RBAC, segredos, aprovacoes externas, branch protection, leases/fencing, custos ou recusas explicitas. Nao criar segundo executor para substituir um controlador existente nem repetir por outra ferramenta uma acao expressamente recusada.

<!-- /TASK_SESSION_CONTINUITY_V1 -->

<!-- EXHAUST_ALL_AUTHORIZED_ROUTES_GATE_20261030 -->
## Regra obrigatoria: continuidade ate esgotamento comprovado de rotas autorizadas (2026-10-30)

Quando o usuario disser "retome", "continue", "nao pare" ou solicitar conclusao integral, **nao encerrar por falha de uma unica ferramenta, autenticacao, pagina ou transporte**. Antes de classificar qualquer tarefa como bloqueada, o agente deve verificar, para o host e operacao especificos, cada rota REALMENTE instalada, acessivel e autorizada: (1) conectores oficiais do servico e MCP ShopVivaliz/controlador; (2) RDC/Remote Desktop Commander 1 e 2, com identificacao atual do dispositivo e privilegios efetivos; (3) SSH privado/Tailscale e tuneis Cloudflare/MCP independentes comprovados; (4) navegador/sessao grafica corporativa ja autenticada e respectivo controle de interface autorizado; (5) OCI Bastion, runner e GitHub Actions apenas nas funcoes de bootstrap e recuperacao oficialmente autorizadas. Nao pressupor que um tunel concede permissao administrativa, que uma sessao grafica permite cliques por MCP ou que uma credencial valida para leitura autoriza escrita.

**Para cada rota:** obter evidencia do estado real, identidade, autorizacao, lease/fencing, resultado da acao e motivo de indisponibilidade; distinguir NOT_PROVISIONED, DENIED_BY_PROVIDER, AUTH_EXPIRED, RUNTIME_LOCKED, NOT_REACHABLE e VERIFIED_OK. Reutilizar credenciais e sessoes protegidas ja provisionadas sem expor segredos nem pedir credenciais repetidas. Se uma rota falhar por motivo tecnico reparavel, corrigir, testar novamente e prosseguir para as demais rotas permitidas. Registrar checkpoint duravel e proxima acao acionavel; manter o incidente aberto e a continuidade pelo controlador existente, sem duplicar workers ou automacoes. Nao afirmar APTO/CONCLUIDO antes de validacao de escrita, persistencia, regressao e UI E2E quando houver interface.

**Limite inegociavel:** a exaustao de caminhos nao autoriza contornar um 401/403 ou recusa explicita do provedor, RBAC, CAPTCHA/MFA, protecoes de plataforma, runtime_lock_required, approvacao obrigatoria ou delimitacao do escopo comercial. Outra rota so pode ser utilizada para finalidade INDEPENDENTEMENTE autorizada; nunca como bypass da mesma acao negada. Preservar dados, precos, estoques, publicacoes organicas e terceiros. Se depender de permissao externa, identificar o provedor, capacidade faltante, evidencias, fluxo oficial de liberacao e status NAO_CONCLUIDO/BLOCKED_EXTERNAL, continuando por tarefas seguras executaveis e sem prometer execucao em segundo plano inexistente.

**Exemplo Mercado Ads #3416:** advertiser 20098, token com leitura de Product Ads mas escrita explicitamente recusada pelo Mercado Livre HTTP 401. A UI autenticada no laptop nao equivale a ferramenta de clique autorizada, e o agrupamento por variantes impede pausar toda campanha/grupo misto quando anuncios ROAS >=12x devem permanecer ativos. Conferir oficialmente permissao de escrita e operacao individual sem alterar publicacoes organicas. Evidencia: https://github.com/Vivaliz-site/site-shopvivaliz/issues/3416 .
<!-- /EXHAUST_ALL_AUTHORIZED_ROUTES_GATE_20261030 -->

<!-- REMOTE_ROUTE_EXHAUSTION_V2_20261030 -->
## Regra operacional: esgotar caminhos reais de acesso antes de parar

Em qualquer retomada, auditoria V5, correcao, deploy ou validacao de projeto, o agente NAO encerra por uma falha isolada de API, comando, ferramenta, sessao, navegador ou transporte. Examinar, nesta ordem e conforme o runbook vigente, TODAS as rotas concretamente disponiveis e autorizadas para o host e a operacao: MCP ShopVivaliz Remote Control/Orquestrador; Remote Desktop Commander 1 e 2; tunel privado documentado, SSH/Tailscale; navegador grafico autorizado; plano de controle OCI/GitHub Actions exclusivamente para bootstrap/recovery. Confirmar identidade do destino, usuario, permissoes, lease/fencing, capacidade efetiva e estado de cada rota. A VM historica excluida NUNCA sera reativada, contatada ou recriada. Marina e a producao; backend e o controlador conforme topologia viva.

O agente deve tentar corrigir a causa tecnica dos erros de conexao, testar novamente de forma limitada e idempotente, e seguir para a proxima rota adequada quando houver. Registrar em checkpoint nao secreto: host, caminho, estado, teste, resultado, correcoes, evidencia temporal e proxima acao. Uma rota nao provisionada nao conta como rota testada. Nao transferir credenciais/cookies entre perfis nem contornar MFA, RBAC, recusas explicitas de plataforma, controles de seguranca ou protecoes de branch. Alternativa tecnica nao equivale a autorizacao para bypass.

A tarefa permanece RUNNING/NAO_APTO ate a verificacao do ciclo diagnosticar -> corrigir -> prevenir -> testar -> validar, incluindo UI grafica real quando aplicavel. Nunca declarar CONCLUIDO ou APTO por HTTP 200, testes unitarios ou logs apenas. Se todas as rotas seguras e autorizadas forem comprovadamente esgotadas, registrar BLOCKED_EXTERNAL com evidencia e next_action; encaminhar ao mecanismo de retomada ja existente, sem inventar execucao em segundo plano, duplicar agentes ou mascarar pendencias.
<!-- /REMOTE_ROUTE_EXHAUSTION_V2_20261030 -->

<!-- RUNTIME_SOURCE_ROUTES_V2_20261029 -->
> **Estado de runtime posterior, prevalente (2026-10-29):** o MCP central do backend agora anuncia **somente backend, Fred-Win e KOCEPSV**; a origem `shopvivaliz-free-a1` foi removida da allowlist (PRs #3391/#3392) e nunca e alvo. Marina permanece fora do MCP central, mas RDC direto e SSH privado foram comprovados; o tunel SSH do MEI para Marina esta ativo (HTTP 200), separado do MCP independente Marina que continua nao instalado. O PR #3395 bloqueou 20 jobs antigos sem migrar automaticamente seus objetivos. Controlador 24x7 ainda precisa de lease valido e recuperacao de checkpoints; CI `mei-ci-a1` segue sem registro. Declaracoes de "MCP ainda inclui origem" abaixo sao apenas historicas. Referencia: `docs/knowledge/current-topology-and-access-2026-10-29.md`.
<!-- /RUNTIME_SOURCE_ROUTES_V2_20261029 -->

<!-- ACTIVE_MARINA_ROUTING_20261029 -->
> **Obrigatório para todo agente em 2026-10-29:** ver `docs/knowledge/current-topology-and-access-2026-10-29.md` e `docs/knowledge/host-access.md` no `main`. **Marina (`marina-a1-4c24g`/OCI ESPOSA) = site/deploy/banco/workers, RDC direto comprovado; backend (`always-free-arm-1787907847-26`/OCI PRINCIPAL) = MCP central/controller/browser.** `shopvivaliz-free-a1` foi excluída: não é alvo e não deve ser recriada. A allowlist MCP central ainda inclui o nome antigo e não inclui Marina. O MCP independente Marina e seu túnel estão inativos; cloudflared ativo não equivale a MCP registrado. Não redirecionar aliases, não utilizar scripts/workflows legados contra origem; exigir identidade, direitos, lease e prova específicos antes de cada execução.
<!-- /ACTIVE_MARINA_ROUTING_20261029 -->

<!-- CURRENT_HOST_ACCESS_POLICY_20261029 -->
**Topologia de acesso vigente:** `marina-a1-4c24g` (OCI ESPOSA) e producao web/deploy; `always-free-arm-1787907847-26` (OCI PRINCIPAL) opera o backend/browser e o **Remote Control MCP** central. A origem `shopvivaliz-free-a1` foi excluida segundo o proprietario; nunca acessa-la. O MCP central ainda nao inclui Marina; para ela, RDC 1/2 direto tem evidencias de acesso, enquanto o MCP independente permanece inativo. Revalidar `docs/knowledge/current-topology-and-access-2026-10-29.md` antes de operar.
<!-- /CURRENT_HOST_ACCESS_POLICY_20261029 -->

<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
## REGRA ABSOLUTA: todo erro identificado exige correcao real e validacao

Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, bloqueio tecnico, resultado incorreto ou problema de validacao detectado **nao pode ser ignorado, apenas documentado ou encerrado sem remediacao**.

Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

**Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicitos e gate de I/O. Se a tarefa/automacao for `SOMENTE LEITURA`, **nao executar mutacoes**: registrar e encaminhar cada defeito ao mecanismo de reparo autorizado ja existente. Proibido contornar recusas por conectores, shell ou agentes alternativos ou duplicar agentes, monitorias, automacoes, tarefas, bindings e PRs.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->
## Auditoria Extrema V5: execucao obrigatoria na UI real (gate de aceite)

Auditorias extremas, completas, V5 ou de aptidao de qualquer sistema com interface exigem **execucao do proprio agente na UI real publicada**, percorrendo as jornadas e casos aplicaveis de ponta a ponta. Logs, API, SQL, codigo, mocks, simples HTTP 200, screenshot isolado ou execucao somente headless nao substituem interacao visual. Para cada fluxo: entrar na tela real, navegar, acionar controles, observar erros, gravar provas de antes/depois, recarregar/revisitar, confirmar persistencia e reconciliar efeitos. Investigar, corrigir, prevenir e reauditar pela UI apos cada correcao aplicavel. Sem evidencias reais: `NAO_VALIDADO_UI` e nunca `APTO`/`CONCLUIDO`. Proibido esconder UI existente em `browser.required=false` ou `journey.ui=false`; a ausencia real de UI requer inventario negativo documentado e evidencias. Usar hosts, perfis e leases autorizados, sem bypass de MFA ou acoes irreversiveis sem permissao exata. Ver `AGENTS.md`, `AUDIT_POLICY.md` e `docs/quality/AUDIT_BROWSER_E2E_REAL_V1.md`.
<!-- /EXTREME_AUDIT_UI_FIRST_HARD_GATE_V1 -->

<!-- REMOTE_EXHAUSTION_EVIDENCE_V2_20261030 -->
## Retomada obrigatoria: esgotamento comprovado de rotas (RDC/MCP/tunel)

Para qualquer agente ShopVivaliz (ChatGPT, Codex, Claude, Gemini, controlador e subagentes), `retome`, `continue`, `prossiga` e equivalentes significam executar ate o aceite validado ou impedimento externo comprovado, nao encerrar no primeiro erro, timeout ou diagnostico.

- Inventariar e testar, conforme o host e o runbook, **ShopVivaliz Remote Control MCP e Orquestrador, Remote Desktop Commander (RDC 1 e RDC 2), desktop bridge/RustDesk, SSH privado/Tailscale, tunel documentado e acesso via laptop como ponte**, bem como controles OCI/GitHub de recuperacao autorizados. Rota de aplicacao nao equivale a canal SSH/MCP; comprovar capacidades reais.
- Nao presumir indisponibilidade: testar identidade, conectividade, autorizacao, escopo da ferramenta, lease/fencing e capacidade da acao. Se uma rota falhar, diagnosticar e corrigir falhas tecnicas permitidas, tentar a proxima rota independente e evitar repetir indefinidamente a mesma tentativa.
- Antes de anunciar `BLOCKED_EXTERNAL`, registrar por rota: data, host, objetivo, teste, resultado verificavel, motivo de exclusao, proxima acao e evidencias. Quando houver proxima acao executavel, continuar em `RUNNING`.
- Em rede/impressoras, identificar equipamento por MAC, modelo, interface fisica/Wi-Fi e porta USB antes de alterar IP. Preservar configuracoes que funcionam; validar conectividade, impressao real e estabilidade apos correcao.
- Nenhuma diretriz autoriza contornar bloqueio explicito de plataforma, MFA, acesso RBAC, aprovacoes, ownership, locks/leases/fencing ou extrair credenciais. Sem permissao, registrar impedimento e usar somente outro caminho legitimamente autorizado.
- Nunca declarar `APTO` apenas por ping, HTTP 200, API ou logs: exigir teste funcional e, quando houver UI, validacao grafica conforme Auditoria Extrema V5.
<!-- /REMOTE_EXHAUSTION_EVIDENCE_V2_20261030 -->

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

<!-- AUDIT_EXTERNAL_REMEDIATION_V1 -->
## Auditoria externa também é corretiva
- Auditoria externa/independente segue o mesmo ciclo de investigação, correção, prevenção e validação.
- Com autorização operacional, o auditor corrige, testa e reaudita; se for read-only, o relatório é intermediário e os achados seguem para executor autorizado até correção e revalidação independente ou bloqueio externo comprovado.
<!-- /AUDIT_EXTERNAL_REMEDIATION_V1 -->

<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto obrigatorio em toda tarefa
Acione o plugin Gepeto junto com Superpowers em toda tarefa e retomada, sem nova mencao do usuario. Leia e cumpra `../../GEPETO-POLICY.md`. Se o runtime nao expuser o plugin, registre `GEPETO_UNAVAILABLE`, informe a limitacao e continue o trabalho autorizado sem simular participacao. Aplicar um plugin nao comprova delegacao nem revisao independente.
<!-- /GEPETO_EVERY_TASK_V1 -->

# Regras para Agentes

## Bootstrap obrigatório de nova sessão

- Antes de qualquer diagnóstico, alteração ou validação, ler `docs/knowledge/host-access.md`.
- Identificar o host correto pelo papel atual; não assumir que IP/nome histórico ainda é produção.
- Usar **Remote Control MCP** como rota operacional primaria para host, servico, diagnostico, arquivos e tarefa duravel quando houver capacidade allowlisted. SSH privado/Tailscale com `shopvivaliz-agent` vem depois, apenas quando a operacao exigir shell direto ou o MCP estiver comprovadamente indisponivel; RustDesk permanece para GUI.
- Confirmar acesso com evidência (`hostname`, `whoami`, diretório e estado Git quando aplicável).
- Nunca versionar, imprimir ou copiar para documentação o conteúdo de chave privada, senha, token ou secret.


<!-- SUPERPOWERS_EVERY_STAGE_V1 -->
## @Superpowers obrigatório em cada etapa

- Todo chat, conversa, sessão, agente e retomada de tarefa relacionada aos projetos ShopVivaliz deve usar **@Superpowers em cada etapa material**, do primeiro diagnóstico à validação final.
- Não considerar uma única invocação no início como suficiente. Reaplicar o workflow/skill apropriado ao mudar de fase: planejamento, investigação, implementação, debugging, TDD/testes, revisão, correção, PR/merge, deploy, pós-deploy e auditoria.
- Em `retome`, `continue` ou `prossiga`, recuperar o último checkpoint comprovado e continuar sob @Superpowers, sem reiniciar desnecessariamente.
- Se @Superpowers não estiver exposto pelo runtime, não simular a chamada: registrar `SUPERPOWERS_UNAVAILABLE` e aplicar a metodologia equivalente até que a capacidade esteja disponível.
- A fonte central desta regra é `REGRAS-AGENTES-CENTRALIZADAS.md`.

## Credenciais, MFA e fontes seguras já provisionadas


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



- Em projetos ShopVivaliz com autenticação já provisionada, o agente deve primeiro usar as fontes seguras existentes (env/arquivo protegido/systemd/secret/host autenticador) e **não pedir ao usuário novamente usuário, senha ou OTP** sem antes provar que a fonte existente está ausente ou inválida.
- Para Amazon Returns / Seller Central, **a antiga rota browser/bridge em `shopvivaliz-free-a1` foi desativada pela exclusao da origem**. Verificar qual novo browser isolado/bridge foi realmente implantado (backend ou Marina) antes de tentar login ou MFA; nao presumir migracao ou reaproveitar sessao/segredo de outro perfil. TOTP somente por fluxo seguro ja autorizado, sem expor valor ou exigir transcricao rotineira.
- Antes de reportar `AUTH_REQUIRED`, verificar o runbook do projeto, referências de credencial e canal de MFA já configurados, sem revelar valores secretos.
- Pedir intervenção humana apenas para credencial realmente revogada/ausente, CAPTCHA, recovery, consentimento novo ou outro desafio que tecnicamente não possa ser resolvido pelo fluxo seguro existente.
- Nunca registrar em Git, docs, logs ou chat o conteúdo de senhas, tokens, chaves, seeds TOTP, OTPs ou cookies; documentar somente a localização segura e o procedimento.

## Navegador e pesquisa técnica

- Para tarefas ShopVivaliz, navegador de agente deve executar na VM de navegação. Não usar Opera Connector nem navegador dos hosts Windows como caminho operacional.
- Para retomada de conversa ChatGPT interrompida, a rota canônica é `always-free-arm-1787907847-26` + `shopvivaliz-chatgpt-continuity.service` + CDP `127.0.0.1:9555`; o instalador Windows é legado/fallback, nunca o padrão operacional.
- A retomada automática explicitamente autorizada pelo usuário deve permanecer habilitada. Investigação de suporte, por si só, não pode desativá-la. Probes sintéticos/repetitivos continuam separados e não devem ser usados como substituto da recuperação real.
- Em programação, infraestrutura, APIs, bibliotecas, frameworks, cloud, segurança e integrações externas, consultar a web quando versão/comportamento atual puder alterar a solução.
- Priorizar documentação oficial, especificações, release notes/changelogs e repositórios oficiais; complementar com issues/fóruns técnicos apenas quando necessário e deixando claro o nível de autoridade da fonte.
- Não assumir flags CLI, endpoints, modelos, parâmetros, limites, deprecações ou comportamento de SDK/API sem verificar quando isso for material à implementação.
- Pesquisa web não substitui validação local: confrontar a fonte externa com versão instalada, código real, testes e runtime.
- Se a web estiver indisponível e a informação atual for necessária, marcar como não verificada em vez de adivinhar.

## Fonte de conhecimento

- Sempre usar `/docs/knowledge/` como base inicial para diagnóstico e operação.
- Confirmar o comportamento no código, workflow, log ou resposta real quando a documentação não for suficiente.
- Nunca assumir uma resposta sem evidência.
- Informar claramente quando a evidência estiver incompleta, ambígua ou desatualizada.

<!-- AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->
## Auditoria corretiva obrigatória

- Todo pedido de auditoria implica **investigar, corrigir, prevenir e validar**; não encerrar apenas relacionando erros.
- Todo achado material corrigível e autorizado deve ter causa raiz investigada, correção aplicada, prevenção pertinente, teste e reauditoria.
- Relatório de achados, recomendação, issue ou plano são estados intermediários enquanto existir ação segura executável.
- APTO/CONCLUIDO só com evidência fresca pós-correção e E2E real quando aplicável; sem falso-verde.
- Pendência só é aceitável após esgotar alternativas seguras e registrar evidência, causa e ação exata necessária para continuidade.
- A coleta inicial é não invasiva; a remediação subsequente deve corrigir o que estiver dentro do escopo autorizado.
- Fonte central: `REGRAS-AGENTES-CENTRALIZADAS.md`, policy `AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1`.
<!-- /AUDIT_REMEDIATE_VALIDATE_GLOBAL_V1 -->

<!-- DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
## Diagnóstico corretivo obrigatório em qualquer tarefa

- Todo diagnóstico, investigação, troubleshooting, health check ou apuração de falha é **etapa de execução**, não estado final: identificar o defeito não conclui a tarefa.
- Ao confirmar um problema material dentro do escopo autorizado, o agente deve seguir o ciclo **reproduzir/confirmar → investigar causa raiz → corrigir → aplicar prevenção pertinente → testar → validar no runtime/E2E quando aplicável → revalidar o diagnóstico**.
- Enquanto existir correção segura e executável, o estado permanece `RUNNING`. Relatório, recomendação, issue, hipótese confirmada, serviço `active`, health verde ou HTTP 200 não autorizam `CONCLUIDO`/`APTO`.
- `CONCLUIDO` exige evidência fresca pós-correção de que o comportamento afetado funciona. Para fluxos de UI, integração, automação, fila, worker, deploy ou continuidade, validar o caminho real ponta a ponta quando tecnicamente aplicável.
- Só é aceitável terminar sem correção como `BLOCKED_EXTERNAL` quando o impedimento for externo, objetivo e comprovado **depois de esgotar alternativas seguras autorizadas**, registrando evidência e a ação exata necessária.
- Esta regra vale para **qualquer tarefa e qualquer agente/subagente/controlador**, inclusive debugging, infraestrutura, navegador, integrações, deploy, auditoria e diagnósticos rotineiros.
- Fonte central: `REGRAS-AGENTES-CENTRALIZADAS.md`, policy `DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1`.
<!-- /DIAGNOSTIC_REMEDIATE_VALIDATE_GLOBAL_V1 -->
## Diagnóstico

- Identificar o erro antes de sugerir a solução.
- Registrar método HTTP, URL, status, corpo da resposta e etapa do fluxo afetada.
- Não tratar 404, 405, 500, CORS e DNS como o mesmo problema.
- Não declarar que produção, deploy, banco, preço, imagem ou integração estão corretos sem teste verificável.

## AUDITORIA_FUNCIONAL_PRODUCAO

- HTTP 200 nao prova funcionamento. Arquivo presente, endpoint existente, secret configurado, schema valido ou pagina renderizada sao apenas evidencias estruturais.
- Auditoria local, lint, unit test e smoke estrutural nunca podem declarar a loja funcional em producao.
- Para declarar producao funcional, executar `scripts/production-functional-audit.sh` contra `https://shopvivaliz.com.br` e exigir `PRODUCTION_FUNCTIONAL_AUDIT=PASS`.
- A auditoria obrigatoria deve usar produto real disponivel e percorrer, no minimo: catalogo, carrinho, cotacao real de frete, checkout, health de pedidos e integracoes criticas.
- Melhor Envio, Olist e Mercado Pago so podem ser considerados saudaveis quando o provider real aceitar a credencial e responder ao probe funcional previsto.
- `configured=true`, token presente ou variavel de ambiente preenchida nao provam autenticacao nem funcionamento.
- qualquer falha critica deve produzir FAIL. Nao converter falha critica em `attention`, `warning`, sucesso parcial ou nota percentual capaz de resultar em status saudavel.
- Se a auditoria funcional nao puder ser executada por falta de credencial, conectividade ou ambiente, o resultado e INCONCLUSIVO/FAIL, nunca PASS.
- Relatorios devem separar explicitamente `STRUCTURAL`, `INTEGRATION`, `FUNCTIONAL` e `TRANSACTIONAL`.
- Nenhum agente, workflow, monitor, Claude, Codex ou automacao pode substituir o gate funcional por verificacao superficial.

## Validação do Squad Chat

Considerar o health válido somente quando todos os requisitos forem atendidos:

- `ok=true`
- `endpoint=squad-chat`
- campo `providers` presente

O campo `configured` indica configuração detectada, mas não prova que a credencial foi aceita pelo provider.

## Credenciais e segurança

- Sempre usar variáveis de ambiente ou GitHub Secrets.
- GitHub Secrets são write-only; nunca tentar recuperá-los em texto.
- Nunca hardcodar, registrar ou exibir senhas, tokens, chaves de API ou dados bancários.
- Não contornar políticas de segurança do navegador, CORS, autenticação ou controles de acesso.
- Não executar deleções destrutivas em FTP ou banco sem autorização explícita e backup.

## Catálogo e integrações

- Não inventar preço, estoque, frete, imagem ou disponibilidade.
- Não alterar campos comerciais em automações de anúncios sem evidência da fonte oficial.
- Ignorar ou sinalizar produtos sem estoque conforme a regra do canal.
- Vincular imagens por identificador confiável, preferencialmente SKU ou ID da origem.
- Distinguir falha de interface de falha de sincronização ou ausência de dados.

## Atualizações

- Produzir atualizações cumulativas para permitir pular versões intermediárias.
- Incluir automaticamente SQLs, migrations e reparos de vínculo necessários.
- Tornar migrations idempotentes e registrar as que foram executadas ou ignoradas.
- Executar preflight, backup, cópia, migrations, reparos e testes na mesma atualização.
- Não exigir abertura manual de links para concluir a instalação.
- Fazer merge apenas quando as alterações estiverem consistentes e validadas.

## Autonomia

Tomar decisões autônomas dentro do escopo autorizado, mas interromper ações destrutivas, irreversíveis ou sem evidência suficiente. Autonomia não substitui validação.

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
- Para operações de host, serviço e diagnóstico, preferir **Remote Control MCP**; depois usar SSH privado/Tailscale e, para bootstrap/recovery, GitHub connector/Actions ou OCI Bastion. Browser permanece na backend. Nenhuma dessas rotas deve consumir Codex por padrão.
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



## GitHub issue comments: single dispatcher

- Exactly one active workflow may subscribe directly to `issue_comment`: `.github/workflows/issue-comment-dispatcher.yml`.
- Workflows that implement comment commands must expose `workflow_call` and be invoked only by the dispatcher. Do not add a second `on: issue_comment` listener.
- The dispatcher must classify each authorized comment into at most one route. Explicit slash commands take precedence over generic mentions such as `@claude`.
- Adding a new comment command requires updating `scripts/issue-comment-router.py`, the dispatcher reusable-workflow route, and `tests/test_issue_comment_router.py`.
- Unrelated or unauthorized comments must produce route `none`; they must not wake command workflows that will only become `skipped`.

<!-- CHATGPT_ATENDIMENTO_CREDENTIAL_V1 -->
## Credencial do ChatGPT — atendimento@shopvivaliz.com.br

- A conta `atendimento@shopvivaliz.com.br` possui senha própria já criada pelo usuário.
- O valor da senha **não deve ser versionado, escrito em docs, logs, issues, comentários, memória de agente ou mensagens**.
- Agentes devem reutilizar primeiro a credencial segura já provisionada no perfil/navegador autenticado da VM `always-free-arm-1787907847-26` e demais fontes seguras autorizadas.
- **Não pedir novamente a senha ao usuário como primeira ação.** Antes de solicitar intervenção humana, comprovar que a credencial segura existente está ausente, revogada ou inválida.
- Se a credencial precisar ser reprovisionada, fazê-lo apenas por canal seguro que não persista o valor em auditoria ou Git.
<!-- /CHATGPT_ATENDIMENTO_CREDENTIAL_V1 -->

<!-- BROWSER_SESSION_ACCOUNT_BINDING_V1 -->
## Vínculo obrigatório entre sessão de navegador e conta

- Fonte canônica: `docs/knowledge/browser-sessions.md`.
- `shopvivaliz-dev-chromium` / CDP `9559` é dedicado a `dev@shopvivaliz.com.br`; `fred`/CDP `9555` é somente compatibilidade temporária para checkpoints pré-migração.
- `shopvivaliz-atendimento-chromium` / CDP `9556` é dedicado a `atendimento@shopvivaliz.com.br`.
- É proibido fazer logout para trocar de conta, autenticar a outra conta no perfil errado ou migrar cookies/storage entre esses perfis.
- Se a sessão correta falhar, reparar/reabrir o mesmo perfil; nunca usar a outra sessão como atalho.
- Preservar os logins existentes e validar perfil/porta antes de qualquer ação de autenticação.
<!-- /BROWSER_SESSION_ACCOUNT_BINDING_V1 -->

<!-- CHATGPT_VM_AUTH_V1 -->
## ChatGPT VM: autenticação local obrigatória

- As contas ChatGPT operacionais são `dev@shopvivaliz.com.br` e `atendimento@shopvivaliz.com.br`. Fontes locais de MFA só podem ser declaradas provisionadas após validação real; referências antigas de `fredmourao` são legado de migração.
- A fonte operacional é a instância OTPClient realmente ativa, identificada por UID e `DISPLAY` do processo e janela correspondente; `fredrdp`/`:99` era apenas o registro histórico. A sessão do OTPClient não precisa coincidir com o display do navegador ChatGPT. Seeds, senhas e códigos nunca podem ser versionados ou impressos.
- O agente deve conseguir preencher senha e OTP por caminho write-only/redigido, sem persistir o segredo em logs, histórico de shell, argumentos de processo ou auditoria.
- Não pedir ao usuário para transcrever senha/OTP enquanto a fonte local autorizada estiver disponível.
- Se faltar capacidade segura de digitação em aplicação desktop, tratar como lacuna do Remote Control MCP a ser corrigida, mantendo a tarefa em andamento até existir rota segura.
- Runbook: `docs/knowledge/chatgpt-vm-auth.md`.
<!-- /CHATGPT_VM_AUTH_V1 -->

## ChatGPT: foreground curto e ownership obrigatório

- Após o recibo/handoff durável, o turno foreground retorna imediatamente. É proibido executar `task_wait`, sleep, espera de CI/deploy ou polling repetido de `task_status` no mesmo turno; o trabalho e sua observação pertencem ao executor durável.
- Browser/continuity/controller são single-writer: qualquer ação mutável exige lease/lock atual e fencing token. Agentes concorrentes podem observar, mas não navegar, clicar, digitar, reiniciar serviço ou promover controller sem ownership válido.
- Enquanto existir lease foreground vivo para a conversa, background é somente leitura. Recovery só assume ownership após release/expiry e revalidação da checkpoint version.
- Nunca declarar recuperação E2E por saúde do bridge, HTTP 200, ACK, tool activity ou mudança genérica da UI. Sucesso exige nova resposta real do assistente na `conversation_id` vinculada.
### Foreground durable handoff lease lifecycle

When `SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=1` and a foreground turn calls `foreground_handoff`, the returned conversation lease is part of the user-turn lifecycle. The foreground agent MUST call `foreground_release` with the exact `task_id`, `lease_id`, and `fencing_token` immediately before returning its user-facing final response. If bounded foreground preparation genuinely approaches lease expiry, it MAY call `foreground_renew`; renewal MUST NOT be used to wait for CI, deploy, durable task completion, sleeps, or background polling. If the client stream disappears before release, the bounded TTL remains the crash/disconnect fallback and recovery may claim ownership only after that lease expires.

<!-- CONTINUITY_AUTH_REMEDIATION_V2 -->
## Gate de autenticacao e continuidade: correcao obrigatoria
- Diagnostico, health check e auditoria de continuidade com bloqueio material exigem remediacao e revalidacao E2E, nao apenas status de servico.
- Validar a identidade e a sessao antes de qualquer login. OTPClient e navegador podem pertencer a usuarios/displays diferentes; verificar em runtime, nao presumir pelo documento.
- Segredos permanecem no contexto protegido de origem: proibido imprimir, ler em saida de ferramentas, transportar por argumentos de comando, copiar entre contas ou registrar em auditorias. A ausencia de ponte protegida e bloqueio de capacidade, nao autorizacao para exfiltrar valores.
- Nunca inventar vinculos de conversas, reexecutar tarefa indeterminada ou criar consumidor duplicado. Recuperacao so e comprovada apos vinculo autentico, dispatcher executado e progresso E2E observado.
- Enquanto nao houver recuperacao comprovada, manter RUNNING ou BLOCKED_EXTERNAL com causa, IDs de auditoria e proxima acao concreta. Pedido direto de prosseguir sempre recebe resposta.
<!-- /CONTINUITY_AUTH_REMEDIATION_V2 -->

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
Para diagnosticos de login/GUI na backend, obrigatorio ler `docs/knowledge/shopvivaliz-graphical-session-recovery.md` e diferenciar `fredconsole/:0` (XFCE/RustDesk), `lightdm/:1` (greeter) e `fredrdp/:99` (Chromium Dev/Atendimento). Se o proprietario mostrar Dev/Atendimento no seletor do ChatGPT, registrar o fato sem declarar autenticacao dos perfis CDP9559/9556: usar somente prova por sessao `/api/auth/session` com email exato. Nao pedir senha Ubuntu como senha ChatGPT, nem redefinir Keyring por tentativa. Incluir runbook nos handoffs de agentes.
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

<!-- VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
## Fechamento automatico impedido sem deploy validado

Antes de marcar `CONCLUIDO` para qualquer mudanca de codigo/configuracao implantavel, pin o SHA **mergeado em main** e execute `python3 scripts/quality/deployment_closure_guard.py --expected-sha <SHA_MAIN>`; sem `ok=true`/exit 0, manter `RUNNING` com proxima acao, reparar CI/pipeline e revalidar. O guard verifica a evidencia oficial do deploy e a revisao publica; nao aceita PR/CI/merge como substituto. Para alteracoes so de docs/testes, usar o classificador oficial `scripts/should-deploy-production.sh`. Independente do chat, `.github/workflows/production-deployment-closure-guard.yml` detecta desvio e mantem issue unico ate correcao. Nunca contornar gates humanos de precos/estoque ou protecoes de branch. Em auditoria de precos apos deploy, comparar **todos os produtos efetivamente publicados**, o preco base e promo API v3, e cada endpoint individual; registrar os que nao puderam ser verificados.
<!-- /VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
