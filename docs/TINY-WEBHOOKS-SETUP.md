# Webhooks de preco/estoque/produto - Olist/Tiny

## Integracao operacional

A fonte de verdade e a API v3 do Olist/Tiny. Os eventos devem chegar pela **integracao API do ERP** (nao pelo aplicativo generico Webhooks):

`Integrações -> API do ERP -> Gerenciar -> Notificações -> URLs de notificações`

Para as notificacoes **envio dos precos**, **envio de produtos** e **estoque**, utilizar o receptor unico:

`https://shopvivaliz.com.br/api/olist/webhook.php?key=<SEGREDO_CONFIGURADO>`

O valor de `<SEGREDO_CONFIGURADO>` e o segredo dedicado `OLIST_WEBHOOK_SECRET` ou `TINY_WEBHOOK_SECRET` ja provisionado em armazenamento protegido, nunca uma chave do agente. Se a integracao permitir cabecalho personalizado, utilizar `X-Webhook-Key` em vez da query. O segredo nao deve ser publicado em Git, chats, prints ou logs. Garantir mascaramento da query nos access logs antes de usar autenticacao por URL.

O receptor aceita os eventos oficiais `tipo=produto`, `tipo=precos` e `tipo=estoque` e recebe a identidade `idEcommerce` da integracao. Para precos, usa `dados.idProduto` ou `dados.codigo/sku` apenas como referencia, consulta `GET /public-api/v3/produtos/{id}` e atualiza **somente** o cache derivado. Nunca grava um preco enviado pelo payload sem consultar o ERP.

Nao usar os receptores antigos `/olist/webhook-receiver.php?event=price`, `/api/olist/webhook-receiver.php?event=price` ou `/api/webhooks/tiny-product-price-sync.php` para configurar novas notificacoes. Eram caminhos legados e nao sao a fonte unica canonica.

## Evidencias obrigatorias

1. Confirmar no painel ERP que a URL **envio dos precos** esta cadastrada na integracao correta. Conferir tambem produtos/estoque.
2. Executar o teste de notificacao do **proprio provedor** sem criar pedido nem alterar preco comercial para teste.
3. Conferir HTTP **200** (o ERP pode remover a URL apos erros consecutivos), tipo recebido, evento `price_synced` em `logs/olist-erp-webhook.log` e timestamp.
4. Conferir imediatamente o read-back de `/api/catalog/price-by-product.php?sku=<SKU>`, a pagina de produto e o catalogo.
5. Conferir o preco com consulta autenticada ao ERP v3; o cache e sempre derivado.
6. Se nao houver notificacoes reais, registrar `WEBHOOK_NOT_VERIFIED`, sem confundir com a reconciliacao `shopvivaliz-catalog-reconcile.timer`.

Nao simular recebimento do ERP com HTTP local ou teste unitario. A reconciliacao de 30 minutos e fallback de deriva, nao a rotina principal de atualizacao.

## Diagnostico de 27/10/2026

No host Marina, nao havia POST para receptores de webhook Olist/Tiny nos access logs HTTP retidos de 18 a 27/10/2026, embora o catalogo recebesse atualizacao pelo timer de reconciliacao. Os segredos de webhook estavam configurados no runtime. A configuracao atual da URL no painel Olist/Tiny **nao foi verificada**, portanto a integracao permanece `WEBHOOK_NOT_VERIFIED` ate existir evento oficial comprovado.
