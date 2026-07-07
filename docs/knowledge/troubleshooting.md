# Troubleshooting

## HTTP Errors
- 405 → método HTTP incorreto
- 404 → caminho inexistente
- 500 → erro interno PHP
- Load failed → CORS ou bloqueio
- DNS error → domínio ou DNS

## Tiny API
- 403 de qualquer origem → API v2 bloqueada pelo Cloudflare globalmente. Usar v3 com Bearer token
- "invalid_grant" / "Token is not active" → refresh_token expirado. Requer novo login OAuth no portal Tiny
- Endpoint v3: https://api.tiny.com.br/public-api/v3/produtos
- Token endpoint: https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token

## Deploy
- [skip ci] no commit → bloqueia TODOS os workflows incluindo deploy
- paths-ignore no deploy.yml → mudanças em .github/** não disparam deploy automático
- Worktree principal (branch main): c:\Users\FRED\site-shopvivaliz-autodev
- Worktree dev: c:\Users\FRED\site-shopvivaliz (branch codex/*)

## Preços
- Todos zerados no fallback-products.json → "Preço sob consulta"
- Para ativar: definir OLIST_REFRESH_TOKEN + OLIST_CLIENT_ID + OLIST_CLIENT_SECRET nos GitHub Secrets

## WhatsApp
- Placeholder 5511999999999 → definir LOJA_WHATSAPP nos GitHub Secrets (formato: 55DDXXXXXXXXX)
