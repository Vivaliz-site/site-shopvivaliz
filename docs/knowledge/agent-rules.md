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

- Em projetos ShopVivaliz com autenticação já provisionada, o agente deve primeiro usar as fontes seguras existentes (env/arquivo protegido/systemd/secret/host autenticador) e **não pedir ao usuário novamente usuário, senha ou OTP** sem antes provar que a fonte existente está ausente ou inválida.
- Para Amazon Returns / Seller Central, o browser/bridge roda em `shopvivaliz-free-a1` e o TOTP é fornecido de forma restrita por `always-free-arm-1787907847-26`. O agente deve usar esse caminho automático e nunca depender de o usuário transcrever OTP rotineiramente.
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
- Nenhum daemon/cron/watch deve consumir Codex automaticamente por padrão. Exceção explicitamente autorizada em 2026-10-01: o controlador Gemini 24/7 pode executar exatamente um fallback finito `codex-auto` por fingerprint elegível, somente depois de Gemini não produzir progresso, com lease/deduplicação/cooldown do dispatcher e `SHOPVIVALIZ_BACKGROUND_CODEX_FALLBACK=1`; Codex continua sendo a última opção e nunca transforma ACK/exit code em conclusão.
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
- A fonte operacional é o OTPClient da sessão `fredrdp` em `DISPLAY=:99`; seeds, senhas e códigos nunca podem ser versionados ou impressos.
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
