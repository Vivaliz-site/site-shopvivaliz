# GitHub Secrets Configuration

## Objetivo

Configure as credenciais reais somente no GitHub Environment `Production` (ou no cofre de segredos do runtime). Nao copie IDs ou secrets de arquivos versionados antigos: houve configuracao OAuth obsoleta neste repositorio e ela ja retornou `invalid_client` em uma chamada real.

## Como adicionar os secrets

1. Abra o repositorio no GitHub.
2. Va para **Settings -> Environments -> Production -> Environment secrets**.
3. Cadastre os valores reais diretamente no cofre, sem grava-los em arquivos versionados.

Secrets usados pelo fluxo Google Ads:

```text
GOOGLE_OAUTH_CLIENT_ID=<client OAuth 2.0 ativo criado no Google Cloud>
GOOGLE_OAUTH_CLIENT_SECRET=<secret atual desse mesmo client>
GOOGLE_ADS_CUSTOMER_ID=<customer ID da conta de producao, 10 digitos>
GOOGLE_ADS_LOGIN_CUSTOMER_ID=<MCC/login customer ID, se necessario>
GOOGLE_ADS_DEVELOPER_TOKEN=<developer token do API Center>
GOOGLE_ADS_REFRESH_TOKEN=<refresh token emitido para o mesmo OAuth client>
GOOGLE_ADS_ID=<conversion ID, quando aplicavel>
GOOGLE_ADS_CONVERSION_LABEL=<conversion label, quando aplicavel>
GOOGLE_ANALYTICS_ID=<measurement ID do GA4>
```

## Regra critica de consistencia OAuth

`GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET` e `GOOGLE_ADS_REFRESH_TOKEN` precisam pertencer ao mesmo fluxo OAuth. Se o client for excluido, recriado ou tiver o secret rotacionado de forma incompatível, gere um novo refresh token.

O Client ID esperado pelo Google deve terminar em:

```text
.apps.googleusercontent.com
```

Nao reutilize valores antigos encontrados em commits, relatorios ou documentacao.

## Validacao obrigatoria

Antes de qualquer chamada live, rode o preflight sem rede:

```bash
python3 scripts/google_ads_auth_preflight.py
```

Resultado aceito:

```text
GOOGLE_ADS_AUTH_PREFLIGHT_OK
```

Depois, execute a auditoria read-only real pelo workflow **Google Ads Config CI -> Run workflow**. O workflow consulta campanhas e recomendacoes sem alterar budget, CPC, keywords, segmentacao ou status.

## Seguranca

- Nunca commitar `.env`.
- Nunca registrar OAuth Client Secret, Developer Token ou Refresh Token em Markdown, logs ou issues.
- Nao enviar secrets por chat.
- Se um secret tiver sido exposto, rotacione-o antes de reutilizar a integracao.
