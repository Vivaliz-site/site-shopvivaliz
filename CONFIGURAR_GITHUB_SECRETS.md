# Configuração segura de GitHub Secrets

## Regra principal

Credenciais nunca devem aparecer em arquivos, comandos versionados, prints, issues ou artifacts. Use apenas o store protegido do GitHub ou o environment correspondente.

## Secrets canônicos

- `OPENAI_API_KEY`
- `SHOPEE_PARTNER_ID`
- `SHOPEE_PARTNER_KEY`
- `SHOPEE_ACCESS_TOKEN`
- `SHOPEE_REFRESH_TOKEN`
- `SHOPEE_SHOP_ID`
- `TIKTOK_APP_KEY`
- `TIKTOK_APP_SECRET`
- `TIKTOK_ACCESS_TOKEN`
- `TIKTOK_REFRESH_TOKEN`
- `TIKTOK_SHOP_ID`
- `OLIST_CLIENT_ID`
- `OLIST_CLIENT_SECRET`
- `OLIST_REFRESH_TOKEN`
- `OLIST_WEBHOOK_TOKEN`
- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `SMTP_HOST`
- `SMTP_PORT`
- `SMTP_USER`
- `SMTP_PASS`

## Configuração

Pela interface do GitHub, abra **Settings → Secrets and variables → Actions** e adicione cada valor ao repositório ou ao environment apropriado.

Pela CLI, forneça o valor de forma interativa, sem colocá-lo na linha de comando:

```bash
gh secret set SHOPEE_PARTNER_KEY
gh secret set TIKTOK_APP_SECRET
gh secret set OLIST_WEBHOOK_TOKEN
gh secret list
```

## Exemplos seguros

```text
OPENAI_API_KEY=<SECRET_PROTEGIDO>
SHOPEE_PARTNER_KEY=<SECRET_PROTEGIDO>
TIKTOK_APP_SECRET=<SECRET_PROTEGIDO>
OLIST_WEBHOOK_TOKEN=<SECRET_PROTEGIDO>
```

## Validação

A existência do nome de um secret não comprova que o valor funciona. Uma integração somente pode ser declarada operacional após execução real com:

- código de saída;
- identificador da requisição redigido;
- contagem de itens;
- read-back;
- artifact ligado ao run e commit.

Nunca publique valores ao diagnosticar uma falha. Credenciais já versionadas devem ser revogadas e rotacionadas no provedor antes de qualquer limpeza de histórico.
