# Deploy

## Pipeline
push to `main` → GitHub Actions `deploy.yml` → FTP via SamKirkland/FTP-Deploy-Action@v4.3.5 → HostGator → dev.shopvivaliz.com.br

## Excludes FTP
- `**/uploads/olist/**`
- `**/reports/**`
- `**/logs/**`
- `**/.env.*`
- `**/claude/medusa/**`

## .env no servidor
Gerado no deploy a partir dos GitHub Secrets via heredoc no deploy.yml.
Inclui: DB_HOST, DB_USER, DB_PASS, DB_NAME, OLIST_ACCESS_TOKEN, OLIST_REFRESH_TOKEN, OLIST_CLIENT_ID, OLIST_CLIENT_SECRET, LOJA_WHATSAPP, etc.

## paths-ignore no deploy.yml
`automation/**`, `**/*.md`, `.github/**`, `.claude/**`, `.codex/**`
Mudanças nesses paths não disparam deploy automático.

## Teste curl
curl https://dev.shopvivaliz.com.br/api/health.php

## Checklist
- cache limpo
- permissões ok
- fallback-products.json atualizado
- build validado
- [skip ci] não presente no commit (bloqueia deploy)
