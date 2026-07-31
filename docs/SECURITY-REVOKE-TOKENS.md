# Guia de revogação de credenciais expostas

## Ação imediata

Qualquer credencial publicada em código, documentação, screenshot, issue, artifact ou histórico Git deve ser considerada comprometida.

## Categorias identificadas

- integrações de redes sociais;
- Cloudflare e DNS;
- Olist/Tiny;
- Mercado Pago;
- Shopee;
- TikTok Shop;
- Melhor Envio;
- Supabase e banco;
- endpoints MCP/agent;
- secrets gerados em artifacts de build.

Nenhum valor, prefixo identificável ou fragmento de token deve ser mantido neste guia.

## Procedimento

1. Revogue o valor no provedor.
2. Gere uma credencial nova com o menor escopo possível.
3. Armazene-a apenas em secret protegido.
4. Atualize a aplicação sem imprimir o valor.
5. Execute teste real com request ID redigido e read-back.
6. Confirme que logs e artifacts não contêm dados sensíveis.
7. Planeje a reescrita do histórico depois da revogação.
8. Avise colaboradores antes de force-push coordenado.

## Evidência aceitável

- identificador da credencial ou integração, nunca o valor;
- timestamp de revogação;
- provedor;
- escopo novo;
- run de validação;
- artifact redigido;
- responsável pela confirmação.

Marcar um item como revogado exige evidência do provedor. A sanitização do repositório, isoladamente, não comprova revogação.
