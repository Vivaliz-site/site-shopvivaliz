# ShopVivaliz Remote Control Browser MCP

Segundo MCP privado derivado do Remote Control MCP canônico. Ele preserva as ferramentas do control plane existente e acrescenta ações gráficas na sessão X11 canônica da backend `always-free-arm-1787907847-26`.

## Endpoint

- loopback: `127.0.0.1:5581/mcp`
- health: `127.0.0.1:5581/health`
- service: `shopvivaliz-remote-control-browser-mcp.service`
- usuário gráfico: `fredconsole` em `DISPLAY=:0`
- autenticação MCP: token protegido em `/var/lib/shopvivaliz-remote-control/service.env`
- alvos RustDesk: configuração opcional protegida em `/var/lib/shopvivaliz-remote-control/desktop.env`; a rota normal do `Fred-Win` não depende dela.

`desktop.env` deve ser `root:root` modo `0600` e conter somente configuração runtime, nunca ser versionado. O mapeamento `SHOPVIVALIZ_RUSTDESK_HOST_IDS` permanece disponível para alvos RustDesk, especialmente `KOCEPSV`; os IDs não são argumentos públicos das ferramentas e não são retornados em health/audit. `Fred-Win` usa normalmente o bridge interativo nativo pelo reverse SSH canônico.

## Ferramentas gráficas de navegador

- `browser_health`
- `browser_tabs`
- `browser_open`
- `browser_navigate`
- `browser_screenshot`
- `browser_click`
- `browser_type`

## Ferramentas de desktop

- `desktop_health(host)` — valida a rota gráfica correspondente ao host.
- `desktop_open(host)` — prepara a rota gráfica: bridge interativo nativo no `Fred-Win`; sessão RustDesk protegida no `KOCEPSV`.
- `desktop_screenshot(host)` — captura a superfície gráfica restrita e retorna PNG.
- `desktop_click(host,x,y,...)` — clique com coordenadas limitadas à superfície retornada por screenshot.
- `desktop_type(host,text,...)` — digitação na superfície ativa; o texto nunca entra em argv nem no audit log.

Somente `Fred-Win` e `KOCEPSV` são aceitos como alvos desktop. Não há parâmetro público para ID RustDesk, display, seletor de janela ou comando arbitrário.

### Fred-Win: bridge interativo nativo

A rota normal do `Fred-Win` usa o reverse SSH canônico do Remote Control MCP e o script versionado `scripts/shopvivaliz-native-desktop-bridge.ps1`. O dispatcher recebe JSON exclusivamente por stdin, grava uma requisição temporária com ACL restrita e executa um worker temporário na sessão do usuário Windows atualmente logado por Scheduled Task `Interactive/Highest`. O worker usa APIs nativas do Windows para `CopyFromScreen`, mouse e teclado/clipboard. Requisição, resposta, screenshot e task temporários são removidos no final. Texto digitado fica apenas no canal protegido e no clipboard transitório, que é limpo.

As coordenadas do `Fred-Win` são relativas ao PNG do desktop virtual retornado por `desktop_screenshot`, inclusive em múltiplos monitores. Essa rota não depende de senha RustDesk, de ID público ou do registro cloud do Remote Desktop Commander.

### KOCEPSV: RustDesk

O `KOCEPSV` continua usando a sessão gráfica X11 do usuário `fredconsole` na backend, com `xdotool`, `xclip`, `xwd` e o cliente RustDesk provisionado. `desktop_screenshot` captura o backing store da janela RustDesk com `xwd -id` e o converte internamente para PNG; isso evita depender do framebuffer raiz, que pode aparecer preto em sessões RustDesk/Flutter sobre display virtual. Cliques são limitados à janela RustDesk resolvida.

## Política de navegador e desktop

O navegador de agente continua na backend; os Windows são apenas endpoints gráficos/administrativos. O wrapper não lê cookies, não analisa perfil do Chrome e não tenta contornar MFA, CAPTCHA, consentimento ou outras proteções. Texto digitado nunca é persistido no audit log; fica somente seu tamanho. URLs auditadas têm query string e fragment removidos.

## Validação

Antes de promover: executar as suítes do Remote Control base e deste wrapper; validar `tools/list`; no `Fred-Win`, provar health/open, screenshot nativo real, digitação e clique inofensivos; no `KOCEPSV`, manter a validação RustDesk; em seguida confirmar `browser_health` e `host_health` para detectar regressão.
