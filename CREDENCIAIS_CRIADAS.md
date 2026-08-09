# Credenciais Google OAuth 2.0 - Registro Sanitizado

**Status:** REQUER RECONFIGURACAO OAUTH

Este arquivo nao e fonte de verdade para credenciais. Valores reais devem existir apenas no `.env` privado, GitHub Environment `Production` ou outro cofre de segredos autorizado.

## Estado atual

- Um OAuth Client antigo documentado neste repositorio nao deve mais ser reutilizado.
- Uma chamada real da Google Ads API retornou `invalid_client`, indicando client OAuth inexistente, excluido ou incorreto no secret store.
- O Google Ads Developer Token e o Refresh Token podem estar cadastrados, mas so funcionarao depois que o OAuth Client ID/Secret forem validos e consistentes.
- O refresh token deve ser reemitido para o mesmo OAuth Client ativo.

## Configuracao segura esperada

```env
GOOGLE_OAUTH_CLIENT_ID=<client_id_ativo_terminando_em_.apps.googleusercontent.com>
GOOGLE_OAUTH_CLIENT_SECRET=<secret_atual_do_mesmo_client>
GOOGLE_ADS_CUSTOMER_ID=<customer_id_real_da_conta_de_producao>
GOOGLE_ADS_LOGIN_CUSTOMER_ID=<mcc_id_se_necessario>
GOOGLE_ADS_DEVELOPER_TOKEN=<armazenado_somente_em_secret_store>
GOOGLE_ADS_REFRESH_TOKEN=<refresh_token_reemitido_para_o_mesmo_client>
GOOGLE_ADS_CONVERSION_SOURCE=GA4_IMPORT
```

## Validacao

Primeiro rode o preflight local, que nao faz chamadas de rede e nao imprime secrets:

```bash
python3 scripts/google_ads_auth_preflight.py
```

Resultado esperado:

```text
GOOGLE_ADS_AUTH_PREFLIGHT_OK
```

Em seguida rode manualmente o workflow `Google Ads Config CI`. A etapa live e read-only e deve ser usada para confirmar:

- autenticacao OAuth;
- acesso do Developer Token;
- acesso ao Customer ID;
- campanhas dos ultimos 30 dias;
- recomendacao de imagens dinamicas.

## Seguranca

- Nao gravar Client Secret, Developer Token ou Refresh Token em arquivos versionados.
- Nao copiar credenciais de commits antigos.
- Rotacionar qualquer secret previamente exposto.
- Nao enviar tokens por chat.

## Campanhas

Configuracoes de campanha permanecem separadas em `scripts/google_ads_campaign_live_ready.json`. A criacao/ativacao real deve respeitar os guardrails existentes e nunca deve ser liberada apenas porque o preflight passou.
