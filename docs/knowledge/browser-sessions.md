# Sessões de navegador e vínculo de contas

Esta é a fonte canônica para o vínculo entre perfis de navegador da VM backend e contas ChatGPT.

## Vínculos fixos

| Perfil / sessão | Porta CDP | Conta permitida |
|---|---:|---|
| `/home/fredrdp/.config/shopvivaliz-atendimento-chromium` | `9556` | `atendimento@shopvivaliz.com.br` |
| `/home/fredrdp/.config/shopvivaliz-dev-chromium` | `9559` | `dev@shopvivaliz.com.br` |

## Roteamento de e-mail e identidade de navegador

Ambos os enderecos corporativos Dev e Atendimento tem e-mails entregues
na caixa Gmail `fredmourao` (confirmacao do proprietario em 2026-10-11).
Isso **nao** muda o vinculo de identidade dos navegadores, nao constitui
autenticacao ChatGPT e nao concede acesso OAuth Gmail ao MCP.
E vedado transportar cookies ou qualquer segredo entre os perfis.

## Regras obrigatórias

- Nunca fazer logout de uma conta para entrar com a outra.
- Nunca trocar a conta autenticada dentro de um perfil já dedicado.
- Nunca reutilizar cookies, storage, perfil ou porta CDP de uma conta para a outra.
- Para tarefa destinada a `atendimento@shopvivaliz.com.br`, usar exclusivamente `shopvivaliz-atendimento-chromium` / porta `9556`.
- Não criar novos bindings para a conta pessoal antiga. `fred`/CDP 9555 existe apenas para concluir checkpoints explicitamente vinculados antes da migração.
- Para tarefa destinada a dev@shopvivaliz.com.br, o navegador CANONICO de continuidade continua shopvivaliz-dev-chromium / CDP 9559. Apenas o MCP Dev dedicado porta 5583 pode usar a excecao de transporte autenticado CDP9557 documentada abaixo, quando aprovada explicitamente pelo proprietario e verificada a identidade Dev exata por chamada real. Isso nao autentica o 9559, nao altera o guardian e nao copia cookies, contas ou perfis.
- Se a sessão correta estiver indisponível, reparar ou reabrir o perfil correspondente; não usar a outra sessão como atalho.
- Preservar login e cookies existentes. Reinício de navegador só é permitido preservando o mesmo `user-data-dir`.
- Antes de qualquer autenticação, validar qual perfil/porta está sendo controlado.
- Nunca registrar senha, OTP/TOTP, cookie ou token. Este documento registra apenas identidade de conta e isolamento de sessão.

## Evidência operacional

Em 2026-10-04, o backend `always-free-arm-1787907847-26` apresentava processos Chromium distintos para:
- `shopvivaliz-chromium` com `--remote-debugging-port=9555`;
- `shopvivaliz-atendimento-chromium` com `--remote-debugging-port=9556`.

A conta de cada perfil deve permanecer conforme a tabela acima.

O perfil `shopvivaliz-dev-chromium` / CDP 9559 foi reservado em 2026-10-05 para a migração curada `fredmourao -> dev`. Sua existência documental não prova login ativo; autenticação deve ser validada ao vivo antes de retirar a conta pessoal do workspace.


## Roteamento da retomada por checkpoint

O worker continua unico; nao criar um segundo consumidor da fila nem trocar
`CHATGPT_CONTINUITY_CDP_URL` globalmente para atender uma conta diferente.
Checkpoints corporativos devem fixar a conversa e depois a sessao:

```sh
python3 scripts/agent_task_state.py bind-conversation --task <id> --conversation-id <conversa-confirmada>
python3 scripts/agent_task_state.py bind-browser-session --task <id> --browser-session atendimento
```

`browser_session=atendimento` seleciona somente CDP9556; `dev` seleciona
somente CDP9559. `fred`/CDP9555 é aceito apenas como compatibilidade de checkpoints pré-migração. Checkpoints novos devem declarar a sessão explicitamente.
O binding e imutavel, nao conta como progresso e e herdado pelo sucessor junto
com a conversa. Nunca substituir implicitamente a conta/conversa antiga.

Antes de reload ou envio, o worker confirma a identidade da sessao e a rota
exata dentro da propria aba, retornando apenas booleano, sem credenciais.
Stream ativo ou nao confirmado e deferido mesmo se o DOM nao mostrar Stop.
Conta divergente, binding invalido, conversa divergente ou checkpoint terminal
falham sem usar outro perfil como fallback. O monitor padrão acompanha Dev/CDP9559. Atendimento permanece checkpoint-driven em CDP9556.
Sessão inválida falha fechado e nunca usa outra conta como fallback.

A escolha de porta usa contexto assincrono por tentativa, sem alterar variaveis
de ambiente globais. Testes exercitam o endpoint realmente solicitado, a
expressao de identidade injetada, concorrencia, heranca e falha fechada.
Fonte de implementacao: Node.js `AsyncLocalStorage.run` (documentacao oficial:
https://nodejs.org/api/async_context.html#asynclocalstoragerunstore-callback-args).

## Guardian da conta Dev — verificação de identidade

O guardian do CDP 9559 só deve sinalizar AUTHENTICATED quando a resposta real
de /api/auth/session contiver user.email=dev@shopvivaliz.com.br (comparação
normalizada). Account ID, presença de token, campo editável ou menu de perfil
**não** comprovam a identidade da conta. E-mail diferente retorna
IDENTITY_MISMATCH, que gera saúde degradada e nunca autoriza envio.
E-mail ausente ou erro de probe mantém UNKNOWN (falha fechada).
A verificação de identidade no worker, antes de qualquer ação na conversa
vinculada, permanece obrigatória e independente do guardian.

A recuperação de autenticação não permite usar cookies ou a sessão da conta
Atendimento como substituto. O guardian não faz login nem altera perfis.


## Página inicial sem login — confirmação não invasiva

O guardian da sessão Dev também detecta LOGGED_OUT na raiz do ChatGPT
quando o próprio DOM exibe simultaneamente campo de e-mail,
ações Log in/Sign up e ausência de compositor ativo. Apenas uma UI
parcial/carregando ou resposta de sessão sem identidade continua UNKNOWN.
Isso reduz probes repetidos no perfil sem criar/fechar abas, navegar,
alterar login ou buscar tokens. A autenticação de verdade continua
dependendo de /api/auth/session com o e-mail Dev exato.


## Boot sem sessao XRDP — display X11 persistente

Os tres perfis de navegador podem usar o mesmo **servidor grafico local**
`DISPLAY=:99` sem compartilhar cookies, perfis Chromium, contas ChatGPT
ou autorizacoes de terceiros. Nao confundir o cookie Xauthority (acesso local
ao display) com cookies de autenticacao web.

O servico `shopvivaliz-virtual-display.service` executa `Xvfb :99` como
`fredrdp`, somente no socket Unix local, com `-nolisten tcp` e
autenticacao `-auth /home/fredrdp/.Xauthority`. Sua rotina
`shopvivaliz-virtual-display-guard.sh` cria apenas o registro de
MIT-MAGIC-COOKIE para `:99` quando ausente; a chave entra no `xauth` por
stdin, jamais no argv/log/auditoria. Quando um display `:99` ja
existe, o supervisor **nao** o substitui: adota somente quando o Xauthority
atual comprova acesso e monitora sua disponibilidade. Se esse display
desaparecer e liberar o socket, o supervisor assume `:99` com Xvfb
proprio. Socket ainda ocupado, mas sem identidade/autorizacao verificavel,
permanece bloqueado, sem alterar perfis nem cookies.
Antes de liberar navegadores, confirma o socket e autorizacao X11 real.

As unidades `shopvivaliz-dev-browser.service` (9559),
`shopvivaliz-atendimento-browser.service` (9556) e
`shopvivaliz-chatgpt-browser.service` (9555 legado) possuem
`Requires/After=shopvivaliz-virtual-display.service`; nao dependem de
um login interativo XRDP para criar o socket `/tmp/.X11-unix/X99`.
A unidade grafica e instalada antes dos navegadores pelo instalador
canonico `scripts/install-chatgpt-continuity-backend-bridge.sh`.

Falha de inicio do Xvfb e degradacao de transporte sao distintas de
`AUTH_FLOW` e de `AUTHENTICATED`: nao usar a presenca de X99 como prova
de login. Verificar CDP 9559 e `/api/auth/session` com o e-mail Dev real.
A UI do OTPClient historicamente usa `fredconsole` em `DISPLAY=:0` e
permanece isolada deste display.

<!-- SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->
**Evidencia de 2026-10-11:** o proprietario mostrou no RustDesk uma sessao XFCE `fredconsole/:0` com Chromium ChatGPT e contas Dev/Atendimento no seletor. Esse Chromium nao deve ser confundido com os perfis corporativos dedicados `fredrdp/:99`, CDP 9559/9556. O seletor nao e comprovacao de identidade autenticada nas duas sessoes; verificar separadamente `/api/auth/session`. Consultar `docs/knowledge/shopvivaliz-graphical-session-recovery.md` antes de agir.
<!-- /SHOPVIVALIZ_GRAPHICAL_SESSION_RECOVERY_V1 -->

## Excecao de roteamento Dev MCP para Chromium auxiliar autenticado - 2026-10-12

O proprietario autorizou aproveitar o navegador fisicamente existente
e JA autenticado, em vez de copiar cookies ou repetir MFA.

- Chromium auxiliar independente: fredrdp, DISPLAY=:0, porta CDP9557,
  servico shopvivaliz-atendimento-mcp-browser.service, diretorio exclusivo
  /home/fredrdp/.config/shopvivaliz-atendimento-mcp-chromium. Apesar do
  nome historico do servico, a API oficial /api/auth/session retornou
  EXACTAMENTE dev@shopvivaliz.com.br (audit f15a9d6e-e3f7-47ab-9fa0-e3eb4f65d845).
- Atendimento continua EXCLUSIVAMENTE no perfil dedicado CDP9556, sem
  qualquer mudanca. Dev canônico CDP9559 permanece sem autenticacao.
- A excecao atua apenas no transporte do MCP Dev na porta5583.
  O processo Chromium 9557 e seus cookies permanecem no mesmo diretorio,
  sem copia, exportacao ou reinicio. O navegador 9559 e os componentes
  guardian/continuity de checkpoint nao sao remapeados.
- Habilitar somente pela rotina versionada
  scripts/adopt-verified-dev-aux-browser.sh, com a autorizacao explicita
  SHOPVIVALIZ_AUTHORIZE_DEV_AUX_ADOPTION=1. O script obtém lease exclusivo
  de manutencao, valida a identidade real de Dev no 9557 e Atendimento no
  9556, e altera apenas a configuracao do MCP Dev:
  SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9557
  SHOPVIVALIZ_DEV_ADOPTED_CDP=9557
- O adapter exige simultaneamente MCP port5583, session=dev, port=9557
  e opt-in explicito. Toda operacao na aba verifica novamente
  /api/auth/session.user.email exato; ao perder autenticacao ou mudar de
  conta o MCP falha fechado. Mantem as validacoes normais de lease,
  fencing, conversa/checkpoint e mutacoes.
- Validacao E2E somente se GET localhost:5583/health retorna
  session=dev, account_authenticated=true,
  account_identity_verified=true e cdp_reachable=true.
  Nao confundir com PROGRESS_CONFIRMED ou readiness do controlador.
- Rollback sob lease: remover apenas o drop-in
  90-verified-dev-aux.conf em
  /etc/systemd/system/shopvivaliz-browser-dev-mcp.service.d/,
  daemon-reload e restart somente de shopvivaliz-browser-dev-mcp.service.
  Nenhum browser Chrome, perfil, sessao do usuario ou token deve ser tocado.
