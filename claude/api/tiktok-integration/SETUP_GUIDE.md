# TikTok Shop API — configuração segura

## Configuração

Cadastre a aplicação no portal oficial e armazene os valores somente como:

- `TIKTOK_APP_KEY`
- `TIKTOK_APP_SECRET`
- `TIKTOK_ACCESS_TOKEN`
- `TIKTOK_REFRESH_TOKEN`
- `TIKTOK_SHOP_ID`

Configure pela CLI de forma interativa:

```bash
gh secret set TIKTOK_APP_KEY
gh secret set TIKTOK_APP_SECRET
gh secret set TIKTOK_REFRESH_TOKEN
gh secret set TIKTOK_SHOP_ID
```

## OAuth

A URL de autorização deve ser construída em tempo de execução usando `<TIKTOK_APP_KEY>` e uma redirect URI previamente aprovada. O authorization code não deve ser gravado em arquivo ou log.

## Validação

Uma sincronização somente pode ser declarada bem-sucedida com exit code, request ID redigido, contagens, read-back e artifact. Credenciais anteriormente publicadas devem ser revogadas e rotacionadas.
