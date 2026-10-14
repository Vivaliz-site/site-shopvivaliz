# Sessao grafica ShopVivaliz x autenticacao ChatGPT — diagnostico de 2026-10-11

## Escopo, fonte de verdade e evidencias

Host backend: `always-free-arm-1787907847-26`. A captura do proprietario em RustDesk mostrou uma sessao XFCE de `fredconsole`, com Chromium aberto em `chatgpt.com`, workspace Vivaliz e **Dev/Atendimento visiveis no seletor de contas**. Isso **nao equivale**, isoladamente, a atestar duas sessoes autenticadas simultaneamente; o seletor pode exibir contas previamente cadastradas.

A VM possui tres superficies distintas, que os agentes **nao podem confundir**:

| Superficie | Usuario/display | Proposito | Evidencia |
|---|---|---|---|
| Desktop RustDesk/LightDM/XFCE | `fredconsole`, `:0`, sessao logind `c1` | GUI visivel ao proprietario | `loginctl` comprovou GUI XFCE em execucao, inclusive apos periodo estavel |
| Tela de login LightDM | `lightdm`, `:1`, sessao `c2` | Greeter do Ubuntu, nao login do ChatGPT | LightDM foi observado ativo; **nao** solicitar a senha ChatGPT no greeter |
| Chromium corporativo Dev | `fredrdp`, display virtual `:99`, CDP `9559` | Perfil dedicado `dev@shopvivaliz.com.br` | Deve ser validado pela identidade exata em `/api/auth/session` |
| Chromium corporativo Atendimento | `fredrdp`, display virtual `:99`, CDP `9556` | Perfil dedicado `atendimento@shopvivaliz.com.br` | Deve ser validado pela identidade exata em `/api/auth/session` |

O RustDesk mostrar o greeter e pedir senha local **nao significa** que a VM, o Chromium ou os navegadores isolados estejam offline. Em 2026-10-11, `passwd -S` (somente metadados; nenhum hash/senha lido) retornou `fredconsole=L` (senha bloqueada) e `fredrdp=P` (senha configurada). Nunca tentar adivinhar, publicar, desbloquear ou redefinir credenciais sem procedimento seguro e autorizacao aplicavel. Preservar GNOME Keyring e credenciais protegidas.

## Rotina obrigatoria de diagnostico para agentes

1. Ler `AGENTS.md`, `docs/knowledge/host-access.md`, `docs/knowledge/browser-sessions.md` e este documento em `main`; registrar `rules_loaded` com SHA/ref.
2. Primeiro inspecionar `controller_status`, `browser_auth_tabs(session=dev|atendimento)`, `loginctl list-sessions`, `loginctl show-seat seat0 -p ActiveSession`, `loginctl show-session c1/c2`, `systemctl is-active rustdesk shopvivaliz-virtual-display` e sockets X `:0/:99`. Apenas leitura e sem mostrar secrets.
3. Distinguir **desktop grafico recuperado**, **contas visiveis no seletor** e **autenticacao E2E em cada perfil**. Somente `/api/auth/session` por conta, comparando o email exato esperado e sem exibir token/cookie, prova autenticação.
4. Se o RustDesk estiver no greeter `:1` e houver sessao `fredconsole` `c1` ja aberta em `:0`, pode-se tentar `loginctl activate c1` por rota MCP autorizada, sem senha nem alteracao permanente. Verificar a estabilidade por tempo suficiente e o que o RustDesk efetivamente mostra. Se retornar a `c2`, investigar `light-locker`, LightDM e processo RustDesk, **sem prometer sucesso apos apenas um ACK do comando**.
5. Em 2026-10-11, tentativas de `activate c1`, `xset s reset`, `light-locker-command --poke` e inibicao **temporaria** voltaram ao greeter em ~10-11 s. Em posterior leitura, `c1` permaneceu ativa por 34 segundos (audit `9056c859-e3fa-40b2-9675-02fd37b02b05`), confirmado por screenshot do proprietario. Nenhuma senha, servico persistente, configuracao de bloqueio ou cookie foi alterado.
6. GUI em `:0` nao e a mesma coisa que CDP9556/9559 de `:99`. **Nao misturar perfis, nao copiar cookies, nao trocar contas num perfil dedicado, nao registrar OTP/senhas/seeds.** Para obter GUI de `:99`, priorizar capacidade aprovada pelo MCP; qualquer bridge local de visualizacao precisa controle de acesso, teste de interface loopback e limpeza, sem expor VNC sem autenticacao fora do loopback.
7. Checkpoint de E2E deve continuar `RUNNING` enquanto CDP9556/9559 nao retornarem o email correto. A consulta read-only em 2026-10-11 retornou `http=200`, mas `user_present=false` em ambos (audit `7cb5866d-e84d-46f7-bc64-ebf7e3c5f258`). Essa constatacao nao contradiz o screenshot do Chromium da sessao `:0`.
8. O encaminhamento de e-mails de ambas as contas corporativas (`dev@shopvivaliz.com.br` e `atendimento@shopvivaliz.com.br`) converge para a caixa Gmail `fredmourao@gmail.com`, confirmado pelo proprietario e pelo conector Gmail neste chat. O recebimento das mensagens nao autentica nenhum perfil Chromium. O conector Gmail do chat nao equivale a OAuth proprio do backend; nao inventar essa permissao nem ler mensagens sem autorizacao da conta correspondente.
9. **Preferencia atualizada em 2026-10-12:** login por senha existente e TOTP GNOME na etapa MFA oficial, com **codigo adicional por e-mail autorizado quando a OpenAI o exigir** em `/email-verification`. Tambem e permitido selecionar `Try another way` / `Tentar outro metodo` quando oferecido pela propria interface, sem pressupor que elimine o desafio atual, sem desabilitar MFA, trocar a identidade corporativa ou migrar cookies. Uma tentativa anterior no Dev voltou para `/email-verification`. Respeitar lease account-specific e gates de seguranca do provedor e da ferramenta: a plataforma bloqueou a transferencia automatica do OTP do Gmail ao Dev, portanto nao tentar realiza-la por CDP, shell, clipboard, proxy, VNC ou outro conector. Se a ferramenta autorizada nao conseguir preencher, somente o proprietario pode informar o codigo **diretamente na pagina oficial**, sem transcrever segredo no chat. Nao repetir pedido de senha ja provisionada nem usar o TOTP como substituto de codigo por e-mail.
10. Nao considerar recuperacao de checkpoints historicos concluida sem atestacao individual de `task_id + conversation_id + browser_session + fingerprint` e progresso real `PROGRESS_CONFIRMED`. Preservar fila e monitor horario ja existente; nao criar agente duplicado.

## Procedimento de comunicacao interagentes

Cada executor (ChatGPT, Claude, Gemini, Gepeto e agentes de repositorios correlatos) deve consultar este runbook antes de diagnosticar GUI/login ShopVivaliz. Atualizar issues de origem e checkpoint canônico com evidencias nao secretas. **A captura de Dev/Atendimento no seletor e um fato observado, nao prova para substituir a autenticacao individual.** Acessos normais por MCP continuam utilizaveis mesmo com greeter LightDM exigindo senha Linux.

Auditorias da sessao grafica: `5f1441a7-3825-45b7-842e-49cb1ab7441f` (logind), `90094810-507a-489f-8ee9-8243059a5fc8` (troca inicialmente confirmada), `17ec9fa0-df33-4974-bc1c-470def20497b` (retorno em ~10 s), `9056c859-e3fa-40b2-9675-02fd37b02b05` (estabilidade), `7cb5866d-e84d-46f7-bc64-ebf7e3c5f258` (identidade dos perfis CDP).
