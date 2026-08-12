# M365 Automation Access - Instrucoes para Agentes

## Objetivo

Este documento permite que agentes autorizados usem a automacao Microsoft 365/Graph/Exchange da ShopVivaliz sem depender de contexto de conversa e sem expor segredos.

## Identificadores da aplicacao

- Aplicacao: `ShopVivaliz Exchange Automation`
- Client ID: `a5e400f0-969e-4fbe-be61-d390cb112517`
- Tenant ID: `cc55b801-12c2-4ea2-a930-d639aa8988a4`
- Thumbprint do certificado: `59D0A76B498BABED1C789079B61026925D73D762`
- Remetente operacional: `naoresponda@dev.shopvivaliz.com.br`
- OAuth grant: `client_credentials`
- Scope Graph: `https://graph.microsoft.com/.default`

## Estado validado

- Autenticacao por certificado X.509 funcionando.
- Aquisicao de token via MSAL funcionando.
- Microsoft Graph acessivel.
- `Mail.Send` validado em App-Only.
- Restricao do remetente retornou `Restricted: false` no ultimo teste conhecido.
- Framework Python implantado na Oracle VM.

## Oracle VM

- Host: `137.131.156.17`
- Usuario SSH: `ubuntu`
- Raiz do framework M365: `/opt/m365`
- Modulos Python: `/opt/m365/python`
- Virtualenv: `/opt/m365/venv`
- Config runtime: `/opt/m365/.env`
- PFX runtime: `/opt/m365/shopvivaliz_cert_new.pfx`

Os arquivos `.env` e `.pfx` sao segredos. Nunca imprimir, copiar para logs, anexar a issues, colar em chats ou versionar no Git.

## Modulos principais

- `python/m365_auth.py`: autenticacao por certificado e token.
- `python/graph_client.py`: Microsoft Graph.
- `python/exchange_client.py`: Exchange Online.
- `python/m365_config.py`: configuracao hibrida.
- `python/automation.py`: orquestracao principal.
- `python/automation_results.json`: relatorio estruturado da ultima execucao.

## Instrucoes para agentes

### 1. Antes de agir

1. Leia este documento por completo.
2. Confirme que a tarefa e realmente M365/Graph/Exchange da ShopVivaliz.
3. Confirme que nenhum outro agente esta alterando `/opt/m365` no mesmo momento.
4. Nao interrompa `mei-mg-email-worker.service` sem necessidade explicita.
5. Nao altere limites de envio, remetente ou credenciais sem autorizacao explicita.

### 2. Como acessar

Use apenas um mecanismo autorizado:

- GitHub Actions com a chave SSH armazenada em `SHOPVIVALIZ_VM_SSH_KEY`; ou
- relay/tunnel MCP previamente aprovado; ou
- SSH administrativo autorizado fora do repo.

Nunca grave uma chave SSH privada no YAML, Markdown ou codigo.

### 3. Preparar ambiente

Na VM:

```bash
cd /opt/m365
source /opt/m365/venv/bin/activate
```

Nao use `cat .env` nem `env` de forma que exponha segredos nos logs.

### 4. Validar identidade antes de qualquer operacao

Confirme:

- Tenant ID: `cc55b801-12c2-4ea2-a930-d639aa8988a4`
- Client ID: `a5e400f0-969e-4fbe-be61-d390cb112517`
- Thumbprint: `59D0A76B498BABED1C789079B61026925D73D762`
- Remetente: `naoresponda@dev.shopvivaliz.com.br`

Se qualquer valor divergir, pare e investigue antes de executar operacoes administrativas.

### 5. Gerar token corretamente

O access token deve ser gerado sob demanda por `m365_auth.py`/MSAL usando o certificado local.

**Nunca salvar o bearer token no Git.**

Nao usar `/me` com `client_credentials`; para App-Only use endpoints compativeis, por exemplo `/users/{UPN}` quando apropriado.

### 6. Operacoes Graph

Antes de uma chamada Graph:

1. Gere token sem imprimir seu valor.
2. Valide as roles/claims requeridas.
3. Execute a menor operacao necessaria.
4. Registre apenas status HTTP, request-id/correlation-id quando util e resultado sanitizado.

### 7. Exchange / Defender

`Directory.ReadWrite.All` e `Mail.Send` nao equivalem a administracao completa do Exchange/Defender.

Restricted Entities, regras administrativas ou operacoes Exchange podem exigir Exchange Online App-Only e roles adicionais, como `Exchange.ManageAsApp` ou RBAC especifico.

Se faltar role, reporte a ausencia. Nao tente contornar autorizacao.

### 8. Envio de email

Para envio operacional:

- remetente esperado: `naoresponda@dev.shopvivaliz.com.br`;
- respeitar os limites e salvaguardas do worker existente;
- nao criar um segundo sender em paralelo para contornar quota/rate-limit;
- antes de grande volume, validar Graph, estado do worker e quota de 24h.

### 9. Segredos que nunca podem ser versionados

Nunca commitar:

- bearer/access token;
- refresh token;
- senha do PFX;
- PFX ou PEM privado;
- client secret;
- cookie/sessao administrativa;
- chave SSH privada;
- conteudo integral de `/opt/m365/.env`.

### 10. GitHub Secrets

Secret operacional ja usado:

- `SHOPVIVALIZ_VM_SSH_KEY`

Para M365, se for necessario centralizar credenciais no GitHub, preferir secrets como:

- `M365_PFX_PASSWORD`
- `M365_PFX_BASE64` apenas se a arquitetura exigir transportar o certificado para um runner efemero

`M365_TENANT_ID` e `M365_CLIENT_ID` podem ser usados como variables ou secrets por conveniencia, embora nao sejam confidenciais.

### 11. Logs e redacao

Sempre redigir:

- `Authorization: Bearer ...`
- private keys
- PFX password
- cookies
- `.env`

Logs permitidos:

- `token_acquired=true`
- `role_Mail.Send=true`
- `restricted=false`
- `status=submitted`
- IDs nao secretos e timestamps

### 12. Troubleshooting

Se token falhar:

1. verificar validade do certificado;
2. verificar thumbprint;
3. verificar Tenant/Client ID;
4. verificar clock da VM;
5. verificar Admin Consent e roles da app;
6. nao substituir automaticamente por token manual de usuario.

Se Exchange falhar:

1. verificar modulo/cliente Exchange;
2. verificar `Exchange.ManageAsApp`/RBAC exigido;
3. confirmar que a app esta autorizada para a operacao;
4. nao ampliar permissoes sem necessidade.

Se envio falhar:

1. checar `mei-mg-email-worker.service`;
2. checar Graph App-Only;
3. checar quota/rate-limit;
4. checar NDR/Restricted Entities;
5. fazer um unico teste externo antes de escalar.

### 13. Handoff entre agentes

Ao terminar, registrar apenas:

- o que foi feito;
- arquivos alterados;
- servicos tocados;
- resultado validado;
- riscos ou pendencias;
- proxima acao recomendada.

Nunca incluir segredos no handoff.

## Regra principal

Os agentes devem reutilizar a infraestrutura de autenticacao existente e gerar credenciais temporarias sob demanda. **Nao criar, copiar ou versionar tokens de acesso permanentes.**
