# Referência da API Tiny/Olist ERP v3

> Compilado a partir da documentação oficial (https://api-docs.erp.olist.com) em 2026-07-17.
> Mantido aqui para consulta rápida sem depender de acesso à internet durante o desenvolvimento.
> Índice completo de endpoints: https://api-docs.erp.olist.com/llms.txt

## Autenticação (OAuth2)

- **Token endpoint:** `https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token`
- **Grant types:** `authorization_code` (autorização inicial, via navegador) e `refresh_token` (renovação)
- **Parâmetros do refresh:** `grant_type=refresh_token`, `client_id`, `client_secret`, `refresh_token`
- **Expiração do access_token:** 4 horas
- **Expiração do refresh_token:** 24 horas — depois disso é necessário reautorizar via navegador (não dá pra automatizar)
- Header de autenticação: `Authorization: Bearer {access_token}`

⚠️ **Risco conhecido no projeto:** o refresh_token expira em 24h. Múltiplos sistemas (GitHub Actions
`sync-olist-6h.yml`, VM local, outros workflows) tentam renovar o MESMO refresh_token de forma
independente — cada renovação bem-sucedida invalida o token anterior (rotação). Se dois processos
tentarem renovar quase ao mesmo tempo, um deles falha com `"Token is not active"` (já aconteceu e
causou o bug crítico de estoque zerado corrigido em 2026-07-17, ver CHANGELOG.md). Idealmente
deveria haver uma única fonte de verdade para o token, não múltiplos consumidores renovando de
forma independente.

## Rate limit

- Limite é **por conta**, não por aplicativo — todos os apps ativos na mesma conta Tiny compartilham o mesmo limite.
- Limite exato: consultar `https://erp.olist.com` (não documentado numericamente na doc pública) — na prática o projeto usa ~60 req/min como referência empírica (`olist/sync-products.php`).
- Headers de resposta: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.
- Ao estourar o limite: retorna erro (HTTP 429 na prática, confirmado empiricamente pelo projeto) e é necessário esperar o reset.

## POST /pedidos — Criar pedido

Endpoint real: `https://api.tiny.com.br/public-api/v3/pedidos`

Campos principais do corpo (schema `CriarPedidoModelRequest`):

| Campo | Tipo | Obs |
|---|---|---|
| `situacao` | integer (enum: 0,1,2,3,4,5,6,7,8,9) | **Precisa ser inteiro puro, não objeto `{id: N}`** — bug corrigido no projeto em 2026-07 |
| `data` | string (date) | |
| `dataPrevista` | string (date) | |
| `numeroOrdemCompra` | string | |
| `valorDesconto` | float | |
| `valorFrete` | float | |
| `idContato` | integer | **Referencia um contato JÁ EXISTENTE** — a API não aceita cliente inline no corpo do pedido, precisa criar/buscar o contato antes via `/contatos` |
| `listaPreco.id`, `naturezaOperacao.id`, `vendedor.id`, `deposito.id`, `intermediador.id` | objetos com `id` | opcionais — só obrigatórios internamente SE o objeto pai for enviado |
| `transportador` | objeto | detalhes de transportadora/frete |
| `ecommerce.id`, `ecommerce.numeroPedidoEcommerce` | | usado para vincular pedido ao pedido do e-commerce |
| `enderecoEntrega` | objeto | endereço completo de entrega |
| `consumidorFinal` | objeto | CPF/CNPJ + flag de consumidor final |
| `pagamento` | objeto | forma/meio de pagamento |
| `itens` | array de `ItemPedidoRequestModel` | ver abaixo |
| `numeroPedido` | string | **confirmado empiricamente que aceita string alfanumérica** (ex: `SV20260715071130912`), não precisa ser só número |

### Item do pedido (`ItemPedidoRequestModel`)

| Campo | Tipo | Obs |
|---|---|---|
| `produto.id` | integer | **ID do produto no Tiny** (não é o SKU) — bug corrigido no projeto (antes mandava `codigo`/`descricao`/`valor`) |
| `quantidade` | float | |
| `valorUnitario` | float | |

### Resposta de GET /pedidos/{id} (`ObterPedidoModelResponse`)

Campos principais: `id`, `numeroPedido`, `idNotaFiscal`, `data`, `dataEntrega`, `dataPrevista`, `dataEnvio`,
`valorTotalProdutos`, `valorTotalPedido`, `valorDesconto`, `valorFrete`, `situacao`, `cliente`,
`enderecoEntrega`, `itens[]` (cada item com `produto.{id,sku,descricao,tipo}`, `quantidade`, `valorUnitario`).

## POST /contatos — Criar contato

Endpoint: `https://api.tiny.com.br/public-api/v3/contatos`

Campos principais (`CriarContatoModelRequest`): `nome`, `codigo`, `fantasia`, `tipoPessoa` (enum `J`/`F`/`E`/`X`),
`cpfCnpj`, `telefone`, `celular`, `email`, `endereco.{endereco,numero,complemento,bairro,municipio,cep,uf,pais}`,
`situacao` (enum `B`/`A`/`I`/`E`), `vendedor.id`, `tipos[]` (array de inteiros).

**`tipoPessoa`**: `F` = Física, `J` = Jurídica — bug corrigido no projeto (estava sempre mandando `F` mesmo para CNPJ de 14 dígitos).

## GET /contatos — Buscar/listar contatos

Parâmetro `cpfCnpj` (string, opcional) — busca por CPF ou CNPJ. **A documentação oficial não especifica o
formato esperado.** Confirmado empiricamente pelo projeto: **precisa estar formatado com pontuação**
(`013.669.956-19`), passar só dígitos retorna lista vazia mesmo quando o contato existe. Isso não está
documentado oficialmente — é uma descoberta empírica que deve ser preservada aqui.

## GET /estoque/{idProduto} — Estoque de um produto (endpoint dedicado)

**Diferente do que o projeto usa hoje** (`GET /produtos/{id}` lendo `estoque.quantidade`), existe um endpoint
dedicado de estoque com campos mais precisos:

| Campo | Tipo | Obs |
|---|---|---|
| `saldo` | float | saldo físico total |
| `reservado` | float | quantidade reservada (ex: por outros pedidos pendentes) |
| `disponivel` | float | **saldo real vendável = saldo - reservado** |
| `depositos[]` | array | breakdown por depósito, cada um com `saldo`/`reservado`/`disponivel` próprios |

⚠️ **Possível melhoria futura identificada mas NÃO implementada ainda**: `olist/sync-products.php` usa
`estoque.quantidade` do endpoint `/produtos/{id}` (que é o saldo bruto, sem descontar reservas). Se a Tiny
tiver produtos com unidades reservadas por outros pedidos/canais, o catálogo do site pode mostrar estoque
disponível para venda que na verdade já está comprometido. O endpoint dedicado `/estoque/{idProduto}` com o
campo `disponivel` seria mais correto, mas dobraria o número de chamadas por produto no sync (já historicamente
perto do rate limit, ver `logs/olist-sync.log` com vários `HTTP 429`). Avaliar com cautela antes de mudar.

## Webhooks

- Ativação: app "Webhooks" (disponibilidade depende do plano) → Configurações → Geral → Outras Configurações → Webhooks.
- Eventos disponíveis: vendas (pedido criado/alterado), expedição (pedido "enviado"), estoque (mudança de saldo), notas fiscais autorizadas.
- Entrega: espera HTTP 200 de confirmação; se não receber, reenvia com até 10 tentativas, aumentando 5 min de intervalo a cada uma.
- **Não documentado publicamente:** formato exato do payload JSON e mecanismo de assinatura/validação de segurança.
- O projeto tem `api/tiny/stock-webhook.php` e o workflow `sync-stock-tiny.yml` (roda 1x/dia como rede de segurança, não como mecanismo primário) — não confirmei se o webhook real da Tiny está configurado e ativo no painel.

## Changelog da API (versões documentadas)

3.1, 3.1.1 até 3.1.5 — não detalhado aqui; consultar https://api-docs.erp.olist.com/changelog/ se precisar
saber o que mudou entre versões específicas.

## Índice completo de endpoints por categoria

Ver `https://api-docs.erp.olist.com/llms.txt` para a lista completa (produtos, notas fiscais, ordem de compra,
ordem de serviço, CRM, contas a pagar/receber, expedição, separação, listas de preço, etc.) — só os endpoints
relevantes ao fluxo real do projeto (pedidos, contatos, produtos, estoque, autenticação, webhooks) foram
documentados em detalhe acima.
