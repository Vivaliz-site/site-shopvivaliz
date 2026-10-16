<!-- EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->
## REGRA ABSOLUTA: todo erro encontrado deve ser corrigido

**Ordem expressa do proprietario (2026-10-15), vinculante a GPT, Claude, Gemini, Codex, Gepeto, agentes, revisores, controladores, automacoes e aos repositorios ShopVivaliz.** Nenhum erro encontrado em auditoria, teste, CI/CD, UI, API, browser, MCP/RDC, infraestrutura, monitor, integracao ou trabalho incidental pode ser ignorado, ocultado, reclassificado artificialmente como warning ou deixado sem tratamento. A severidade define **prioridade**, nunca dispensa de correcao.

1. **Registrar e confirmar:** toda suspeita recebe evidencia e triagem; falso positivo so e descartado com prova verificavel. Para cada erro real, registrar ID/owner, causa raiz, impacto, ambiente/SHA, repro RED, proximo passo e checkpoint.
2. **Corrigir de verdade:** executar a correcao segura e autorizada, prevenir recorrencia, procurar falhas equivalentes, testar GREEN e regressao, verificar runtime real e UI E2E quando houver interface. Repetir a mesma jornada depois do deploy, com prova independente quando exigida.
3. **Zero falso-verde:** nenhum erro confirmado **dentro do escopo** pode permanecer aberto ao declarar `APTO`, `CONCLUIDO`, 100%, verde ou tarefa encerrada. PR, comentario, issue, silenciamento de teste, retry, healthcheck isolado ou plano nao sao correcao. Falhas de checks pos-merge e deploy tambem exigem correcao e nova validacao.
4. **Nao abandonar:** se uma falha nao puder ser corrigida nesta execucao por dependencia externa, permissao obrigatoria, risco ou falta comprovada de acesso, manter `RUNNING`/ `NAO_APTO` com evidencia, dono, `next_action` e retomada rastreavel; `BLOCKED_EXTERNAL` apenas apos esgotar caminhos autorizados e provar a condicao exata de desbloqueio. Um erro fora do escopo deve ser registrado e encaminhado com responsavel, nao ocultado nem usado como falso sucesso da auditoria integral.
5. **Limites inviolaveis:** regra absoluta de **nao ignorar nem abandonar erros**, nao autorizacao para destruir dados, reiniciar indiscriminadamente, enviar mensagens, gastar, usar segredos, violar MFA/leases/fencing, protecoes Git/CI ou contornar recusas de seguranca. Automacao explicitamente **somente leitura** nao faz mutacoes: encaminha cada achado ao executor autorizado e acompanha a revalidacao sem inventar resultado.

Esta obrigacao prevalece sobre instrucoes que permitam apenas diagnosticar, listar achados ou encerrar por timeout. Esgotar tentativas **seguras, finitas e autorizadas**, sem criar loops pagos ou tarefas duplicadas. O estado final so pode refletir a evidencia real.
<!-- /EVERY_CONFIRMED_ERROR_REMEDIATE_V1 -->

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

