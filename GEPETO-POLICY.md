<!-- AGENT_ACCESS_EXHAUSTION_V2_20261030 -->
## Obrigacao de esgotamento de rotas autorizadas antes de interromper

Para toda tarefa ShopVivaliz acionada por "Retome", "Continue", "Prossiga" ou "nao pare ate concluir", cada agente deve **executar a acao disponivel e continuar**: diagnostico isolado, PR aberto, falha de login, timeout ou uma ferramenta inoperante nao constituem conclusao. Identificar primeiro o host e a topologia vigentes no main; registrar `remote_access_attempts` nao secreto por host/tarefa e consultar as capacidades *realmente expostas*.

**Rotas a investigar e tentar quando aptas ao escopo**, com fallback justificado: (1) ShopVivaliz Remote Control MCP, suas tarefas duraveis, inspecao e navegador grafico; (2) Remote Desktop Commander (RDC 1 e RDC 2, cada um com sessao/permissoes proprias) na Marina, backend, laptop Fred-Win ou KOCEPSV; (3) SSH privado autenticado pela VCN ou Tailscale, usando apenas credenciais ja provisionadas por mecanismo oficial; (4) tuneis privados/reverse SSH ou Cloudflare explicitamente documentados, sem confundir tunel de aplicacao MEI com MCP administrativo; (5) GUI autorizada por perfil/sessao (browser bridge, RustDesk) quando houver interface; (6) OCI Bastion/console/Run Command e GitHub Actions como mecanismos de recuperacao ou deploy **apenas nos respectivos fluxos permitidos**. A indisponibilidade de um conector nao invalida os demais; a existencia de uma rota tambem nao garante permissao para toda acao.

Para cada tentativa aplicavel, documentar horario UTC, host/identidade, canal, teste, permissao efetiva, resultado, causa, correcao, evidencias, proximo fallback e classificacao (`PASS`, `FAILED_REPAIRABLE`, `NOT_PROVISIONED`, `DENIED`, `NOT_APPLICABLE`). Nao registrar segredos, cookies, OTP ou tokens. Recuperar sessao autentica existente sem copiar credenciais entre perfis. Resolver erros reparaveis, testar novamente de modo finito/idempotente, seguir para a proxima rota autorizada e preservar estado `RUNNING/NAO_APTO` enquanto houver trabalho executavel. **So declarar `BLOCKED_EXTERNAL`** quando houver prova objetiva de impedimento externo apos esgotar e justificar todas as rotas aplicaveis; registrar proprietario do bloqueio e acao segura seguinte.

**Limites superiores inalterados:** recusas explicitas da plataforma, RBAC, sudo, MFA, CAPTCHA, leases/fencing, branch protection, aprovacao humana para estoque/precos, quotas e controles de gasto nao podem ser burlados usando outro MCP, RDC, SSH, tunnel, navegador, identidade, workflow ou host. A VM `shopvivaliz-free-a1` foi retirada e nunca pode ser alvo. Marina e producao e backend e controlador; confirmar ao vivo. Para correcoes de codigo: RED, causa raiz, GREEN, CI protegido, merge autorizado, deploy por Master Pipeline, SHA publico exato e E2E GUI com trace/persistencia. Sem isso nao marcar `CONCLUIDO/APTO`. Esta regra reforca, nao revoga, `docs/knowledge/host-access.md`, `GEPETO-POLICY.md` e as politicas vigentes.
<!-- /AGENT_ACCESS_EXHAUSTION_V2_20261030 -->

<!-- ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->
## REGRA ABSOLUTA: todo erro identificado exige correcao real e validacao

Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, bloqueio tecnico, resultado incorreto ou problema de validacao detectado **nao pode ser ignorado, apenas documentado ou encerrado sem remediacao**.

Para cada erro, executar e evidenciar: (1) RED/reproducao observavel e impacto, com prioridade e proprietario; (2) investigacao da causa raiz e de falhas semelhantes; (3) correcao efetiva minima, segura e autorizada com prevencao de recorrencia; (4) GREEN, regressao, verificacao da revisao implantada e E2E na UI real, com trace/midia, persistencia e revisao independente quando aplicavel; (5) registro do resultado e somente entao fechamento individual. Um erro descoberto na tentativa de reparo integra a mesma obrigacao; nenhuma contagem de falhas pode cair por expiracao de janela ou descarte de checkpoints.

**Continuidade obrigatoria:** se a reparacao estiver bloqueada por recusa explicita da plataforma, ownership/lease/fencing ausente, gate de I/O, autorizacao especifica pendente, restricao de seguranca ou terceiro, manter o erro PENDENTE e a tarefa `RUNNING/NAO_APTO` com evidencias, responsavel, impedimento concreto e `next_action` seguro; continuar a retomada pelo fluxo ja existente, sem criar duplicata. Nunca declarar `APTO`, `CONCLUIDO` ou sucesso com erro aplicavel em aberto, diagnostico, ACK, HTTP 200, PR, CI verde ou teste isolado.

**Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicitos e gate de I/O. Se a tarefa/automacao for `SOMENTE LEITURA`, **nao executar mutacoes**: registrar e encaminhar cada defeito ao mecanismo de reparo autorizado ja existente. Proibido contornar recusas por conectores, shell ou agentes alternativos ou duplicar agentes, monitorias, automacoes, tarefas, bindings e PRs.
<!-- /ABSOLUTE_REMEDIATE_EVERY_ERROR_V1 -->

<!-- GEPETO_EVERY_TASK_V1 -->
## Gepeto e Superpowers obrigatorios em toda tarefa

- Por determinacao do proprietario, toda tarefa, conversa, sessao e retomada deve acionar o plugin Gepeto junto com Superpowers, sem exigir nova mencao do usuario. A regra vale globalmente e em todos os repositorios de Vivaliz-site e fredmourao-ai.
- No inicio da tarefa, localizar e ler a skill de instrucoes do Gepeto no catalogo do runtime (plugin gpt-4d1b51dc23427fe5106bbd896a5b7a0e; skill instructions), usando suas ferramentas pertinentes. Reutilizar a leitura na mesma sessao enquanto estiver atual; nao repetir chamadas sem necessidade.
- Aplicar Gepeto na execucao, revisao critica e validacao com evidencias; selecionar a disciplina Superpowers adequada a cada etapa material, inclusive retome/continue/prossiga.
- Um plugin fornece instrucoes e ferramentas ao agente atual; nao implica outro agente autonomo, fila recebida, atividade em segundo plano ou revisao independente. So declarar delegacao/revisao externa quando houver execucao e resposta comprovadas.
- Se a capacidade nao estiver exposta, registrar GEPETO_UNAVAILABLE (ou SUPERPOWERS_UNAVAILABLE), informar a limitacao e continuar o trabalho autorizado com as regras disponiveis. Nunca simular invocacao, resposta, consenso ou aprovacao.
- Ao delegar uma tarefa autorizada, transmitir esta regra e o contexto minimo necessario. Gepeto nao substitui OpenAI, Claude ou Gemini nem conta como quarto provider do Buscador.
- Esta regra nao instala nem habilita plugins automaticamente em outra conta/conversa e nao amplia permissoes, escopo, custo ou autorizacao. Preservar secrets, gates, revisoes independentes exigidas, limites de execucao e proibicao de IA paga em loops/rotinas permanentes.
<!-- /GEPETO_EVERY_TASK_V1 -->

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
Preservar a cota do Codex. Para continuidade de tarefas, priorizar rotas determinísticas/controle remoto auditável e executores alternativos autenticados adequados ao trabalho; usar Codex somente como último recurso finito. Falta de cota, rate limit ou indisponibilidade do Codex mantém a tarefa `RUNNING` e nunca constitui, isoladamente, `BLOCKED_EXTERNAL`.
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

<!-- VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
## Entrega real exige release comprovada

Agentes de codigo nao podem dar tarefa por concluida no PR, testes ou merge. Antes de resposta de sucesso, verificar o SHA mergeado em main com `scripts/quality/deployment_closure_guard.py --expected-sha <SHA_MAIN>`, exigir evidencia oficial completa, mesma versao na resposta publica, smoke, E2E e persistencia no ambiente. Se falhar, corrigir a causa e repetir deploy pelo pipeline autorizado; manter tarefa RUNNING sem alegar CONCLUIDO. A verificacao independente automatica e o issue de deriva nao fazem o deploy pelo agente. Nunca usar este procedimento para burlar label de aprovacao humana, CI, branch protection, ownership, RBAC ou leases.
<!-- /VERIFIED_PRODUCTION_CLOSURE_GUARD_V2 -->
