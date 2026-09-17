# Product Video Dual-Source Marketplace Design

## Goal
Preservar para cada produto duas fontes de video independentes: o arquivo direto hospedado pela ShopVivaliz e uma copia no YouTube, sem substituir uma pela outra, e permitir que cada marketplace utilize apenas o formato aceito pelo canal.

## Architecture
O ERP Olist/Tiny continua sendo a fonte primaria do video cadastrado em `seo.linkVideo`. O cache publico preserva esse valor em `video_url` e passa a complementar, quando existir, um `youtube_url` vindo de um registro persistente do storefront. O site prefere o MP4 direto e usa YouTube como fallback.

Uma camada `ProductVideoSources` concentra leitura, gravacao e normalizacao das duas fontes. A persistencia complementar fica em `storage/product-video-sources.json`, que no deploy aponta para armazenamento compartilhado e nao substitui dados do ERP.

## Distribution policy
Cada canal recebe uma estrategia explicita: site aceita MP4 e YouTube; YouTube recebe upload do arquivo direto; marketplaces somente tentam publicacao quando houver endpoint suportado pelo publisher do canal. Falha em um canal nao remove nem altera as fontes dos demais.

## YouTube publication
O uploader usa YouTube Data API v3 com OAuth e upload resumable do MP4. O refresh token precisa conter escopo `https://www.googleapis.com/auth/youtube.upload`. Credenciais existentes nunca sao impressas em logs. A resposta persistida contem somente video ID, URL publica, horario e status.

Se o OAuth atual nao possuir o escopo YouTube, a automacao reporta `youtube_scope_missing` sem alterar o ERP ou o site. A autorizacao adicional pode ser feita uma unica vez e depois o mesmo fluxo passa a ser automatico.

## Marketplace publication state
Os resultados de cada tentativa usam a tabela existente `catalog_publications`, com `publication_type=video`, `channel`, `operation`, `external_id`, `http_status`, `response_json` sanitizado e `verified_at`. Isso evita criar uma segunda fonte de estado operacional.

## Validation
Testes cobrem normalizacao MP4/YouTube, merge das fontes no cache, persistencia atomica, selecao de fonte por canal e falhas de OAuth. Em producao, validar o SKU 35039: seis imagens, MP4 direto acessivel, miniatura de video na PDP e reconciliador ativo. Upload YouTube so e considerado concluido apos read-back do `videos.list` para o ID publicado.
