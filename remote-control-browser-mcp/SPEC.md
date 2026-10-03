# ShopVivaliz Remote Control Browser MCP

Segundo MCP privado, derivado do Remote Control MCP canônico. Ele preserva todas as ferramentas do control plane existente e acrescenta ações gráficas de navegador somente na backend `always-free-arm-1787907847-26`.

## Endpoint

- loopback: `127.0.0.1:5581/mcp`
- health: `127.0.0.1:5581/health`
- service: `shopvivaliz-remote-control-browser-mcp.service`
- autenticação: reutiliza o token protegido do Remote Control MCP em `/var/lib/shopvivaliz-remote-control/service.env`

## Ferramentas adicionais

- browser_health
- `browser_tabs`
- `browser_open`
- `browser_navigate`
- `browser_screenshot`
- `browser_click`
- `browser_type`

Todas as ferramentas originais continuam disponíveis por delegação ao servidor canônico.

## Política de navegador

A automação usa somente a sessão gráfica X11 do usuário `fredrdp` com `xdotool`, `xclip` e captura de tela. Não usa CDP/DevTools, não lê cookies, não analisa o perfil do Chrome e não tenta contornar MFA, CAPTCHA, consentimento ou outras proteções.

`browser_type` nunca persiste o texto digitado no audit log; grava somente tamanho e SHA-256. URLs auditadas têm query string e fragment removidos.

`browser_click` rejeita coordenadas fora da janela ativa do navegador.

## Validação

O endpoint HTTP de health e a tool browser_health devem confirmar dependências GUI e ao menos uma janela de navegador visível; o endpoint HTTP continua exigindo identidade correta do serviço. A validação funcional deve ainda provar `tools/list`, screenshot real e uma navegação segura na sessão autenticada.
