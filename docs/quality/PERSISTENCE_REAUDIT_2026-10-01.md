# Reauditoria de persistencia — 2026-10-01

Escopo: controller de continuidade, contrato de checkpoint, worker ChatGPT e evidencia de deploy. Esta nota nao certifica o storefront nem declara APTO global.

## Defeito reproduzido e prevencao

Um executor detached concluiu um checkpoint enquanto a rodada em primeiro plano ainda preparava sua alteracao. A conclusao textual precedeu merge/deploy e o worker executava bytes antigos. O teste negativo reproduziu a escrita atrasada antes da correcao.

A [PR #2529](https://github.com/Vivaliz-site/site-shopvivaliz/pull/2529), merge `25da915935ab4e17b473fcf3e5101e57f06499da` em 22:45:53 UTC, exige ownership atual quando o dispatcher fornece o snapshot de history. Provas opcionais fixadas no inicio usam argv limitado e somente leitura; schema 2 impede clientes antigos de ignorarem esse contrato. A falha da prova final devolve RUNNING e proxima acao de reparo. Tarefas legadas sem provas continuam exigindo verificacao independente; status CONCLUIDO sozinho nao comprova resultado.

O worker informa sent somente quando houve envio efetivo. Recuperacao passiva e erro de envio nao contam como mensagem enviada.

## Evidencia confirmada

- Suite Node completa e 169 testes Python de continuidade/background passaram na rodada local; 71 testes focados passaram apos o ultimo ajuste. CI continuity, validation-gate e Repository Governance da PR passaram.
- Negativo em runtime: ready sem manifesto de hashes foi rejeitado e manteve RUNNING. Cliente antigo rejeitou checkpoint schema 2. A suite cobre prova perdida entre ready e complete, retorno automatico para recuperacao e sucesso apos reparo.
- Controller ativo em 22:49:26 UTC executava `/opt/shopvivaliz-gemini-24x7-controller/releases/25da915935ab4e17b473fcf3e5101e57f06499da/scripts/gemini_24x7_controller.py`.
- Worker reiniciado em 22:50:29 UTC, SHA256 `c2b21cdb44b4b8612b60909b9aa809d001e5437ea2f86c1108a85b08cbb70a29`, identico ao arquivo integrado. Installer supervisionado encerrou com exit 0.
- Manifesto protegido fixa quatro hashes: worker, agent_task_state, task_resume_dispatcher e controller. Todos passaram sha256sum --check. Caminho operacional: `/home/ubuntu/audit-preservation/persistence-proof-221221.sha256`.
- Bridge autenticado respondeu OK, sem pendencias, claims ou entradas invalidas no instante observado. Isso comprova saude da fila, nao retomada da conversa.
- Diagnostico somente leitura confirmou sessao autenticada, 24 candidatos e dois projetos; HTTP 429 exige backoff. Nenhum token, titulo, conteudo ou ID de conversa foi registrado.

## Limites e pendencias

ESC-2026-002 permanece OPEN. Checkpoints de probes nao tem identidade explicita de conversa; a recusa AMBIGUOUS_CONVERSATION_TARGET nao pode ser convertida em prova de retomada. A recuperacao detached e a retomada da mesma conversa precisam de evidencias distintas.

Probe observe-only `continuity-e2e-proof-20261001-2252` foi iniciado em 22:50 UTC, com timeout 480 s e sem chamar watchdog/dispatcher diretamente. Relatorio operacional: `/home/ubuntu/audit-preservation/persistence-proof-e2e-221221.json`. O relatorio final em 22:55:46 UTC confirmou PASS, observed_request=true, executor_exit_code=0, provider=gemini, final_status=CONCLUIDO e final_verification=continuity_e2e_pass. O ledger registrou recuperacao terminal em 22:55:42 UTC, sem invocar diretamente watchdog/dispatcher.

Houve drift concorrente antes de 22:50:29 UTC: worker antigo voltou a executar apos a primeira instalacao. O deploy foi reconciliado; quatro hashes foram reconferidos apos o PASS detached em 22:56 UTC, sem drift nesse intervalo. Essa observacao nao prova estabilidade indefinida ou retomada da conversa na interface. Nenhuma certificacao anterior foi reativada. Conclusoes textuais indevidas foram preservadas como evidencia, sem reescrever seu historico.

## Contraditorio adicional: identidade da conversa

O journal do worker implantado registrou PROGRESS_CONFIRMED para silent_stall em 22:53:01 UTC (latencia 22882 ms) e para um nudge em 22:53:25 UTC. Esses logs sao evidencia positiva de atividade; a auditoria nao os converteu em certificacao global.

Um teste negativo adicional reproduziu que assistantProgressed aceitava count/text maior com conversationFingerprint diferente. A confirmacao anterior nao vinculava o snapshot a rota da conversa; um alvo de navegador compartilhado podia mudar durante o periodo de confirmacao.

A prevencao adiciona fingerprint SHA256 da rota no snapshot e remove a rota bruta do objeto retornado. Progresso de assistant, surface, baseline apos envio e polling recusam identidade diferente, ausente ou rota home/login. Reattach/retry compara com o alvo inicial e aborta antes de continuar quando a mudanca e observada; input, click de envio e fallback Enter conferem o alvo fixado. Adapters de teste legados sem metadados mantem seu contrato; snapshots reais sempre incluem identidade.

Regressao: outra conversa com conteudo maior nao confirma progresso; mesma conversa continua confirmando; perda de identidade nao confirma; home nao confirma; troca durante reattach retorna ERROR e sent=false; alvo diferente recusa antes do input; expressao real de identidade e exercitada com rota ficticia. Suite Node completa PASS em execucao supervisionada. ESC-2026-002 continua OPEN ate prova E2E correlacionada da conversa real e cobertura equivalente completa; os checks de identidade nao sao promessa de ausencia de toda concorrencia possivel no navegador.
