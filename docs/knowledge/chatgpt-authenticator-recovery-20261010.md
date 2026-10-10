# Recuperacao de autenticacao ChatGPT na VM — 2026-10-10

## Escopo e proveniencia

Registro **sem segredos** da recuperacao de autenticacao das contas corporativas `dev@shopvivaliz.com.br` e `atendimento@shopvivaliz.com.br`. Consulte primeiro `AGENTS.md`, `docs/knowledge/atendimento-chatgpt-bootstrap.md`, `docs/knowledge/host-access.md`, `docs/knowledge/agent-rules.md`, `docs/knowledge/chatgpt-vm-auth.md` e `docs/knowledge/browser-sessions.md` no `main` atual. Este registro nao substitui esses runbooks.

O usuario informou e mostrou no GNOME Authenticator **dois registros separados**, rotulados Dev e Atendimento. A interface estava acessivel pela sessao remota RustDesk no backend `always-free-arm-1787907847-26`, usuario `fredrdp`, display observado `:99`. A existencia dos registros nao comprova que cada seed esta aceito no fluxo MFA da respectiva conta.

## Evidencia operacional observada em 2026-10-10

| Item | Observacao | Limite |
|---|---|---|
| GNOME Authenticator | Executando; dois registros mostrados pelo usuario | Nao validar ou mostrar seeds/codigos |
| OTPClient RustDesk | Processo/servico ativo, cofre distinto | Nao confundir com TOTP do ChatGPT |
| GNOME Keyring `login` | `Locked=true` via D-Bus do `fredrdp` | Nunca contornar criptografia |
| Chrome Dev | Servico ativo, CDP 9559 responde; varias abas oficiais de login | Nao autenticado na VM |
| Chrome Atendimento | Servico ativo, CDP 9556 responde; abas oficiais na etapa senha | Nao autenticado na VM |
| Chromium Atendimento | Uma entrada de senha para `auth.openai.com`, confirmada somente por contagem SQLite | Nao prova descriptografia ou aceitacao |
| Chromium Dev | Nenhuma entrada na localizacao examinada | Nao prova ausencia em outras fontes autorizadas |
| Controlador | `continuity_ready=false`; checkpoints sem vinculo comprovado | Nao forcar reconciliacao sem receipt |
| Laptop do usuario | Captura mostrou conta Dev autenticada com MFA habilitado | Nao transferir cookies, sessao ou credenciais para a VM |

## Procedimento autorizado

1. Descobrir ao vivo usuario e display dos processos, identificar o Chrome Dev/Atendimento pelas portas dedicadas e confirmar o estado do Runtime Lock. Nunca supor que o display historico permanece o mesmo.
2. Preservar `user-data-dir`, perfis, cookies e sessao legitima existente; nao alternar contas dentro do mesmo perfil.
3. Desbloquear o GNOME Keyring apenas pelo fluxo oficial do proprietario, ou por mecanismo de desbloqueio automatico *previamente aprovado, seguro e provisionado*. Nao redefinir o cofre para contornar a senha.
4. Verificar a fonte legitima de senha de cada conta por metadados antes de tentar login. Nenhuma senha deve entrar em Git, logs, argumentos de processo ou chat.
5. Prosseguir somente nos dominios oficiais do ChatGPT/OpenAI, usando `browser_auth_action` com lease **especifico da conta** e entrada protegida para senha/codigo. Nao usar perfil da outra conta, nem misturar identidades.
6. Se houver etapa MFA, utilizar o autenticador correto e o metodo oficial. Se o provedor solicitar confirmacao pelo proprietario, aguardar a acao legitima; nao contornar verificacao.
7. Validar o resultado em cada perfil separadamente por identidade de sessao autenticada, nunca por tela de login preenchida ou mera existencia de TOTP.
8. Documentar apenas: conta, etapa, resultado, identificador de auditoria nao secreto, rota e bloqueio. Marcar `AUTH_REQUIRED`/ `VAULT_LOCKED` se aplicavel, sem falso positivo.
9. Validar persistencia depois de reinicio controlado **somente quando autorizado**: processos, CDP, mesmos perfis, sessao, chaveiro, MFA oficial e recuperacao. Um teste de disponibilidade do Chrome sozinho nao comprova login persistente.

## Prevencao e riscos

- Manter dois perfis Chrome independentes e serviços supervisionados, com monitoramento da disponibilidade e de `AUTH_FLOW`/estado autenticado.
- Não prometer login permanente: a plataforma pode requerer reautenticacao legitima, MFA, CAPTCHA ou aprovacoes.
- A chave TOTP foi compartilhada anteriormente em uma conversa; **rotacionar a chave pelo procedimento oficial da conta correspondente** e substituir o registro antigo, validando o novo metodo antes de remover qualquer recuperacao anterior.
- Nunca armazenar nem registrar codigo atual, chave TOTP, QR, senha, token, cookies ou chave do cofre neste arquivo.
- A proxima dependencia externa para concluir o login na VM e o desbloqueio legitimo do chaveiro e/ou disponibilizacao da credencial correspondente por uma fonte protegida. Os dois logins **nao estavam concluidos** na ultima verificacao.

## Estado desta tarefa

`AUTH_FLOW / VAULT_LOCKED` — **nao concluida**. Os dois registros visiveis no autenticador nao equivalem a duas sessoes ChatGPT autenticadas na VM.
