# Mercado Livre — API de Items (MLB)

> Levantado em 2026-08-13 durante a otimizacao de SEO dos anuncios sem venda.
> Seller: SHOPVIVALIZ (`user_id` 112962856, loja oficial 385272).
> Scripts que dependem disto: `scripts/ml-seo-optimizer.py`, `scripts/ml-image-optimizer.py`.

Leia antes de escrever qualquer coisa que faca `PUT /items`. Boa parte das regras
abaixo nao aparece na documentacao publica e so se descobre pelo erro 400.

---

## Credenciais e token

- `ML_CLIENT_ID` / `ML_CLIENT_SECRET` vivem em `shopvivaliz-deploy/shared/.env` na VM.
  **Nao existe `ML_ACCESS_TOKEN` no `.env`** — o token fica em
  `shopvivaliz-deploy/shared/storage/private/ml-tokens.json`.
- O `access_token` dura 6 horas (`expires_in: 21600`). Renove por
  `POST /oauth/token` com `grant_type=refresh_token` e **regrave o arquivo**: o
  refresh token tambem rotaciona a cada renovacao.
- `user_id` dentro do arquivo de token e o seller id.

## Listar o catalogo

`GET /users/{seller}/items/search?search_type=scan&limit=100` + `scroll_id`.
O `offset` normal trava em 1000 itens; o modo `scan` pagina o catalogo inteiro.

`GET /items?ids=a,b,c&attributes=...` aceita **no maximo 20 ids** por chamada e
devolve 503 esporadico em lote grande — trate como falha recuperavel e repita, se
nao voce perde itens silenciosamente do universo processado.

---

## ⚠️ O titulo nao e editavel quando o item tem `family_name`

Este e o achado que muda a estrategia inteira. **Todos os 158 anuncios ativos sem
venda do seller** sao `user_product_listing`: tem `family_name` e `user_product_id`.

Tentativas e respostas reais:

| Tentativa | Resposta |
|---|---|
| `PUT /items/{id}` com `title` | `400` — `"You cannot modify the title if the item has a family_name"` (cause 374) |
| `PUT /items/{id}` com `family_name` | `400 BODY_INVALID_FIELDS` — `"The field family name is invalid"`, **mesmo enviando o valor identico ao atual** |
| `PUT /user-products/{user_product_id}` | `404 resource not found` (o endpoint so tem GET) |
| `GET /user-products/{id}/family` | `500` embrulhando um `404 NOT_FOUND` |

Ou seja: nao existe caminho de API para reescrever o titulo desses anuncios. A
documentacao sugere que `family_name` seria editavel e que o titulo seria
recalculado a partir dele; **na pratica, nesta conta, o campo e rejeitado**. Se for
tentar de novo, confirme antes se a conta saiu do modelo "Preco por Variacao".

### Mas o titulo muda pelos atributos

O ML **recompoe** o titulo concatenando `family_name` + valores de atributos de
variacao. Comprovado: preencher `FINISH=Polido` (tag `allow_variations`) em
`MLB3458321963` mudou o titulo de

    Ducha Em Alumínio 8 Polegadas - Pro Lazer Prateado

para

    Ducha Em Alumínio 8 Polegadas - Pro Lazer Prateado Polido

Consequencias praticas:

1. A ficha tecnica e o unico caminho para influenciar o titulo — e o principal fator
   de posicionamento de qualquer forma. E onde investir.
2. Preencher atributo de variacao tem **efeito colateral no titulo**. Um valor que
   repete palavra ja presente gera titulo redundante, e o titulo pode estourar o
   `max_title_length` da categoria. `ml-seo-optimizer.py` relê o titulo apos o PUT e
   reverte os atributos de variacao se o titulo piorar.
3. Para limpar um atributo, mande `{"id": X, "value_id": null, "value_name": null}`.

---

## O que aceita PUT (confirmado em producao)

| Campo | Endpoint | Observacao |
|---|---|---|
| `attributes` | `PUT /items/{id}` | ✅ funciona; recompoe o titulo (acima) |
| `pictures` | `PUT /items/{id}` | ✅ funciona; aceita `[{"id": "..."}]` para so reordenar |
| descricao | `PUT /items/{id}/description` com `{"plain_text": "..."}` | ✅ funciona; substitui a descricao inteira |
| `title` | `PUT /items/{id}` | ❌ bloqueado por `family_name` |
| `family_name` | `PUT /items/{id}` | ❌ rejeitado |

---

## Categorias e atributos

- `GET /categories/{id}` → `settings.max_title_length`. **Nao presuma 60**: das 26
  categorias usadas por este seller, 24 limitam em 60 e 2 em 200.
- `GET /categories/{id}/attributes` → lista completa. Filtre antes de oferecer para
  preenchimento: `tags.hidden`, `tags.read_only` e `tags.fixed` nao sao editaveis, e
  o prefixo `PACKAGE_*`, `SAT_*`, `IVA*`, `IMPORT_*`, `INVOICE_*` etc. e fiscal e
  logistico, nao ficha tecnica.
- Atributo com lista `values` exige `value_id` de um item da lista — texto livre e
  recusado. `number_unit` exige `"<numero> <unidade>"` com unidade de
  `allowed_units`.
- `GET /categories/{id}/sale_terms` devolve principalmente termos de assinatura e
  loyalty; a garantia (`WARRANTY_TYPE` / `WARRANTY_TIME`) ja vinha preenchida em 58
  dos 60 itens amostrados.
- `GET /trends/MLB/{category_id}` devolve os termos mais buscados da categoria —
  insumo direto de SEO, sem custo.
- `GET /sites/MLB/domain_discovery/search?q=<titulo>` sugere dominio e categoria.
  **Trocar categoria zera a ficha tecnica**, entao os scripts apenas reportam a
  sugestao.

## Health / qualidade

`GET /items/{id}/health` responde `404` com
`"Items with buying mode 'buy_it_now' are not allowed"` para estes anuncios — nao da
para usar o score de saude do ML aqui. Use as `tags` do item
(`good_quality_thumbnail`, `poor_quality_thumbnail`, `picture_crop_fix`) como proxy.

## Imagens

- `pictures[].max_size` e a resolucao do original enviado. Foi a base do diagnostico:
  154 de 158 capas abaixo de 1200x1200 (limiar de zoom do ML) e 11 abaixo de 500px.
- `http2.mlstatic.com` **recusa download por servidores da Anthropic** (`400 Unable to
  download the file`). Para analisar imagem com IA, baixe na VM e mande em base64.
- Reordenar e seguro e reversivel; o `PUT` aceita so os ids na ordem nova.

---

## Recorte de quem pode ser otimizado

Do catalogo de 565 anuncios (2026-08-13):

- 284 sem nenhuma venda
- desses, 226 ativos
- desses, **158 fora do catalogo** (`catalog_listing: false`) — os unicos otimizaveis

Os 102 sem venda que sao `catalog_listing: true` tem titulo, ficha e fotos herdados
do produto de catalogo do ML e nao aceitam edicao do vendedor. Itens **com** venda
tem o titulo travado pelo ML por regra de plataforma.
