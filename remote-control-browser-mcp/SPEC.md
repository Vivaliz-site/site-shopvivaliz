# ShopVivaliz Remote Control Browser MCP

Segundo MCP privado derivado do Remote Control MCP canônico. Ele preserva as ferramentas do control plane existente e acrescenta ações gráficas na sessão X11 canônica da backend `always-free-arm-1787907847-26`.

## Endpoint

- loopback: `127.0.0.1:5581/mcp`
- health: `127.0.0.1:5581/health`
- service: `shopvivaliz-remote-control-browser-mcp.service`
- usuário gráfico: `fredconsole` em `DISPLAY=:0`
- autenticação MCP: token protegido em `/var/lib/shopvivaliz-remote-control/service.env`
- alvos RustDesk: configuração opcional protegida em `/var/lib/shopvivaliz-remote-control/desktop.env`

`desktop.env` deve ser `root:root` modo `0600` e conter somente configuração runtime, nunca ser versionado. O mapeamento `SHOPVIVALIZ_RUSTDESK_HOST_IDS` associa os nomes canônicos `Fred-Win` e/ou `KOCEPSV` aos IDs RustDesk; `SHOPVIVALIZ_RUSTDESK_HOST_PASSWORDS` guarda apenas as senhas unattended geradas no backend. IDs e senhas nunca são argumentos públicos nem são retornados em health/audit.

## Ferramentas gráficas de navegador

- `browser_health`
- `browser_tabs`
- `browser_open`
- `browser_navigate`
- `browser_screenshot`
- `browser_click`
- `browser_type`

## Ferramentas de desktop RustDesk

- `desktop_health(host)` — valida dependências, display, RustDesk, unicidade da janela e apenas a presença booleana da credencial unattended.
- `desktop_unattended_bootstrap(host)` — gera a senha no backend, configura o RustDesk do Windows por transporte administrativo e a persiste somente em `desktop.env`; a senha nunca entra nos argumentos MCP nem na resposta.
- `desktop_open(host)` — abre ou foca a sessão RustDesk configurada para o host canônico.
- `desktop_screenshot(host)` — captura somente a janela RustDesk resolvida e retorna PNG.
- `desktop_click(host,x,y,...)` — clique com coordenadas relativas e limitadas à janela RustDesk.
- `desktop_type(host,text,...)` — digitação na janela RustDesk; texto não entra em argv nem audit e o clipboard transitório é limpo após a colagem.

Somente `Fred-Win` e `KOCEPSV` são aceitos como alvos desktop. Não há parâmetro público para ID RustDesk, display, seletor de janela ou comando arbitrário. Janela ausente ou ambígua falha fechado.

## Política de navegador e desktop

A automação usa a sessão gráfica X11 do usuário `fredconsole` com `xdotool`, `xclip`, `scrot`, `xwd` e o cliente RustDesk provisionado. `desktop_screenshot` captura o backing store da janela RustDesk com `xwd -id` e o converte internamente para PNG; isso evita depender do framebuffer raiz, que pode aparecer totalmente preto em sessões RustDesk/Flutter sobre display virtual. A captura continua restrita à janela resolvida do host. Quando existe senha unattended protegida, `desktop_open` a transfere somente pelo stdin do clipboard X11, cola na janela RustDesk recém-aberta, limpa imediatamente o clipboard e nunca inclui a senha no argv do cliente controlador. O bootstrap envia a senha ao PowerShell remoto por stdin; a chamada local suportada pelo próprio RustDesk para gravar a senha permanente no Windows pode expô-la transitoriamente apenas ao processo local do host, nunca ao MCP, audit ou resposta. Navegador de agente continua na backend; o Windows é apenas endpoint gráfico remoto. Não usa CDP/DevTools neste wrapper, não lê cookies, não analisa perfil do Chrome e não tenta contornar MFA, CAPTCHA, consentimento ou outras proteções.

Texto digitado nunca é persistido em audit log; ficam somente tamanho e SHA-256. URLs auditadas têm query string e fragment removidos. Cliques de browser permanecem limitados à janela ativa do browser, e cliques desktop ficam limitados à janela RustDesk do host solicitado.

## Validação

Antes de promover: executar as suítes do Remote Control base e deste wrapper; validar `tools/list`; provar `desktop_health`, `desktop_open`, screenshot real e uma interação inofensiva no KOCEPSV; em seguida confirmar `browser_health` e `host_health(KOCEPSV)` para detectar regressão.
