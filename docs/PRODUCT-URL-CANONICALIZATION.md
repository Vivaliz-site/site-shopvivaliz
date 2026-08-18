# Canonicalização de URLs de Produto — Guia para Agentes

Este documento define como tratar URLs históricas/duplicadas de produtos da ShopVivaliz sem reativar produtos antigos ou criar redirects incorretos.

## Regra principal

Nunca redirecione uma URL antiga de produto para home ou catálogo apenas para eliminar um 404 do Search Console.

Um `301` só é permitido quando for possível provar a cadeia:

`slug/URL histórica -> SKU -> produto ativo atual -> slug canônico atual`

Se essa cadeia não puder ser provada, preserve o comportamento normal da página, inclusive `404` quando aplicável.

## Roteamento atual

As URLs `/produto/<slug>` passam por `produto-slug-route.php` antes de chegar a `produto.php`.

O guard tenta, nesta ordem:

1. slug exato do catálogo ativo: renderiza normalmente, sem redirect;
2. SKU único no final do slug histórico: redireciona para o slug ativo;
3. slug encontrado em snapshots editoriais históricos: usa apenas o SKU do snapshot e exige que o SKU exista no catálogo ativo;
4. URL de origem persistida em `olist_products.detail_json`: resolve somente o SKU e exige produto ativo;
5. compatibilidade conservadora com IDs numéricos antigos: remove o sufixo numérico somente quando o slug-base existe exatamente no catálogo ativo;
6. sem correspondência comprovada: delega para `produto.php`, que mantém o 404 normal.

## Snapshots não são fonte comercial

Arquivos como:

- `storage/products-cache.json`
- `api/catalog/fallback-products.json`

podem conter dados históricos e são usados pelo guard somente para identificar `slug -> SKU`.

Nunca use preço, estoque, status, disponibilidade ou condição comercial desses arquivos para decidir se o produto deve voltar à vitrine. A existência no `svcr_products()` atual é obrigatória para qualquer redirect de produto histórico.

## Atribuição de campanhas

Redirects canônicos preservam apenas parâmetros de atribuição allow-listed:

- `gclid`
- `gbraid`
- `wbraid`
- `utm_source`
- `utm_medium`
- `utm_campaign`
- `utm_content`
- `utm_term`
- `utm_id`
- `cupom`

Não copie parâmetros arbitrários para o destino.

## Search Console

Quando a URL Inspection indicar `PAGE_FETCH_NOT_FOUND` para uma URL histórica:

1. confirme se há produto ativo equivalente no catálogo atual;
2. identifique o SKU atual;
3. se o alias histórico puder ser provado pelo mecanismo acima, deixe o guard responder 301;
4. se o produto não estiver ativo, mantenha 404 e deixe o Google removê-lo naturalmente;
5. não use a Indexing API para produtos comuns.

Quando houver `CANONICAL_MISMATCH` em um alias de produto que retorna 200, prefira consolidar o alias com 301 para o slug atual em vez de manter duas páginas 200 concorrentes.

## Segurança operacional

- O guard é read-only em banco de dados.
- Nenhuma credencial deve aparecer em logs.
- Queries históricas retornam apenas SKU.
- Mapeamento ambíguo falha fechado: nenhum redirect.
- Produto não ativo nunca é ressuscitado por snapshot.
- Não transforme o guard em redirect genérico de todo 404.
