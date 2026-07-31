# API Olist local para desenvolvimento

Este servidor é um mock local e não representa autenticação real do provedor. Ele não deve receber credenciais de produção nem ser exposto à internet.

## Inicialização

```bash
python api/olist/local-server.py
```

## Endpoints principais

```text
GET  /health
GET  /status
POST /oauth/token
GET  /v2/orders
GET  /v2/products
GET  /webhooks
```

A resposta de OAuth usa placeholders explícitos:

```json
{
  "access_token": "<LOCAL_MOCK_ACCESS_TOKEN>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "<LOCAL_MOCK_REFRESH_TOKEN>"
}
```

## Restrições

- bind somente em localhost;
- nunca use partner key, access token ou refresh token real;
- não publique o servidor por túnel;
- não use o mock como evidência de integração operacional;
- testes de produção exigem API oficial, request ID, contagens, read-back e artifact.
