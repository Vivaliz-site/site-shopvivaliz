# Google Cloud API Bootstrap — ShopVivaliz

Este guia complementa `docs/GOOGLE-APIS-OAUTH-INTEGRATION.md`.

## Quando usar

Use este bootstrap quando uma rotina OAuth estiver autenticando corretamente, mas o Google responder que a API correspondente **não foi usada no projeto** ou está **desabilitada**.

Ter o escopo OAuth autorizado não ativa automaticamente a API no Google Cloud. São duas camadas diferentes:

1. o refresh token precisa carregar o escopo necessário;
2. o serviço precisa estar habilitado no projeto Google Cloud do OAuth Client.

## Comando autorizado

O projeto fornece um utilitário com allow-list fixa:

```bash
php scripts/google-cloud-service-enable.php searchconsole.googleapis.com
```

Serviços permitidos pelo utilitário:

```text
searchconsole.googleapis.com
analyticsdata.googleapis.com
tagmanager.googleapis.com
merchantapi.googleapis.com
indexing.googleapis.com
```

O script **não aceita nomes arbitrários de serviço**. Ele consulta o estado primeiro; se o serviço já estiver `ENABLED`, não faz alteração. Se estiver indisponível, tenta `services.enable` via Service Usage API e verifica o resultado.

## Projeto Google Cloud

Preferência:

```dotenv
GOOGLE_CLOUD_PROJECT_NUMBER=
```

Se essa variável não estiver definida, o utilitário deriva o project number do prefixo numérico de `GOOGLE_OAUTH_CLIENT_ID`.

`GOOGLE_CLOUD_PROJECT_NUMBER` não é segredo e pode ser configurado como GitHub Repository/Environment Variable.

## Permissões

A ativação usa:

```text
POST https://serviceusage.googleapis.com/v1/projects/{PROJECT_NUMBER}/services/{SERVICE}:enable
```

Ela requer OAuth com `https://www.googleapis.com/auth/cloud-platform` e permissão IAM suficiente para habilitar serviços no projeto. O utilitário **não contorna IAM**: em `403`, ele encerra e apresenta somente a mensagem sanitizada do Google.

## Search Console

O workflow `.github/workflows/google-search-console-audit.yml` executa o bootstrap de `searchconsole.googleapis.com` antes da inspeção das URLs. Assim:

- a primeira execução pode habilitar a API quando a identidade tiver permissão;
- execuções posteriores fazem apenas a leitura do estado e seguem para a auditoria;
- os secrets continuam vindo do GitHub Environment `Production`/Secrets e nunca são impressos.

## Regra para outros agentes

Antes de criar uma nova automação Google que dependa de uma API Cloud:

1. usar o OAuth compartilhado;
2. confirmar o service ID oficial;
3. confirmar que o service ID está na allow-list antes de habilitá-lo;
4. habilitar somente o serviço exigido pela tarefa;
5. nunca desabilitar serviços automaticamente;
6. nunca adicionar `cloud-platform` a um novo consentimento sem necessidade operacional explícita;
7. não usar este mecanismo para contornar IAM, quotas ou políticas do Google.

Referência oficial: https://cloud.google.com/service-usage/docs/reference/rest/v1/services/enable
