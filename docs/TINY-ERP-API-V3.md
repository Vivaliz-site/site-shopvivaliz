# Tiny/Olist ERP API v3 — Referência de campos (levantada em produção)

> Fonte oficial: https://api-docs.erp.olist.com/ (documentação Mintlify da Olist).
> Base da API: `https://api.tiny.com.br/public-api/v3`
> Auth token: `https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token` (OAuth2 refresh_token)
>
> Este arquivo existe porque o schema real da API diverge do que várias implementações
> antigas no repo assumiam (campos errados, enums invertidos). **Antes de mexer em
> qualquer código que integra com o Tiny (`includes/tiny-order-push.php`,
> `daemon-sync-products.py`, `api/olist/*`), leia isto.**

## Índice de endpoints reais confirmados (path exato ≠ slug da doc, sempre confirme)

O `llms.txt` da doc (`api-docs.erp.olist.com/llms.txt`) tem um índice resumido, mas os
**slugs de URL da doc não batem com o path HTTP real** em vários casos (ex: doc mostra
`/formas-de-pagamento`, o path real é `/formas-pagamento`). Sempre abra a página da doc e
leia o bloco `cURL` de exemplo pra confirmar o path exato antes de codificar — não adivinhe.

| Recurso | Endpoint real (GET) | Doc |
|---|---|---|
| Pedidos — criar | `POST /pedidos` | [criar-pedido](https://api-docs.erp.olist.com/api-reference/pedidos/criar-pedido) |
| Produtos — obter (schema completo) | `GET /produtos/{id}` | [obter-produto](https://api-docs.erp.olist.com/api-reference/produtos/obter-produto) |
| Contatos — criar | `POST /contatos` | [criar-contato](https://api-docs.erp.olist.com/api-reference/contatos/criar-contato) |
| Contatos — listar/buscar | `GET /contatos` | [listar-contatos](https://api-docs.erp.olist.com/api-reference/contatos/listar-contatos) |
| Vendedores — listar | `GET /vendedores` | — |
| Intermediadores — listar | `GET /intermediadores` | [listar-intermediadores](https://api-docs.erp.olist.com/api-reference/intermediadores/listar-intermediadores) |
| Formas de pagamento — listar | `GET /formas-pagamento` | [listar-formas-de-pagamento](https://api-docs.erp.olist.com/api-reference/formas-de-pagamento/listar-formas-de-pagamento) |
| Formas de envio — listar | `GET /formas-envio` | [listar-formas-de-envio](https://api-docs.erp.olist.com/api-reference/logistica/listar-formas-de-envio) |
| Transportadores — listar | **não existe** (`/transportadores` = 404) | — |
| Naturezas de operação — listar | **não existe** (`/naturezas-operacao` = 404) | — |
| Listas de preço — listar | não confirmado (`/lista-de-precos`/`/listas-precos` = 404, path real não achado ainda) | — |
| Estoque | `GET /estoque/{id}` | — (só esse tem `disponivel` correto pra kits) |
| Pedidos — atualizar despacho/rastreio | `PUT /pedidos/{idPedido}/despacho` | [atualizar-informações-de-rastreamento-do-pedido](https://api-docs.erp.olist.com/api-reference/pedidos/atualizar-informa%C3%A7%C3%B5es-de-rastreamento-do-pedido) |
| Notas fiscais — obter | `GET /notas/{idNota}` | [obter-nota-fiscal](https://api-docs.erp.olist.com/api-reference/notas/obter-nota-fiscal) |
| Notas fiscais — obter XML | `GET /notas/{idNota}/xml` | [obter-xml-da-nota-fiscal](https://api-docs.erp.olist.com/api-reference/notas/obter-xml-da-nota-fiscal) |
| Categorias — árvore completa | `GET /categorias/todas` | [listar-árvore-de-categorias](https://api-docs.erp.olist.com/api-reference/categorias/listar-%C3%A1rvore-de-categorias) |

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
- **2026-07-18**: `vendedor` agora É preenchido (cadastrado vendedor genérico "Loja Online",
  id `369463749` -- era isso, junto com o campo `data` ausente, que fazia pedidos pushados
  pelo site sumirem da busca/listagem da UI do Tiny mesmo existindo via API). `transportador`
  e `intermediador` continuam de fora conscientemente: `GET /intermediadores` retorna 51
  cadastros, todos de marketplaces (Mercado Livre, Amazon, Shopee, TikTok Shop) -- nenhum
  corresponde a "venda direta pelo site", preencher um deles seria dado fiscal incorreto.
  As transportadoras cadastradas (`Correios/Jadlog/JeT/Loggi/Total Express via Melhor Envio`)
  são todas "Gateway logístico" via Melhor Envio -- a transportadora real só é decidida na
  cotação, depois do push inicial (ver `api/melhorenvio/generate-label-background.php`).
  Melhoria futura possível: fazer um PUT no pedido depois da etiqueta comprada, vinculando
  o `transportador.id` real baseado no serviço escolhido. Cada transportadora tem sub-servicos
  em `formaFrete` (ex: dentro de "Correios via Melhor Envio" -> PAC=codigo 1, SEDEX=codigo 2) --
  precisaria mapear nosso `shipping_service`/`shipping_label` (do lado Melhor Envio) pros IDs
  de transportador+formaFrete correspondentes do lado Tiny. Não implementado ainda -- exigiria
  primeiro listar as transportadoras via UI (API não expõe endpoint de listagem, `/transportadores`
  retorna 404) pra montar essa tabela de mapeamento.
- (histórico) **`vendedor`, `transportador`, `ecommerce`, `intermediador`** exigem IDs de cadastros
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

## Webhooks — app genérico "Webhooks" (NÃO é o caminho usado neste projeto)

⚠️ Isto documenta o app avulso "Webhooks" da Tiny por completude, mas **não é como
este projeto recebe eventos da Tiny** — ver seção seguinte ("Notificações da
integração API do ERP") pro caminho realmente em uso.

Configuração: `Menu → Configurações → Aba Geral → Outras configurações → Webhooks`.
Confirmação exige HTTP 200 na URL configurada; sem isso a Tiny reenvia até 10x com
delay progressivo (+5min por tentativa).

Eventos disponíveis:
- **Notificações de vendas** — Pedido de Venda criado ou alterado.
- **Notificações de pedidos enviados** — Pedido de Venda muda pra "enviado".
- **Notificações de lançamentos de estoque** — Produto tem atualização de estoque.
- **Notificações de notas fiscais autorizadas** — Nota Fiscal é autorizada.

Não é possível criar webhooks específicos por aplicativo (é por conta).

## Notificações da integração "API do ERP" (caminho realmente usado neste projeto)

Painel Tiny → `Integrações → API do ERP → gerenciar → aba Notificações → URLs de
notificações`. Tem 6 campos de URL, um por evento — cada um já vem preenchido
apontando pra um endpoint deste repo:
- Envio de produtos / estoque / preços → `olist/webhook-receiver.php?event=product|stock|price`
- Alteração na situação de pedidos / rastreio / **nota fiscal** →
  `api/webhooks/order-status-update.php?token=<OLIST_WEBHOOK_TOKEN>&type=order_status|tracking|invoice`

✅ **Implementado em 2026-07-18**: descoberto ao vivo (painel Tiny → Integrações →
API do ERP → aba Notificações → URLs de notificações) que **já existia** um endpoint
cadastrado pra isso desde antes — `api/webhooks/order-status-update.php`, recebendo
os 3 eventos "alteração na situação de pedidos", "rastreio" e **"nota fiscal"** (cada
um com sua própria URL, todas `.../order-status-update.php?token=...&type=<evento>`).
O endpoint já existia e já autenticava via `?token=` (comparado contra
`OLIST_WEBHOOK_TOKEN`/`ERP_WEBHOOK_TOKEN`), já mapeava `status: invoiced/invoice_sent`
→ `nota_fiscal_enviada` — só faltava agir sobre esse evento. Adicionado: quando o
status normalizado vira `nota_fiscal_enviada`, localiza o pedido local por
`order_number` (`svmp_find_order_path()`) e dispara
`api/melhorenvio/generate-label-background.php` em background, mesmo padrão já usado
em `api/webhook-mercadopago.php` (que **não gera mais a etiqueta na aprovação do
pagamento** — só faz o push do pedido pro Tiny).

⚠️ Nenhuma ação manual pendente — a URL já estava cadastrada no painel Tiny e
`OLIST_WEBHOOK_TOKEN` já está configurado em produção. Só falta validar com um pedido
real (a lógica assume que o `status` recebido no body é `invoiced`/`invoice_sent`;
se a Tiny mandar outro valor pro evento `type=invoice`, ajustar `$status_map` em
`api/webhooks/order-status-update.php`).

**Lição pra quem for mexer em webhooks aqui de novo**: antes de criar um endpoint
novo, sempre conferir `Integrações → API do ERP → Notificações → URLs de
notificações` no painel — só existe UM lugar pra ver quais URLs já estão realmente
cadastradas, e a UI de "adicionar integração" / app "Webhooks" (que a doc antiga
mencionava) não é o caminho certo pra esses 6 eventos nativos de e-commerce
(produto/estoque/preço/situação-pedido/rastreio/nota-fiscal) — esses vivem dentro da
integração "API do ERP" já instalada, aba Notificações.

## PUT /pedidos/{idPedido}/despacho — vincular transportador/rastreio real

Este é o endpoint que fecha a lacuna documentada acima em "vendedor agora É preenchido":
depois da etiqueta comprada via Melhor Envio, dá pra fazer PUT no pedido já criado no Tiny
com os dados reais de transporte — sem precisar saber isso no momento do push inicial.

Schema confirmado (todos os campos opcionais, `idPedido` no path):

```json
PUT /pedidos/{idPedido}/despacho
{
  "codigoRastreamento": "string",
  "urlRastreamento": "string",
  "formaEnvio": { "id": 123 },
  "formaFrete": { "id": 123 },
  "fretePagoEmpresa": 0.0,
  "dataPrevista": "2024-01-01",
  "idContatoTransportadora": 123,
  "volumes": 1,
  "pesoBruto": 0.0,
  "pesoLiquido": 0.0,
  "observacoes": "string"
}
```

Resposta: `204 No Content` em sucesso.

✅ **Implementado em 2026-07-18**: o fluxo de etiqueta (`includes/melhorenvio-label.php`)
agora chama este PUT depois da compra/geracao da etiqueta, usando os dados disponíveis no
pedido local e no webhook de NF. O payload final aceita:

- `codigoRastreamento` e `urlRastreamento` quando o pedido/integração já os tiver;
- `formaEnvio.id` via `TINY_DESPACHO_FORMA_ENVIO_ID` (ou heuristica por nome do servico);
- `formaFrete.id` via `TINY_DESPACHO_FORMA_FRETE_ID`;
- `idContatoTransportadora` via `TINY_DESPACHO_ID_CONTATO_TRANSPORTADORA`;
- `fretePagoEmpresa`, `dataPrevista`, `volumes`, `pesoBruto`, `pesoLiquido`, `observacoes`
  quando presentes.

Se faltar dado real para o despacho, a rotina registra `dispatch_payload_empty` em vez de
inventar valores.

## GET /notas/{idNota} e GET /notas/{idNota}/xml — consultar NF emitida

`GET /notas/{idNota}` retorna o modelo completo da nota fiscal: `situacao` (enum 1-10),
`tipo` (E/S), `numero`, `serie`, `chaveAcesso`, `dataEmissao`, valores (`valor`,
`valorProdutos`, `valorFrete`, `baseIcms`, `valorIcms`, `valorIpi`, etc.), `cliente`,
`enderecoEntrega`, `vendedor`, `transportador`, `itens[]`, `parcelas[]`.

`GET /notas/{idNota}/xml` retorna `{ "xmlNfe": "string", "xmlCancelamento": "string" }` —
o XML assinado da NF-e (e o de cancelamento, se houver).

✅ **Implementado em 2026-07-18**: o webhook `api/webhooks/order-status-update.php`
agora extrai `idNota` quando a Tiny envia esse identificador, faz `GET /notas/{idNota}`
e `GET /notas/{idNota}/xml`, grava `nf_id`, `nf_numero`, `nf_serie`,
`nf_chave_acesso`, `nf_data_emissao` na tabela `orders` e espelha esses dados no JSON
local do pedido. O CLI `scripts/fetch-tiny-invoice.php` também salva o snapshot da nota
em `storage/tiny/notas/{idNota}.json` e o XML em `storage/tiny/notas/{idNota}.xml`.

## GET /categorias/todas — árvore de categorias

```json
GET /categorias/todas
[
  { "id": 123456789, "descricao": "Camisetas", "filhas": [
    { "id": 987654321, "descricao": "Camisetas Masculinas", "filhas": [] },
    { "id": 876543219, "descricao": "Camisetas Femininas", "filhas": [] }
  ]}
]
```

Estrutura recursiva ilimitada.

✅ **Implementado em 2026-07-18**: o CLI `scripts/sync-tiny-categories.php` busca esta
árvore e grava cache local em `storage/tiny/categories-tree.json` e
`storage/tiny/categories-flat.json`. `includes/products-cache.php` passou a preferir esse
cache para devolver categorias reais do Tiny quando ele existe, com fallback para as
categorias locais dos produtos.

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
