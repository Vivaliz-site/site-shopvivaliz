# Correção de vídeos de produto — YouTube Error 153

## Escopo
- Página de produto (`/produto` e `/produto/*`).
- Mantém a política de referenciador segura `strict-origin-when-cross-origin`.
- Intercepta somente o clique da miniatura marcada como vídeo.
- Cria o iframe com `referrerPolicy` explícita antes de definir `src`.
- Acrescenta `playsinline=1` e `origin=<origem atual>` ao embed do YouTube.

## Banco de dados
Nenhuma migration ou alteração SQL é necessária.

## Validação
O teste estático `tests/product-video-embed-fix-static.php` protege os parâmetros e a política necessários para evitar regressão da correção.
