# Tiny/Olist ERP API v3 — Referência de campos (levantada em produção)

> Fonte oficial: https://api-docs.erp.olist.com/ (documentação Mintlify da Olist).
> Base da API: `https://api.tiny.com.br/public-api/v3`
> Auth token: `https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token` (OAuth2 refresh_token)
>
> Este arquivo existe porque o schema real da API diverge do que várias implementações
> antigas no repo assumiam (campos errados, enums invertidos). **Antes de mexer em
> qualquer código que integra com o Tiny (`includes/tiny-order-push.php`,
> `daemon-sync-products.py`, `api/olist/*`), leia isto.**

## Limites de requisição

- Limite por minuto, diferenciado leitura (GET, mais folgado) vs escrita (POST/PUT/DELETE).
- Limite é **por conta**, não por aplicativo — todos os apps ativos compartilham o mesmo limite.
- Headers de controle: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset` (segundos).
- Retry com backoff é obrigatório; a API retorna 429 ao estourar.

## POST /pedidos — criar pedido

Schema real (confirmado contra `api-docs.erp.olist.com/api-reference/pedidos/criar-pedido`
e testado ao vivo, POST retornou 201):

```json
{
  "dataPrevista": "2024-01-01",
  "dataEnvio": "2024-01-01 00:00:00",
  "observacoes": "string",
  "observacoesInternas": "string",
  "data": "2024-01-01",
  "dataEntrega": "2024-01-01",
  "numeroOrdemCompra": "string",
  "valorDesconto": 0,
  "valorFrete": 0,
  "valorOutrasDespesas": 0,
  "idContato": 123,
  "listaPreco": { "id": 123 },
  "naturezaOperacao": { "id": 123 },
  "vendedor": { "id": 123 },
  "enderecoEntrega": { "...": "..." },
  "consumidorFinal": { "cpfCnpj": "string", "clienteConsumidorFinal": true },
  "ecommerce": { "id": 123, "numeroPedidoEcommerce": "string" },
  "transportador": {
    "id": 123,
    "formaEnvio": { "id": 123 },
    "formaFrete": { "id": 123 },
    "codigoRastreamento": "string",
    "urlRastreamento": "string"
  },
  "intermediador": { "id": 123 },
  "deposito": { "id": 123 },
  "pagamento": {
    "formaRecebimento": { "id": 123 },
    "meioPagamento": { "id": 123 },
    "parcelas": [{ "dias": 0, "data": "2024-01-01", "valor": 0, "formaRecebimento": { "id": 123 }, "meioPagamento": { "id": 123 } }]
  },
  "itens": [{ "produto": { "id": 123 }, "quantidade": 1, "valorUnitario": 0, "infoAdicional": "string" }],
  "pagamentosIntegrados": [{ "valor": 10.5, "tipoPagamento": 1, "cnpjIntermediador": "00000000000191", "codigoAutorizacao": "123456", "codigoBandeira": 1 }]
}
```

Response 200/201: `{ "id": 123, "numeroPedido": "string" }`

### Erros comuns já vistos em produção e o que significam

- **`numeroPedido` não aceita string customizada.** A Tiny ignora o valor enviado e
  atribui seu próprio número sequencial interno (o que aparece na lista do ERP, ex:
  "3021"). Para referenciar o número de pedido do nosso site, use `numeroOrdemCompra`
  (campo livre) **e** repita na `obs` — não existe campo dedicado confiável pra isso
  fora do `ecommerce.numeroPedidoEcommerce`, que por sua vez exige um `ecommerce.id`
  de canal registrado que não temos para vendas diretas do site.
- **`situacao` é enum inteiro, não objeto.** Mandar `{"situacao": {...}}` quebra com
  `"O tipo de dado 'stdClass' não é válido para a propriedade 'situacao' do tipo 'int'"`.
- **Enum de `situacao` (confirmado na doc oficial):**
  | valor | significado |
  |---|---|
  | 8 | Dados Incompletos |
  | 0 | Aberta |
  | 3 | Aprovada |
  | 4 | Preparando Envio |
  | 1 | **Faturada** |
  | 7 | Pronto Envio |
  | 5 | Enviada |
  | 6 | Entregue |
  | 2 | Cancelada |
  | 9 | Não Entregue |

  ⚠️ **`situacao=1` significa "Faturada" (nota fiscal já emitida), NÃO "Aberta"/"Aberto"
  como um comentário antigo no código assumia.** Isso fez todo pedido criado pelo push
  do site nascer marcado como se já tivesse NF emitida, sem nunca ter sido de fato.
  Pedidos recém-criados (pagamento aprovado, mas NF ainda não emitida) devem usar
  `situacao: 0` (Aberta).

- **Não existe campo `idDeposito` solto.** É `deposito: { "id": ... }`.
- **Não existe campo `formaPagamento` dentro de `pagamento`.** Só existem
  `formaRecebimento` e `meioPagamento`, ambos objetos `{ "id": ... }`.
- **`vendedor`, `transportador`, `ecommerce`, `intermediador`** exigem IDs de cadastros
  já existentes na conta (não são strings livres). Ver seção "Cadastros da conta" abaixo
  para os IDs reais já levantados. Se a conta não tem nenhum registro do tipo (ex:
  `vendedor` — `GET /vendedores` retorna vazio nesta conta), não dá pra preencher, e
  mandar um ID inválido provavelmente quebra o POST.

## Cadastros da conta (IDs reais, levantados via API em 2026-07-17)

Estes IDs são **desta conta especificamente** — se a conta Tiny mudar, refazer o
levantamento via `GET /formas-pagamento`, `GET /formas-envio`, `GET /depositos`.

### GET /formas-pagamento

| id | nome |
|---|---|
| 337683276 | Dinheiro |
| 337683277 | Cartão de crédito |
| 337683278 | Cartão de débito |
| 337683279 | Boleto |
| 337683280 | Cheque |
| 337683281 | Depósito |
| 337683282 | Crediário |
| 337683283 | Vale-troca |
| 337683284 | Pix |
| 348327198 | Integração |
| 351229816 | Link de pagamento |
| 360487679 | Cashback (situação 2 = inativo) |
| 368193978 | Vale-presente (situação 2 = inativo) |

Pedido pago via Mercado Pago no site (cartão de crédito, na prática) mapeia pra
`337683277`. PIX mapeia pra `337683284`.

### GET /depositos

| id | descrição | tipo | observação |
|---|---|---|---|
| 337683271 | Geral | P (próprio) | **padrão** (`padrao: true`) — usar este pra pedidos do site |
| 351953500 | Amazon dba | E (externo) | |
| 337722748 | FBA Amazon | E (externo) | desconsidera saldo |
| 337758618 | FBA Onsite | E (externo) | |
| 337722734 | Mercado Livre Full | E (externo) | desconsidera saldo |

### GET /formas-envio (parcial, as relevantes pra Melhor Envio)

| id | nome | tipo |
|---|---|---|
| 337683312 | Correios | 1 |
| 337683314 | Transportadora | 2 |
| 337683315 | Retirar pessoalmente | 6 |
| 337692320 / 337724599 | Mercado Envios | 3 |
| 337724753 | Correios via Melhor envio | 10 |
| 337724757 | Jadlog via Melhor envio | 10 |

### GET /vendedores

Vazio nesta conta (`{"itens":[],"paginacao":{"total":0}}`). Não preencher `vendedor`
em pedidos até haver um cadastro real.

## Canais de venda (`ecommerce.nome`) observados nos pedidos reais

Contagem de pedidos dos últimos 30 dias (2026-07-17), por canal:

| canal | pedidos |
|---|---|
| Amazon Onsite | 427 |
| Mercado Livre | 196 |
| Mercado Livre Fulfillment | 51 |
| Amazon | 35 |
| TikTok Shop | 15 |
| Amazon Classic | 6 |
| Shopee | 4 |
| TikTok site | 3 |
| tiktok oficial | 2 |
| Ecommerce | 2 |

⚠️ **"Amazon Onsite" é venda real feita dentro do marketplace da Amazon (programa FBA
Onsite), não é o site shopvivaliz.com.br.** Confirmado diretamente pelo usuário/dono da
conta. Pedidos genuinamente originados do checkout próprio do site (Mercado Pago) **não
têm um canal `ecommerce` próprio registrado nesta conta** — chegam com `ecommerce.id: 0`
e `ecommerce.nome: ""`. Rastreá-los exige usar `numeroOrdemCompra`/`obs` (formato
`SV<17 dígitos>`, ver `svmp_order_number_is_valid()` em
`includes/mercadopago-gateway.php`) e comparar com `storage/orders/*.json` local — não
dá pra confiar em nenhum filtro de canal do próprio ERP pra isolar "pedidos do site".

## GET /pedidos/{id} vs GET /estoque/{id} — duas fontes de estoque diferentes

- `GET /produtos/{id}` → `estoque.quantidade`: estoque bruto do produto, **não calcula
  composição de kit** (produtos `tipo: "K"` sempre mostram aqui o próprio saldo do kit
  como item, que costuma ficar zerado/desatualizado).
- `GET /estoque/{id}` → campo `disponivel`: estoque **calculado pela própria Tiny**,
  já considerando reserva e composição de kit corretamente, com detalhamento por
  depósito (`depositos[]`, cada um com `desconsiderar: bool`). **Esta é a fonte
  correta** — usada em `daemon-sync-products.py` desde 2026-07-17.

## Produtos tipo kit

`GET /produtos/{id}` retorna `tipo: "K"` e um array `kit` com a composição:

```json
"kit": [
  { "produto": { "id": 342902508, "sku": "1C7Q-LKVS-MDOC", "tipo": "P" }, "quantidade": 2 },
  { "produto": { "id": 342902497, "sku": "1C7Q-LKVS-MQQN", "tipo": "P" }, "quantidade": 2 }
]
```

Não confiar em `estoque.quantidade` do próprio kit — usar `GET /estoque/{id}` (campo
`disponivel`, já calculado corretamente pela Tiny) em vez de recalcular manualmente pela
composição.

## Webhooks (app "Webhooks" precisa estar instalado na conta)

Configuração: `Menu → Configurações → Aba Geral → Outras configurações → Webhooks`.
Confirmação exige HTTP 200 na URL configurada; sem isso a Tiny reenvia até 10x com
delay progressivo (+5min por tentativa).

Eventos disponíveis:
- **Notificações de vendas** — Pedido de Venda criado ou alterado.
- **Notificações de pedidos enviados** — Pedido de Venda muda pra "enviado".
- **Notificações de lançamentos de estoque** — Produto tem atualização de estoque.
- **Notificações de notas fiscais autorizadas** — Nota Fiscal é autorizada.

Não é possível criar webhooks específicos por aplicativo (é por conta).

✅ **Implementado em 2026-07-18**: `api/webhooks/tiny-nota-fiscal.php` recebe o evento
"notas fiscais autorizadas" e dispara `api/melhorenvio/generate-label-background.php`
pro pedido correspondente (localizado por `tiny_order_id` em `storage/orders/*.json`).
`api/webhook-mercadopago.php` não gera mais a etiqueta na aprovação do pagamento —
só faz o push do pedido pro Tiny; a etiqueta agora só é comprada depois que a NF é
emitida de fato no ERP.

⚠️ **Ainda pendente (ação manual do usuário, não automatizável por API)**:
1. Configurar `TINY_WEBHOOK_SECRET` no `.env` (local e produção) — um token
   aleatório qualquer, usado só pra autenticar a URL do webhook.
2. No painel Tiny: `Menu → Configurações → Aba Geral → Outras configurações →
   Webhooks` → app "Webhooks" instalado → ativar **"Notificações de notas
   fiscais autorizadas"** com URL
   `https://dev.shopvivaliz.com.br/api/webhooks/tiny-nota-fiscal.php?token=<TINY_WEBHOOK_SECRET>`.
3. O formato exato do payload desse evento específico da Tiny não foi observado
   ao vivo ainda (só é possível depois do passo 2). O parsing em
   `tiny-nota-fiscal.php` tenta os campos mais prováveis (`dados.idPedido`,
   `dados.pedido.id`, `dados.id`, `id`, `idPedido`) e loga o payload cru em
   `error_log` sempre que não reconhece o pedido — ajustar o parsing com um
   exemplo real assim que o primeiro webhook chegar.

## Como redescobrir estes dados se algo mudar

```bash
# Token (mesma logica de includes/tiny-order-push.php::svtop_tiny_get_token)
curl -s -X POST https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token \
  -d grant_type=refresh_token -d client_id=$OLIST_CLIENT_ID \
  -d client_secret=$OLIST_CLIENT_SECRET -d refresh_token=$OLIST_REFRESH_TOKEN

# Cadastros
curl -s https://api.tiny.com.br/public-api/v3/formas-pagamento -H "Authorization: Bearer $TOKEN"
curl -s https://api.tiny.com.br/public-api/v3/formas-envio -H "Authorization: Bearer $TOKEN"
curl -s https://api.tiny.com.br/public-api/v3/depositos -H "Authorization: Bearer $TOKEN"
curl -s https://api.tiny.com.br/public-api/v3/vendedores -H "Authorization: Bearer $TOKEN"

# Estoque real de um produto (inclui calculo de kit)
curl -s https://api.tiny.com.br/public-api/v3/estoque/{id} -H "Authorization: Bearer $TOKEN"
```

Documentação completa (sitemap com ~322 páginas): `https://api-docs.erp.olist.com/sitemap.xml`.
