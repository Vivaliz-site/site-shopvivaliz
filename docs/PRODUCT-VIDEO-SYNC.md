# Product Video Sync — Etapa 1

Esta etapa mapeia produtos ativos do ERP Olist/Tiny API v3 para vídeos já hospedados em:

`https://shopvivaliz.com.br/uploads/videos-produtos/`

Ela **não publica nem altera** Shopee, Mercado Livre, TikTok ou Amazon.

## Credenciais

Não crie novo token. A rotina reutiliza o OAuth rotativo existente do ShopVivaLiz nesta ordem:

1. `SHOPVIVALIZ_OLIST_TOKEN_FILE`;
2. store padrão de produção `/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json`;
3. `OLIST_ACCESS_TOKEN`;
4. `TINY_ACCESS_TOKEN`.

Valores de credencial nunca são gravados no JSON ou exibidos pela rotina.

## Execução

No diretório raiz do site:

```bash
python3 scripts/map-product-videos.py
```

Saída padrão:

`storage/tiny/produtos_videos_mapeados.json`

Para escolher outro destino:

```bash
python3 scripts/map-product-videos.py --output /tmp/produtos_videos_mapeados.json
```

Para informar um diretório de vídeo diferente:

```bash
python3 scripts/map-product-videos.py --video-dir /caminho/uploads/videos-produtos
```

## Dados consultados no Tiny

A rotina usa exclusivamente leitura na API v3:

- `GET /produtos?situacao=A&limit=...&offset=...` para listar produtos ativos;
- `GET /produtos/{idProduto}` para inspecionar o detalhe/campos do produto;
- `GET /produtos/{idProduto}/anexos` para obter a lista oficial de anexos e imagens (`id`, `url`, `externo`).

## Matching

A ordem é deliberadamente determinística:

1. referência de vídeo encontrada no detalhe ou no endpoint oficial de anexos do produto;
2. nome de arquivo com stem igual ao SKU;
3. nome de arquivo com stem igual ao ID Tiny;
4. alias manual opcional.

Não existe fuzzy match por nome/descrição. Se mais de um arquivo puder corresponder, o item fica `ambiguous` e nenhuma URL é escolhida automaticamente.

## Aliases opcionais

Arquivo JSON:

```json
{
  "SKU-123": "video-especial.mp4",
  "987654321": "outro-video.mp4"
}
```

Uso:

```bash
python3 scripts/map-product-videos.py --aliases /caminho/aliases.json
```

## Estrutura do JSON

```json
[
  {
    "id_tiny": "123456",
    "sku": "ABC-123",
    "url_video_completa": "https://shopvivaliz.com.br/uploads/videos-produtos/ABC-123.mp4",
    "arquivo_video": "ABC-123.mp4",
    "origem_match": "sku",
    "status": "mapped"
  }
]
```

Status possíveis: `mapped`, `missing`, `ambiguous`.

## Robustez

- Somente chamadas GET na API Tiny nesta etapa.
- Produtos filtrados com `situacao=A`.
- Paginação por `limit/offset` e proteção contra página repetida.
- Retry finito para `429`/`5xx`.
- Em `429`, respeita primeiro `Retry-After` e depois `X-RateLimit-Reset` quando disponível.
- `401`/`403` falham imediatamente.
- O JSON é escrito de forma atômica para evitar arquivo parcial.
- `requests` e `python-dotenv` já pertencem ao `requirements.txt` do projeto.

## Playwright

Playwright é somente último recurso. `PRODUCT_VIDEO_ALLOW_PLAYWRIGHT=0` é o padrão. A rotina normal não importa nem abre navegador; um resolver Playwright só deve ser conectado quando houver evidência de que a API v3 e o inventário do servidor não disponibilizam a informação necessária.

## Testes

```bash
pytest -q tests/test_product_video_sync_*.py
python3 -m compileall -q scripts/product_video_sync scripts/map-product-videos.py
python3 scripts/map-product-videos.py --help
```
