# Configuração de Webhooks Olist/Tiny ERP

## Credenciais

- **Identificador do parceiro:** `31816`.
- **Token:** armazenar somente em secret protegido, usando o nome canônico `OLIST_WEBHOOK_TOKEN`.
- **Endpoint de cotação:** `https://erp.olist.com/webhook/api/v1/parceiro/31816/cotar`.

O token anteriormente versionado deve ser considerado comprometido e revogado no provedor. Nenhum valor substituto deve ser gravado neste arquivo ou em outro conteúdo versionado.

---

## URLs para configurar na Olist

A configuracao pertence a **Integrações -> API do ERP -> Gerenciar -> Notificações**. O parametro `key` deve ser preenchido somente no painel ERP com o segredo protegido `OLIST_WEBHOOK_SECRET` / `TINY_WEBHOOK_SECRET`; nunca versionar o valor. Conferir sanitizacao das queries nos logs de acesso e, quando disponivel, preferir `X-Webhook-Key`. A alteracao precisa ser verificada por evento genuino do provedor; nao basta copiar uma URL.

O endpoint canonico diferencia os eventos pelo campo `tipo` no JSON oficial (produto, precos, estoque).

### Produtos

```text
https://shopvivaliz.com.br/api/olist/webhook.php?key=<SEGREDO_CONFIGURADO>
```

Eventos: `produto.criado`, `produto.atualizado`, `preco.alterado`, `estoque.alterado`.

### Estoque

```text
https://shopvivaliz.com.br/api/olist/webhook.php?key=<SEGREDO_CONFIGURADO>
```

Evento: `estoque.alterado`.

### Preços

```text
https://shopvivaliz.com.br/api/olist/webhook.php?key=<SEGREDO_CONFIGURADO>
```

Evento: `preco.alterado`.

### Pedidos

```text
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=order
```

Eventos: `pedido.criado`, `pedido.alterado`, `pedido.cancelado`.

### Rastreio

```text
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=tracking
```

Evento: `rastreio.alterado`.

### Nota fiscal

```text
https://shopvivaliz.com.br/olist/webhook-receiver.php?event=invoice
```

Evento: `nota_fiscal.emitida`.

---

## Configuração segura

1. Acesse o painel oficial da Olist.
2. Cadastre cada URL HTTPS necessária.
3. Selecione somente os eventos utilizados.
4. Configure o token rotacionado no secret protegido do ambiente de produção.
5. Execute o teste fornecido pelo provedor.
6. Verifique logs redigidos, sem token ou dados de autenticação.
7. Confirme o efeito por leitura posterior da API de catálogo.

## Evidência mínima

Uma configuração só pode ser declarada operacional quando existirem:

- identificador do teste do provedor;
- timestamp;
- status HTTP sem cabeçalhos sensíveis;
- evento recebido;
- read-back do dado esperado;
- run ou artifact imutável associado.

## Troubleshooting

- Webhook não chega: valide HTTPS, DNS, autenticação e logs redigidos.
- Produtos não atualizam: valide a fila, o processador e o read-back do catálogo.
- Volume excessivo: aplique idempotência, fila, rate limit e concorrência controlada.

Este documento não comprova que a integração está pronta para produção. A aprovação depende de token rotacionado e teste real com evidência verificável.
