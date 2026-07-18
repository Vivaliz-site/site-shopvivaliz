# Remote AI Access

Ponto único para comandos e browser controlados pelo PC.

## Componente

- Servidor: `scripts/ai-remote-gateway.py`
- Starter: `scripts/start-remote-ai-gateway.ps1`
- Config: `mcp-servers.json`

## Endpoints

- `GET /health`
- `GET /status`
- `GET /mcp/tools`
- `POST /mcp/tool/{name}`
- `POST /exec`
- `POST /file/read`
- `POST /file/write`
- `POST /browser/{action}`

## Auth

- Header: `X-API-Key`
- Valor salvo em: `storage/remote-access/api-key.txt`

## Browser

- `browser_open`
- `browser_click`
- `browser_fill`
- `browser_type`
- `browser_press`
- `browser_eval`
- `browser_text`
- `browser_screenshot`
- `browser_back`
- `browser_forward`
- `browser_reload`
- `browser_close`
- `browser_status`

## Uso

1. Execute `scripts/start-remote-ai-gateway.ps1`.
2. Use o IP que ele imprimir.
3. Envie o header `X-API-Key` em toda chamada.

## Observação

- Se o Tailscale ainda estiver em `NoState`, o gateway continua disponível na rede local.
- Assim que o Tailscale autenticar, o mesmo gateway passa a poder ser consumido pelo IP do tailnet.
