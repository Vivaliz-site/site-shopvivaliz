# Medusa — configuração segura no servidor

## Ambiente

Configure o serviço com usuário dedicado, HTTPS, banco protegido e secrets fora do repositório.

```text
DATABASE_URL=<SECRET_PROTEGIDO>
JWT_SECRET=<SECRET_PROTEGIDO>
COOKIE_SECRET=<SECRET_PROTEGIDO>
MEDUSA_WEBHOOK_SECRET=<SECRET_PROTEGIDO>
```

## Deploy

1. Instale dependências com lockfile.
2. Execute build e migrations.
3. Inicie o processo por systemd ou gerenciador equivalente.
4. Restrinja portas administrativas.
5. Valide endpoint de saúde.

## Webhooks EHA

- use URL HTTPS aprovada;
- gere secret aleatório no secret store;
- valide assinatura e timestamp;
- aplique idempotência por event ID;
- rejeite payload inválido;
- registre apenas IDs e status redigidos;
- confirme o efeito por read-back.

## Evidência

O deploy só é considerado concluído quando build, migrations, health check e webhook canário estiverem ligados ao run e commit em artifact imutável.

Secrets padrão anteriormente documentados foram removidos e devem ser rotacionados caso tenham sido utilizados.
