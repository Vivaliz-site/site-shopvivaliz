# TikTok Shop API Reference

## OAuth

```text
GET <PROVIDER_AUTHORIZATION_URL>
client_id=<TIKTOK_APP_KEY>
redirect_uri=<APPROVED_REDIRECT_URI>
response_type=code
```

```text
POST <PROVIDER_TOKEN_ENDPOINT>
```

```json
{
  "client_id": "<TIKTOK_APP_KEY>",
  "client_secret": "<TIKTOK_APP_SECRET>",
  "code": "<AUTHORIZATION_CODE>",
  "grant_type": "authorization_code",
  "redirect_uri": "<APPROVED_REDIRECT_URI>"
}
```

## Chamadas autenticadas

Use `TIKTOK_ACCESS_TOKEN` somente em memória. Respostas de autenticação devem ser redigidas; registre apenas request ID, status, expiração, contagens e read-back.

Credenciais anteriormente publicadas nesta referência devem ser consideradas comprometidas e rotacionadas.
