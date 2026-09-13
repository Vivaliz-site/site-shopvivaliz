# Product Video Sync — Etapa 1 Design

## Objetivo

Criar a base segura para mapear produtos ativos do ERP Olist/Tiny API v3 aos vídeos já hospedados em `https://shopvivaliz.com.br/uploads/videos-produtos/`, sem publicar em marketplaces nesta etapa.

## Decisões

- O ERP Olist/Tiny API v3 é a fonte autoritativa de produto, ID, SKU, situação e anexos.
- Reutilizar o OAuth rotativo existente do site. Não criar novo token estático nem novo secret.
- Fonte preferida de token: `SHOPVIVALIZ_OLIST_TOKEN_FILE`; em produção, `/home/ubuntu/shopvivaliz-deploy/shared/private/olist-tokens.json`. Variáveis `OLIST_ACCESS_TOKEN`/`TINY_ACCESS_TOKEN` ficam como fallback de runtime.
- A URL pública base é `https://shopvivaliz.com.br/uploads/videos-produtos/`, configurável por `PRODUCT_VIDEO_BASE_URL`.
- Quando executado no servidor do site, inventariar primeiro o diretório local `uploads/videos-produtos`. Se o diretório não estiver disponível, validar URLs candidatas por HTTP.
- Playwright é último fallback e só pode ser habilitado explicitamente quando a API e o inventário do servidor não expuserem a informação necessária.
- Nenhum envio/escrita em Shopee, Mercado Livre, TikTok ou Amazon pertence à Etapa 1.

## Estrutura

`scripts/product_video_sync/` conterá módulos pequenos e independentes:

- `config.py`: configuração e caminhos de runtime.
- `token_provider.py`: leitura segura do token rotativo existente, sem logar valores.
- `tiny_client.py`: cliente read-only da API v3, paginação, timeout, retry e rate limit.
- `video_inventory.py`: inventário e normalização dos arquivos de vídeo hospedados.
- `mapper.py`: correspondência determinística entre produto e vídeo.
- `playwright_fallback.py`: interface opcional de último recurso, desativada por padrão.
- `cli.py`: orquestra a extração e grava `produtos_videos_mapeados.json` atomicamente.

## Fluxo de dados

1. Resolver token do store rotativo existente.
2. Listar produtos ativos (`situacao=A`) com paginação.
3. Para cada produto, obter detalhe/anexos somente quando necessário.
4. Inventariar os arquivos de vídeo existentes.
5. Mapear pela ordem: anexo/referência explícita do ERP → SKU exato → ID Tiny exato → alias manual opcional.
6. Não fazer fuzzy match por nome de produto.
7. Validar que o arquivo/URL corresponde a uma extensão de vídeo aceita e, quando possível, que a URL pública responde com sucesso.
8. Escrever o JSON por arquivo temporário + rename para evitar saída parcial.

## Formato de saída

A saída é uma lista JSON. Cada item preserva os campos pedidos e acrescenta auditoria:

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

Produtos sem correspondência continuam no arquivo com `url_video_completa: null`, `origem_match: null` e `status: "missing"`. Ambiguidades usam `status: "ambiguous"` e nunca são resolvidas por palpite.

## Segurança e robustez

- Somente `GET` na API Tiny na Etapa 1.
- Tokens nunca aparecem em JSON, logs, exceções ou commits.
- `401/403`: falhar fechado com mensagem de autenticação.
- `429` e `5xx`: retry limitado com backoff e respeito a `Retry-After`.
- Timeout de rede configurável e número máximo de retries finito.
- Playwright não é executado silenciosamente.
- Arquivo gerado é dado de runtime e não deve ser versionado.

## Critérios de aceite

- Testes unitários cobrem token provider, paginação, retry, inventário, prioridade de matching, missing/ambiguous e escrita atômica.
- Cliente Tiny é estritamente read-only.
- Nenhum código de marketplace é chamado.
- Em runtime com credenciais válidas, a CLI consegue gerar `produtos_videos_mapeados.json` a partir de produtos ativos.
- Se API/inventário forem insuficientes, o resultado identifica explicitamente a lacuna antes de considerar Playwright.
