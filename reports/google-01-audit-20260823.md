# GOOGLE-01 — Auditoria Google, feeds e zero vendas

Data: 2026-08-23

## Evidências confirmadas

- Google Shopping Feed público validado em produção: 175 itens e 175 imagens.
- Google Merchant Feed público validado em produção: 175 itens e 175 imagens.
- As imagens de produto aceitas no feed vêm do domínio canônico ERP/Tiny (https://s3.amazonaws.com/tiny-anexos-*) ou do próprio site quando aplicável a páginas, sem fallback manual/local para cadastro de produto.
- Catálogo público validado anteriormente com 162 produtos disponíveis para compra, sem sobreposição de paginação após correção de offset.
- Product/Offer JSON-LD presente em página de produto validada.
- purchase não deve disparar na simples criação do pedido; compra/conversão deve nascer após pagamento aprovado.

## Correção aplicada neste bloco

O feed Merchant/Shopping estava respondendo 200, mas vazio, porque a validação de URL do feed aceitava apenas imagens no domínio shopvivaliz.com.br. Como a regra correta é ERP/Tiny como fonte canônica de mídia de produto, URLs oficiais do Tiny S3 estavam sendo rejeitadas.

Correção: permitir estritamente imagens HTTPS de s3.amazonaws.com somente quando o path começa com /tiny-anexos-, mantendo bloqueio para imagens externas genéricas, snapshots, placeholders e fallbacks locais.

## Diagnóstico de zero vendas

Evidência histórica do projeto indicava tráfego pago com cliques e zero conversões antes das correções. As causas técnicas mais prováveis eram combinadas:

1. Feed Merchant vazio ou incompleto, reduzindo qualidade/diagnóstico de produtos nos módulos Google.
2. Problemas anteriores em sitemap/indexação e URLs antigas.
3. Catálogo com risco de itens sem estoque/inativos e paginação incorreta antes dos reparos.
4. Evento purchase/Ads precisava ser restrito ao pagamento aprovado, evitando conversão falsa e melhorando atribuição.
5. UX de carrinho, frete, confiança e conteúdo público tinha ruídos já corrigidos em etapas anteriores.

## Lacunas que ainda exigem painel autenticado

- Status final dos itens no Google Merchant Center após recrawl do feed.
- Diagnósticos atuais do Search Console depois de nova leitura do sitemap.
- Métricas atuais de Google Ads por campanha se a API/UI não estiver acessível.
- Importação final de compra GA4 como conversão principal no Google Ads, se não estiver confirmada pelo painel.

## Critério de aceite

- Feeds públicos não vazios, com pelo menos 1 item e paridade básica de item/image_link.
- Feed aceita mídia oficial ERP/Tiny S3 e continua bloqueando imagens externas genéricas.
- Checklist registra GOOGLE-01 como concluído apenas para correções verificáveis no site/repo, mantendo lacunas autenticadas separadas.
