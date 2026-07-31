# TikTok Shop API Integration

## Secrets necessários

```text
TIKTOK_APP_KEY=<SECRET_PROTEGIDO>
TIKTOK_APP_SECRET=<SECRET_PROTEGIDO>
TIKTOK_ACCESS_TOKEN=<SECRET_PROTEGIDO>
TIKTOK_REFRESH_TOKEN=<SECRET_PROTEGIDO>
TIKTOK_SHOP_ID=<SECRET_PROTEGIDO>
```

## Fluxo seguro

1. Autorize a aplicação pela interface oficial.
2. Valide redirect URI e state.
3. Armazene tokens apenas em secrets protegidos.
4. Não registre respostas completas de autenticação.
5. Exija request ID redigido, contagens, read-back e artifact.

Credenciais anteriormente publicadas nesta pasta devem ser consideradas comprometidas e rotacionadas no provedor. A presença dos nomes dos secrets não comprova prontidão operacional.
