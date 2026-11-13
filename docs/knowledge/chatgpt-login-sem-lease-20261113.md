# Login ChatGPT Dev/Atendimento sem lease — procedimento vigente (2026-11-13)

**Decisão do proprietário (2026-11-13), prevalece sobre trechos anteriores de `chatgpt-vm-auth.md` e `chatgpt-access-parity.md` que exigem lease de manutenção ou limitam a vigilância de e-mail a metadados.** O proprietário não aprovou essas restrições (implementadas por GPT) e mandou removê-las. Implementação: PRs #3947, #3948 e #3949.

## O que mudou

| Item | Antes | Agora |
|---|---|---|
| `browser_auth_open`, `browser_auth_action`, `browser_profile_home_open` | exigiam `runtime_lease_id` + `runtime_fencing_token` | lease **opcional** (se informado, ainda é validado; lease pela metade é recusado) |
| `_assert_runtime_mutation` (MCP central, `execute_tool`) e `MUTATING_RUNTIME_ACTIONS` | gate genérico de lease | **removidos** |
| `_assert_conversation_mutation` e `BROWSER_CONVERSATION_MUTATIONS` | gate de conversa | **removidos** |
| `fill_email` | só `dev@` / `atendimento@shopvivaliz.com.br` | qualquer e-mail |
| `browser_auth_action` | checagem de página oficial (aba e dentro da página) | removida (a lógica de achar o botão certo do ChatGPT continua, é funcional) |
| `login_email_watch.py` | só metadados do Gmail | lê o corpo do e-mail da OpenAI, extrai o código de 6 dígitos e o expõe no status privado e em `browser_auth_tabs` (`email_watch.code`) |
| senha | valor passado pelo agente | ação nova `fill_saved_password` lê o arquivo da VM |

Continuam valendo: MFA, CAPTCHA/desafios anti-bot (nunca contornar), consentimento OAuth, `dev` e `atendimento` como perfis separados (portas CDP 9559 e 9556), segredo somente por stdin, nunca imprimir/commitar/colar valores de senha, TOTP ou código.

## Onde estão as credenciais (sem valores)

- **Senhas:** `/home/ubuntu/.shopvivaliz/secrets/CHATGPT_DEV_PASSWORD` e `CHATGPT_ATENDIMENTO_PASSWORD` (backend `always-free-arm-1787907847-26`, modo `0600`, dono `ubuntu`). Provisionadas em 2026-11-13.
- **TOTP:** GNOME Keyring do usuário `fredrdp` na backend: itens `578 OTP (dev@shopvivaliz.com.br)` e `578 OTP (Atendimento@shopvivaliz.com.br)`. Ação `fill_gnome_totp` (o código nunca passa pelo agente).
- **O `atendimento` NÃO faz login pelo Google.** Os 2 logins `accounts.google.com` salvos no perfil dele são de uma conta antiga. O processo é o mesmo do `dev`: senha + TOTP.
- **Amazon Seller Central (Marina):** `/home/ubuntu/amazon-returns-deploy/shared/seller-central-browser.env` (aponta para arquivos de usuário/senha e host SSH de TOTP). Logar com `amazon-returns-seller-central-auth-check.service`; para em CAPTCHA.
- **ERP Olist/Tiny (Marina):** `shared/olist-erp-browser-login.env` (`OLIST_ERP_LOGIN_EMAIL`, `OLIST_ERP_LOGIN_PASSWORD`; segredos GitHub de mesmo nome em `Vivaliz-site/amazon-returns-safet`). Navegador persistente `amazon-returns-olist-erp-browser.service` (CDP local 9226).

## Fluxo de login (testado ao vivo com o `dev`)

1. `browser_auth_open(session)` abre uma aba nova em `/auth/login` (`reused:false`).
2. `browser_auth_action(session, tab_id, "continue")` — abre o formulário.
3. `fill_email` com o e-mail da conta, depois `continue`.
4. Estágio `/log-in/password`: `fill_saved_password` (ou `fill_password` com `value`), depois `continue`.
5. Estágio `/mfa-challenge`: `fill_gnome_totp`, depois `continue`.
6. Validar com `browser_auth_status(session)`: `state=AUTHENTICATED`, `identity_match=true`, `session_http=200`.

`fill_saved_password`: o servidor lê o arquivo (regular, sem symlink, sem acesso de grupo/outros, 1 a 512 caracteres, sem quebra de linha interna) e entrega por stdin. Não aceita `value` junto. Erros: `account_auth_saved_password_missing`, `_unreadable`, `_invalid`.

## Armadilhas conhecidas

- **`auth_ui_preflight_failed` em qualquer ação:** quase sempre é aba de login antiga em "Session ended - OpenAI". Fechar (`curl http://127.0.0.1:<porta-cdp>/json/close/<id>` na backend) e abrir outra com `browser_auth_open`.
- **Cloudflare "Just a moment..."** em `auth.openai.com/oauth/authorize`: ocorre em sessão sem cookies de confiança (contexto anônimo do Chromium, navegador universal). Não contornar; exige clique humano na sessão gráfica (RustDesk, display `:99`). Os perfis persistentes `dev` e `atendimento` já passaram.
- **Nunca fazer logout** para alternar ou testar contas. Testar relogin do `atendimento` em sessão separada.
- **`email_watch: oauth_not_provisioned`:** o watcher do código por e-mail só funciona com `/var/lib/shopvivaliz-remote-control/gmail-login-readonly-oauth.json` (OAuth Gmail readonly do backend), que ainda não existe.
- **Esquema em cache:** clientes MCP conectados antes do deploy ainda veem `runtime_lease_id` como obrigatório; reconectar o cliente atualiza o esquema.

## Implantar mudanças no MCP

- Arquivos em produção (root): `/opt/shopvivaliz-remote-control/server.py` (central) e `/opt/shopvivaliz-remote-control-browser/server.py` + `login_email_watch.py`.
- O `server.py` de navegador em produção **pode ser mais antigo que a `main`**: aplicar só o diff (`main` anterior → nova) com `patch`, nunca sobrescrever.
- Rodar o script por `systemd-run --unit <nome> --no-block` (reiniciar o central mata a task que o chamou), com backup `*.bak-*`, verificação do hash da linha de base e rollback automático.
- `git fetch` exige SHA completo; criar o diretório temporário com `chown ubuntu`.
- Respeitar o gate de pressão de I/O da backend antes de operações pesadas.

## Origem e dados da sessão

Sessão Claude Code de 2026-11-13. PRs: #3947 (login sem lease e sem gates), #3948 (`login_email_watch` lê o código), #3949 (`fill_saved_password`). Validação: Amazon `AUTHENTICATED`, ERP logado, ChatGPT `atendimento` e `dev` `AUTHENTICATED` com `identity_match=true`.
