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

## Atualizacao operacional — 2026-10-11 (retomada apos preflight de credenciais)

Este checkpoint registra apenas evidencias nao secretas. Nenhuma conta foi
considerada autenticada sem verificacao do e-mail exato em
`/api/auth/session`; nenhuma senha, OTP, cookie ou seed foi extraido.

- Backend `always-free-arm-1787907847-26`: acessivel por
  ShopVivaliz Remote Control, com servicos Chromium Dev e Atendimento ativos.
- Atendimento/CDP9556: preflight oficial
  `scripts/chatgpt_credential_preflight.py --session atendimento`
  retornou `saved_entry=PRESENT`, `DENY_EXISTING_CREDENTIAL` e
  `password_or_token_accessed=false`. A entrada esta no subperfil
  `Profile 1`; `Local State` registra `last_used=Profile 1`, mas isso nao
  confirma sozinho qual subperfil hospeda cada aba. Nenhuma credencial nova
  deve ser solicitada ou duplicada.
- Uma acao protegida e unitaria
  `browser_auth_action(action=continue,session=atendimento)` retornou
  clique executado, com lease de manutencao exclusivo liberado ao final.
  A pagina permaneceu em `/log-in/password`; um segundo envio sem nova
  evidencia de preenchimento nativo nao e indicado. Nenhum erro visivel de
  senha incorreta ou CAPTCHA foi identificado pela classificacao sanitizada;
  a interface ainda solicita senha.
- Dev/CDP9559: `saved_entry=NOT_VERIFIED` (ausencia NAO comprovada).
  Uma aba `/mfa-challenge` tem campo de codigo; outras abas antigas
  `/email-verification` nao expuseram campos/botoes ativos. Uma unica
  tentativa protegida de `resend` falhou no preflight da propria pagina
  (`auth_ui_preflight_failed`) e nenhuma nova mensagem apareceu na
  busca Gmail correspondente. Nao insistir em abas expiradas.
- O endereco Gmail conectado recebe mensagens dirigidas aos alias
  corporativos; remetentes observados tinham dominio `tm.openai.com` ou
  `tm1.openai.com`. Essa descoberta NAO prova que alguma aba de
  verificacao antiga esteja apta a receber codigo nem autoriza ignorar MFA.
- O MCP implantado suporta `fill_gnome_totp`,
  `back_to_methods`, `continue_google` e outras acoes protegidas, mas o
  schema do conector disponivel nesta conversa ainda anuncia somente
  `fill_email`, `fill_password`, `fill_code`, `continue`, `resend`.
  Uma tentativa de `back_to_methods` foi rejeitada **pela validacao do
  schema do cliente**, sem execucao no backend. Atualizar a exposicao de
  ferramentas pelo procedimento oficial; nao contornar esse controle por
  comandos locais.
- O probe de sessao `/api/auth/session` retornou `UNKNOWN` para ambos
  os perfis, sem prova de login; o monitor de continuidade Dev permaneceu
  em `AUTH_TERMINAL`. Um status desconhecido nao deve virar
  `AUTHENTICATED`.
- Verificacao de acesso a GNOME Keyring via comando direto foi recusada
  pelo controle de seguranca do ambiente; nenhuma alternativa para burlar
  esse controle foi utilizada. O ultimo estado de desbloqueio conhecido
  nao substitui verificacao atual.

### Proxima etapa segura

1. Preservar os perfis CDP9556/CDP9559 e as sessoes remotas; nao reiniciar
   ou trocar subperfis por suposicao.
2. Para Atendimento, utilizar **somente o autofill nativo autorizado**
   do proprio Chromium ou a interface protegida do gerenciador existente,
   sem ler/exportar senha; se exigir escolha/desbloqueio pelo proprietario,
   realizar pelo fluxo oficial. Nao repetir `continue` em formulario vazio.
3. Para Dev, liberar pelo mecanismo oficial a ferramenta ja instalada de
   MFA GNOME para o conector autorizado, com lease especifico, ou concluir
   a verificacao na UI oficial; nao copiar/expor TOTP e nao reutilizar
   OTPClient do RustDesk.
4. Depois de cada login, validar o e-mail exato na sessao
   `/api/auth/session`, incluindo persistencia sob o mesmo perfil.
5. Se alguma etapa humana for obrigatoria, solicitar **a interacao na UI**,
   e nao a retransmissao de uma senha que ja esta salva.

**Situacao deste checkpoint:** `AUTH_REQUIRED` para as duas contas.
`CREDENTIAL_REPROMPT_GATE_V1` permanece obrigatorio. Nao declarar
login concluido com base em metadados ou clique de botao.
