# Auditoria do merge dos arquivos de instrucao dos agentes (2026-11-05)

Regra do dono: `AGENTS.md`, `CLAUDE.md`, `GEMINI.md` e `docs/AGENTS.md` sao identicos; conteudo e unido, e **restricao ou bloqueio ausente em qualquer um dos quatro e removido de todos**. Ficaram somente as restricoes presentes nos quatro (5 linhas, incluindo a regra do dono contra restricoes sem aprovacao).

Deteccao: linha a linha, fora de blocos de codigo, por marcadores de proibicao ou bloqueio imperativos (`✗`, `NAO USE`, `Nunca <verbo>`, `proibido`, `Somente o certifier`, `fail-closed`, `nao contornar` etc.). E uma heuristica: use esta lista para revisar.

Os textos abaixo estao truncados em 200 caracteres. O conteudo completo esta no historico do Git (commit anterior a este).


## Removidas a partir de `AGENTS.md` (58 linhas)

- **Conduta diante de bloqueios:** diagnosticar a causa e a diferenca em relacao ao estado anterior, procurar rotas tecnicas independentes **ja autorizadas** e corrigir paralelamente outros defeitos; na
- somente depois dos status checks oficiais no SHA sintetico da integracao,
- > **Gate de exposicao:** a decisao de transporte nao e concessao de acesso. Antes de publicar DNS/ingress, confirmar tunnel ID/account e configuracao existente pelo painel/API oficial; criar **Access 
- **Proibido enfraquecer seguranca para acelerar:** MFA, recusas 401/403, RBAC, branch protections, grants, lock/lease/fencing, isolamento do daemon, protecoes financeiras e segredo permanecem vigentes.
- **Execucao eficiente:** um checklist duravel por tarefa material (nao por subcomando), sem aprovacoes ou preflights duplicados para o mesmo escopo; selecionar suites especializadas por diff/dependenci
- A conta GitHub shopvivaliz-human deve poder visualizar todas as PRs pelo acesso nativo ao repositorio. **Nao adicionar shopvivaliz-human como CODEOWNER global, revisor padrao, nem solicitar revisao hu
- **PR com aprovacao formal do proprietario:** abrir como **Ready for review (draft=false)**, nunca aguardar em Draft; solicitar imediatamente revisao de `shopvivaliz-human` pelo GitHub e confirmar `req
- Aplicar `docs/knowledge/agent-rules.md#regra-permanente-gates-somente-por-impacto-comprovado-da-pr`: **somente disparar gates especializados com relacao tecnica entre arquivos/dependencias alterados e
- Aplicar `docs/knowledge/security-approval-via-pr.md`: **toda autorizacao de seguranca da ShopVivaliz exige PR Ready for review (draft=false) criada por outra identidade (agente/operador), com diff, mi
- ## Auditoria V5: proibido encerrar no diagnostico
- Esta regra vale para **todos os agentes, auditorias, automacoes, repositorios, servicos, ambientes e fluxos ShopVivaliz**, inclusive durante a propria recuperacao: erro, falha, regressao, timeout, blo
- **Sem bypass:** esta obrigacao nao amplia permissoes. Respeitar protecoes de branch/producao, controles de seguranca, segredos, MFA, gastos, backups, CAS, ownership, leases/fencing, bloqueios explicit
- **Ordem explicita do proprietario (2026-10-13), vinculante a GPT, Claude, Gemini, Codex, subagentes, orquestradores e revisores:** toda auditoria extrema/completa/V5/aptidao de sistema que tenha UI **
- 4. **Gate fail-closed, sem falso APTO:** qualquer fluxo com UI nao percorrido e comprovado permanece `NAO_VALIDADO_UI` / `NAO_APTO`; e proibido declarar `APTO`, `CONCLUIDO`, 100% ou verde. `browser.re
- Para qualquer executor, subagente ou ferramenta neste projeto: carregue `AGENTS.md` e a documentação aplicável do `main`; persista prova `rules_loaded` (SHA/ref/data). `Retome` significa **executar ag
- - O probe jamais concede permissao para pedir senha: nova intervencao humana
- somente apos evidencia independente de credencial ausente/revogada/invalida,
- - Nunca copiar senha do iPhone ou navegador para terminal, Git, chat, logs,
- somente leitura e retorna em chamada inline, sem `task_submit`, `Monitor`,
- `ABSENT`, e continua proibido pedir novamente a senha sem prova.
- a transferencia automatica, jamais via CDP/shell/clipboard/ponte
- Em `Retome`, `Continue`, `Prossiga`, `Siga` ou qualquer trabalho remoto, **um erro em um unico canal jamais comprova inacessibilidade do host**. Antes de `BLOCKED_EXTERNAL` ou de solicitar ao usuario 
- Limites: nao contornar MFA, CAPTCHA, RBAC, protecoes de branch, limites administrativos ou aprovacoes; nao procurar/extrair secrets nem tentar senhas indiscriminadamente; nao habilitar SSH publico ou 
- Antes da PRIMEIRA ferramenta que altera estado e sempre que receber `Retome`, `Continue`, `Prossiga` ou `Siga`, todo agente deve LER EFETIVAMENTE, na revisao `main` atual, `AGENTS.md`, `docs/knowledge
- **PROIBIDO declarar `BLOCKED_EXTERNAL`, `IMPEDIDO`, `INCONCLUSIVO` como encerramento ou pedir ao usuario que resolva falha tecnica enquanto existir qualquer acao segura, autorizada e tecnicamente viav
- 5. Somente apos comprovado esgotamento de **todas as alternativas razoaveis e autorizadas aplicaveis**, registrar bloqueio objetivo com checkpoint recuperavel, dependencia externa especifica e passo e
- 4. Nao encerrar por diagnostico, plano, PR, merge, timeout ou erro transitorio; executar testes, checks, integracao/deploy pertinentes e validacao funcional/E2E antes de `CONCLUIDO`.
- 6. Nunca burlar politicas de seguranca, revisao, CI, branch protection, MFA ou aprovacao necessaria. Diante de bloqueio externo real, documentar evidencias, alternativas tentadas e passo de desbloquei
- - Se houver bloqueio externo objetivo ou aprovacao obrigatoria pendente, registrar causa, evidencias, tentativas, checkpoint e acao exata para retomar; nao contornar protecoes de branch, autenticacao,
- > 🔐 **SESSÕES CHATGPT FIXAS:** leia `docs/knowledge/browser-sessions.md`. Na VM backend, `shopvivaliz-dev-chromium`/CDP 9559 pertence a `dev@shopvivaliz.com.br` e `shopvivaliz-atendimento-chromium`/CD
- - **Origem web `shopvivaliz-free-a1` aposentada/excluida conforme proprietario em 2026-10-29**; `10.0.1.112:22` sem rota. Proibido acionar, publicar ou recriar como fallback. Confirmacao `TERMINATED` 
- ## ⛔ REGRAS OBRIGATÓRIAS (Resumo Executivo)
- > É proibido encerrar a tarefa em qualquer estado intermediário, inclusive: commit apenas local, branch apenas enviada, PR aberta/draft, PR verde aguardando merge ou alteração local não commitada.
- - É proibido usá-los em daemon, cron/timer periódico, watcher, autorepair, polling ou retry/loop sem limite.
- - Workflows GitHub pagos devem exigir gatilho explícito/restrito; eventos genéricos e `schedule` não podem acionar Claude/GPT/Codex automaticamente.
- ### 1. NUNCA Use `git reset --hard` em Produção
- **Proibido SEMPRE:**
- ### 6. NUNCA Declare "Sucesso" Sem Evidência
- **Proibido:**
- **NUNCA comitte:**
- 1. **NUNCA editar dentro:**
- 2. A bridge continua limitada à sua API e nunca deve:
- 4. Nunca publicar token, cookie, chave, OTP/TOTP, conteúdo de secret ou credencial de sessão.
- - Métricas não podem registrar mensagem bruta, token, senha ou dado pessoal.
- - Se qualquer check, teste, lint, gate, conflito ou Action falhar: investigar a causa raiz, corrigir, revalidar e repetir ate ficar verde. E proibido contornar falha com bypass, mascaramento de exit c
- Para operações OCI, use somente a identidade dedicada `AGENTS`; nunca use como fallback o perfil pessoal `DEFAULT`. O procedimento e os caminhos por host estão em [`docs/operations/oci-agent-access.md
- - DESKTOP-KOCEPSV acessa OCI pelo wrapper SSH verificado para VM1; a chave privada OCI não deve ser duplicada nesse host.
- - É proibido versionar, imprimir ou transportar em logs/prompts chaves `*.pem`, security tokens, cookies de sessão ou headers de autenticação.
- - A resposta final de conclusao e um gate operacional: nao pode ser enviada enquanto houver qualquer etapa executavel necessaria ao pedido original.
- Antes de qualquer operacao em terminal/CLI, leia e cumpra a secao `Isolamento obrigatorio de sessao CLI por chat` de `AI-TO-CLI-PROTOCOL.md`. Cada chat deve usar sessao/namespace CLI exclusivo; reutil
- - Nenhum daemon/cron/watch pode consumir Codex automaticamente: a excecao historica de 2026-10-01 foi REVOGADA em 2026-10-11 por FREE_PROVIDER_FALLBACK_V2. Codex e Claude sao apenas ultimas opcoes int
- - Cron, controller, worker e bridges nao podem promover fallback pago por
- - **Nao contornar** esta regra usando outro transporte ou outro agente. Nao matar/cancelar jobs, reiniciar Chromium/containers/VM, limpar volumes ou worktrees sujos, fazer `swapoff` ou aumentar custo 
- Para toda alteracao implantavel de codigo, configuracao, servico ou integracao, **nao encerre a tarefa em PR aberto, CI verde ou merge**. Registre o SHA de `main` apos o merge e execute `python3 scrip
- O workflow `.github/workflows/production-deployment-closure-guard.yml` verifica novamente a producao apos o pipeline e quando acionado manualmente, e abre/atualiza um unico issue de deriva de deploy a
- **Nenhum agente pode encerrar uma tarefa de alteração implantável em PR, checks, merge, publicação acionada ou HTTP 200.** Para afirmar CONCLUIDO, provar a revisão em produção com: PR integrado; CI ob
- **Sem bypass:** o guard de aprovação humana `human-approved-price-stock` e demais proteções de CI/branch são independentes e obrigatórios. Agentes não podem criar/atribuir o rótulo de aprovação humana
- Um workflow disparado mas não concluído, skipped, com relatório não gerado ou release SHA diferente é **NÃO VALIDADO**, jamais CONCLUIDO. Não substituir evidência real por texto do agente.

## Removidas a partir de `CLAUDE.md` (3 linhas)

- Toda tarefa longa, delegacao, investigacao material ou mutacao deve executar `scripts/agent_task_state.py start` antes da primeira etapa material. Apos cada etapa, executar `scripts/agent_task_state.p
- Leia primeiro `AGENTS.md`. Para auditoria/aptidão, cumpra `AUDIT_ABSOLUTE_GATE_V1.md`, `AUDIT_BROWSER_E2E_REAL_V1.md`, `AUDIT_AUTH_CREDENTIAL_DISCOVERY_V1.md`, `AUDIT_PROJECT_REQUIREMENTS_V1.md` e o m
- Leia `docs/quality/AUDIT_MERGE_ENFORCEMENT_V1.md`; preserve o governance bridge e o Absolute Audit Main Guard. Somente o certifier absoluto autoriza APTO.

## Removidas a partir de `GEMINI.md` (1 linhas)

- Toda tarefa longa, delegacao, investigacao material ou mutacao deve executar `scripts/agent_task_state.py start` antes da primeira etapa material. Apos cada etapa, executar `scripts/agent_task_state.p

## Removidas a partir de `docs/AGENTS.md` (35 linhas)

- > **Estado operacional 2026-10-29:** site/producao web/deploy = `marina-a1-4c24g` (OCI ESPOSA); backend/controller/browser/MEI/M365 = `always-free-arm-1787907847-26` (OCI PRINCIPAL); `shopvivaliz-free
- **Por quê importa:** o protocolo v5 exige evidência de runtime parity e mutação real via UI contra o release publicado para emitir `APTO`; um agente sem acesso às VMs/produção **não pode fechar essas 
- - Nunca apontar workflow, script, cron, systemd, SSH, MCP, health-check ou deploy para IP/hostname das E2 aposentadas.
- 3. **Não remova entradas** antigas a não ser que estejam **confirmadas obsoletas**
- ## 🚫 Regras Obrigatórias para Agentes
- - ✗ **Nunca** manter Claude, GPT/OpenAI ou Codex pago em daemon, loop, cron/timer, watcher, supervisor, autorepair ou polling recorrente.
- - ✗ `while true`, retry ilimitado, relançamento automático e gatilho GitHub amplo consumindo IA paga são proibidos.
- - ✗ **Nunca** declarar que produção/deploy/banco/preço/imagem estão certos **sem teste verificável**
- - ✗ **Nunca** hardcodar, registrar ou exibir senhas, tokens, chaves de API
- - ✗ **Nunca** contornar CORS, autenticação, controles de acesso
- - ✗ **Nunca** deletar em FTP ou banco sem backup + autorização explícita
- - ✗ **Nunca** inventar preço, estoque, frete, imagem, disponibilidade
- - ✗ **Nunca** alterar campos comerciais sem evidência da fonte oficial
- - ✗ **Nunca** exija clique manual pra concluir instalação
- - ✗ Não encerrar em commit local, branch enviada, PR aberta/draft ou PR verde ainda sem merge.
- Essa autorização não permite force-push, bypass de branch protection, exposição de secrets, cobranças reais ou exclusões destrutivas fora do escopo. O agente ainda deve confirmar evidências independen
- ### 2026-09-01 — HTTP 204 do Tiny/Olist v3 nao pode acionar fallback que repete escrita
- **O que descobri:** o endpoint oficial `PUT /public-api/v3/produtos/{idProduto}` responde `204 No Content` em sucesso. O helper `svtop_tiny_request()` tratava resposta sem JSON ou HTTP nao-2xx como fa
- `hash(IP+User-Agent)` (não depende de cookie) e falha fechado sem armazenamento confiável. Assinatura
- - ✗ **Nunca enviar** `pagamento.meioPagamento` — Tiny rejeita mesmo com ID válido
- - ✗ **Nunca** usar `GET /estoque/{id}?token=legacy_v2_token`
- - ✗ **Nunca** redirecionar `POST` (quebra webhooks)
- - ✗ **Nunca** mostrar pré-venda/sob-encomenda (remover do catálogo)
- - ✗ **Não é** cron `git-auto-sync.py` a cada 30min como versões antigas deste doc diziam
- - ✗ **FTP/HostGator desativado** — só via `workflow_dispatch` manual
- - `api/orchestrator/status.php` tinha o mesmo padrão de auth fail-open já visto em R5-2 (Melhor Envio): se `CRON_SECRET` não estivesse definido, a checagem inteira virava `false` e o endpoint ficava p
- - `api/blog/comment.php::bc_rate_limit()`: as duas saídas de erro (diretório/arquivo de rate-limit não abrível) devolviam `true` (permite) — fail-open num limitador cuja falha vira burla. Cada comentá
- - Corrigido o dispatcher para portar os 6 efeitos que faltavam desde o refactor de 09/08 (R9-2): push pro Tiny/Olist (`svtop_push_order_tiny`), espelho de status na tabela MySQL `orders`, e-mail de co
- **Ver também:** `docs/AGENTS.md` (entrada da Rodada 9, acima, com o achado original R9-1/R9-2), relatório de reconciliação entregue ao Fred no chat (não versionado — dados de pagamento não devem ir pr
- explorável (falha fechado), mas é código morto. Decisão pendente do Fred: restaurar
- ### 2026-08-27 — Policy Engine bloqueia merge de mudança server-side não-visual (Sonnet)
- - Quando isso acontecer: **não** tente contornar o Policy Engine (é fail-closed de
- - Proibido reintroduzir Tiny v2, token estático antigo ou arrays/caches locais como fonte de pedido/NF.
- - A resposta final de conclusao e um gate operacional: nao pode ser enviada enquanto houver qualquer etapa executavel necessaria ao pedido original.
- - **Nunca deixar arquivo de debug temporário em produção:** durante o

---
Total de linhas de restricao removidas: 97.
