# Integração segura entre Medusa e EHA

## Objetivo

Conectar eventos de catálogo e pedidos ao EHA sem publicar credenciais, tokens ou secrets de webhook.

## Secrets

```text
MEDUSA_ADMIN_EMAIL=<EMAIL_ADMINISTRATIVO>
MEDUSA_ADMIN_PASSWORD=<SECRET_PROTEGIDO>
MEDUSA_WEBHOOK_SECRET=<SECRET_PROTEGIDO>
EHA_API_URL=<ENDPOINT_HTTPS_APROVADO>
```

## Fluxo

1. Crie o usuário administrativo com senha única.
2. Autentique por cliente oficial ou variável de ambiente.
3. Cadastre webhooks com secret gerado aleatoriamente.
4. Valide assinatura, timestamp e idempotência no receptor.
5. Processe o evento em fila.
6. Faça read-back do produto ou pedido.
7. Registre artifact com event ID, run e commit, nunca com a credencial.

## Teste

Use um produto canário e remova-o depois da validação. O teste deve falhar quando faltar autenticação, assinatura ou read-back.

Exemplos antigos com email e senha padrão foram removidos. Caso tenham sido usados, as credenciais devem ser rotacionadas.
