# Configuração segura de secrets Olist

Use exclusivamente os nomes canônicos definidos em `docs/knowledge/secrets-and-integrations-map.md`.

Secrets esperados, conforme o fluxo utilizado:

- `OLIST_CLIENT_ID`
- `OLIST_CLIENT_SECRET`
- `OLIST_ACCESS_TOKEN`
- `OLIST_REFRESH_TOKEN`
- `OLIST_REDIRECT_URI`
- `OLIST_API_BASE_URL`

Nunca registre valores neste repositório. Configure-os em GitHub Actions/Environment Secrets ou em gerenciador de segredos aprovado.

Após configurar, valide apenas presença e autenticação, sem imprimir conteúdo.
