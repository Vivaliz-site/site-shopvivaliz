# Sincronização segura com Olist

## Autorização

Use o fluxo OAuth oficial do provedor. Authorization code, access token e refresh token não devem aparecer em documentação, logs ou comandos versionados.

Secrets canônicos:

```text
OLIST_CLIENT_ID=<SECRET_PROTEGIDO>
OLIST_CLIENT_SECRET=<SECRET_PROTEGIDO>
OLIST_REFRESH_TOKEN=<SECRET_PROTEGIDO>
```

## Execução

O entrypoint legado `scripts/olist-sync-master.py` está aposentado e termina em estado `blocked`. Uma nova sincronização só pode ser habilitada depois de implementar:

- chamadas autenticadas reais;
- paginação e contagem de itens;
- request IDs redigidos;
- read-back de produtos, estoque e preços;
- retry limitado e tratamento de rate limit;
- artifact imutável;
- rollback ou backup quando houver mutação.

## Validação

Uma resposta de token deve ser tratada somente em memória e persistida no secret store. Relatórios podem registrar tipo, expiração e status, mas nunca o valor.

Exemplos antigos com tokens foram removidos. Valores anteriormente utilizados devem ser revogados e rotacionados no provedor.
