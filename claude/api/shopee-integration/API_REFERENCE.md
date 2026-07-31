# Shopee API Reference

## Authentication

Use a provider-issued authorization code and protected secrets. Examples must never contain production or sandbox credentials.

### Token exchange

```text
POST /api/v2/auth/token/get
partner_id=<SHOPEE_PARTNER_ID>
sign=<HMAC_GERADO_EM_TEMPO_DE_EXECUCAO>
```

```json
{
  "code": "<AUTHORIZATION_CODE>",
  "shop_id": "<SHOPEE_SHOP_ID>",
  "partner_id": "<SHOPEE_PARTNER_ID>"
}
```

### Token refresh

```text
POST /api/v2/auth/access_token/get
```

```json
{
  "refresh_token": "<SHOPEE_REFRESH_TOKEN>",
  "shop_id": "<SHOPEE_SHOP_ID>",
  "partner_id": "<SHOPEE_PARTNER_ID>"
}
```

## Evidência

Respostas devem ser redigidas. Registre somente request ID, status, contagens, expiração e resultado do read-back. Nunca imprima access token, refresh token, partner key, auth code ou senha.

Credenciais anteriormente publicadas nesta documentação devem ser consideradas comprometidas e rotacionadas.
