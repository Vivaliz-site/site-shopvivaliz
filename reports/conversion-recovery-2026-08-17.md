# ShopVivaliz — recuperação de conversão

Data: 17/08/2026
Versão: 9.2.106 (`catalog-help-clarity`)

## Diagnóstico de partida

- Google Ads entre 09/07 e 05/08/2026: 311 cliques, 3,98 mil impressões, CTR de 7,83%, custo de R$ 84,17 e nenhuma conversão registrada.
- Catálogo público com 176 produtos, mas sem categorias úteis nas páginas públicas.
- Sitemap com 29 URLs e nenhum produto.
- Feed do Google Merchant disponível, porém sem itens.
- Páginas de produto com descrição fraca, poucas especificações e recomendações pouco relacionadas.
- Checkout com WhatsApp de DDD 11 divergente do contato oficial de DDD 37, cotação de frete vencida reaproveitada e eventos de compra disparados antes do pagamento.
- GA4/GTM carregados em duplicidade e pageview server-side síncrono em páginas públicas.
- Textos institucionais genéricos, sem formulário de contato e com elementos de prova social não comprovados.

## Implementado

### Catálogo, SEO e Google Merchant

- A fonte de itens ativos continua sendo a única autoridade para preço, estoque e disponibilidade.
- Conteúdo editorial existente é associado por SKU/ID apenas para completar descrição, categoria, imagens, marca, GTIN, NCM, garantia e dimensões.
- Categorias ausentes recebem inferência conservadora; contagens inventadas foram removidas.
- Slugs de produto passaram a carregar a identidade do SKU, mantendo compatibilidade com URLs antigas.
- Sitemap e feed Merchant passam a receber produtos ativos enriquecidos.
- Páginas de produto mostram especificações reais e relacionados por categoria/afinidade, sem fallback aleatório.

### Conversão e desempenho

- GTM corrigido para `GTM-PHZ55CP3`; o carregamento direto duplicado do Google tag é removido quando GTM está presente.
- Pageview server-side síncrono tornou-se opt-in e ganhou timeouts de rede.
- `purchase` e conversão de Ads foram removidos da simples criação do pedido; receita só é registrada após pagamento aprovado.
- O webhook continua sendo a fonte canônica de `purchase` no GA4. O retorno aprovado mantém fallback deduplicado para GA4/Ads.
- Páginas de produto anônimas agora aceitam cache público curto sem criar `PHPSESSID`.

### Checkout e atendimento

- WhatsApp centralizado no perfil oficial `+55 37 99937-4112` em checkout e notificações.
- Cotações de frete sem validade ou expiradas são descartadas antes de compor a interface e o payload.
- Removido cronômetro de reserva antes da criação real da reserva de estoque.
- CTA alterado para “Ir para pagamento” e textos explicam a revalidação no servidor.
- Formulário de contato funcional criado com validação, honeypot, mesma origem, limite por IP e envio SMTP ao atendimento.
- Catálogo deixa de refazer a primeira renderização via JavaScript, reduzindo uma chamada à API e eliminando o salto de layout causado por skeletons transitórios.
- Catálogo passa a exibir um seletor de categoria evidente e sincronizado com chips, URL, histórico e navegação móvel.
- Condições fixas de Pix e parcelamento foram removidas do catálogo, carrinho, produto, checkout e e-mails; o gateway confirma as condições antes do pagamento.

### Confiança e conteúdo institucional

- Home, Sobre, Contato, FAQ e Avaliações foram reescritos com linguagem factual e orientada à decisão.
- Notas, depoimentos e promessas não comprovadas foram removidos.
- O falso combo de economia foi removido.
- Avaliações exigem e-mail e pedido; o selo “Compra verificada” só é aplicado após correspondência do pedido e confirmação do pagamento.
- Referência do pedido e metadados internos deixam de ser expostos na API pública de avaliações.
- O endpoint legado da Liz passou a usar somente o catálogo canônico ativo, deixou de compartilhar histórico entre visitantes e não promete preço, cupom, frete grátis ou parcelamento sem fonte.
- A imagem da mascote Liz permanece como botão flutuante no canto inferior direito, com a etiqueta “Dúvidas? Fale com a Liz”.
- Um script legado de WhatsApp sem qualquer referência ativa foi removido; ele continha respostas comerciais fixas e registro local de mensagens.

## Validação

- Teste novo prova que preço e estoque continuam vindo da fonte ativa, enquanto descrição, categoria, imagem e GTIN são enriquecidos por SKU.
- O mesmo teste renderiza o feed Merchant e exige pelo menos um item com GTIN.
- Testes cobrem compra verificada, privacidade das avaliações, frete expirado e ausência de purchase/Ads antes do pagamento.
- O quality gate executa os novos testes com PHP 8.3 e faz lint de todos os arquivos PHP.
- A auditoria isolada de imagens agora cria uma fonte canônica efêmera no runner, sem alterar a regra fail-closed de produção.
- O health gate registra o score e os checks reprovados para tornar falhas pós-deploy diagnosticáveis sem expor segredos.

## Dependência operacional externa

O código está pronto para atribuição, mas o Google Ads só receberá conversão direta se o ambiente seguro tiver `GOOGLE_ADS_CONVERSION_ID`/`GOOGLE_ADS_ID` e `GOOGLE_ADS_CONVERSION_LABEL`, ou se o evento `purchase` do GA4 for importado como conversão principal no Google Ads. Nenhum identificador foi inventado ou versionado.
