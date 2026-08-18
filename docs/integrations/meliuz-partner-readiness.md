# Méliuz Business — Partner Readiness

Data: 2026-08-15
Branch: `feat/meliuz-partner-readiness`
Issue: #993

## Objetivo
Preparar a ShopVivaliz para integração como loja parceira do Méliuz sem publicar promessa de cashback antes da homologação e sem alterar preços ou orçamento de mídia.

## O que foi confirmado publicamente
A página oficial do Méliuz Business para e-commerce descreve o fluxo de parceria: o usuário acessa o ecossistema Méliuz, seleciona a loja e ativa o cashback, é redirecionado para o e-commerce, conclui a compra normalmente e recebe a recompensa após validação.

## O que NÃO está publicamente especificado
Não foi localizada documentação técnica pública suficiente para implementar com segurança:

- nome/formato do identificador de clique;
- parâmetros obrigatórios no redirect;
- duração da janela de atribuição;
- modelo de first/last click;
- pixel, postback ou API oficial;
- autenticação e secrets;
- payload de compra aprovada;
- payload de cancelamento, devolução e estorno;
- regras de deduplicação/idempotência;
- tratamento de frete/impostos/descontos na base comissionável;
- compatibilidade com cupons e com o desconto automático de 3% da ShopVivaliz;
- ambiente e casos de homologação.

## Ação externa executada
Foi enviada solicitação formal ao Méliuz pedindo onboarding comercial e documentação técnica oficial, incluindo regras de cupons, atribuição, postback/API, cancelamentos/estornos e homologação.

## Guardrails técnicos
Até receber a documentação oficial:

1. Não adicionar pixel/script de origem não documentada.
2. Não inventar parâmetro de clique (`click_id`, `transaction_id`, etc.).
3. Não criar cookie de atribuição com janela arbitrária.
4. Não enviar pedidos/clientes para endpoint não homologado.
5. Não registrar secret em código, git, logs ou frontend.
6. Não mostrar percentual de cashback Méliuz no storefront.
7. Não alterar a oferta automática de 3% do carrinho sem regra comercial explícita.
8. Não alterar preço de produto nem orçamento de mídia.

## Plano técnico pronto para execução após homologação
Quando o Méliuz fornecer a especificação oficial:

1. Criar adaptador de atribuição isolado em `includes/` e JS mínimo somente se exigido.
2. Persistir apenas o identificador de atribuição necessário, sem PII adicional.
3. Anexar a atribuição ao pedido no backend, com validação e limite de tamanho.
4. Disparar confirmação somente após pagamento aprovado, nunca na criação do pedido.
5. Implementar cancelamento/estorno idempotente a partir dos webhooks de pagamento/pedido.
6. Proteger postback/API com timeout, retry limitado, logs sanitizados e fail-open para o checkout.
7. Adicionar health/readiness endpoint que não exponha secrets.
8. Criar testes para compra aprovada, duplicidade, cancelamento, estorno, cupom e oferta de 3%.
9. Executar compra de homologação antes de ativar qualquer claim público.
10. Só então habilitar a integração por feature flag/configuração de produção.

## Critério de liberação
A integração só pode ser marcada como ativa quando houver:

- aprovação comercial da loja;
- taxa/regra de cashback definida;
- documentação oficial recebida;
- credenciais/secrets provisionados em armazenamento seguro;
- tracking e pós-venda homologados;
- compra de teste atribuída;
- cancelamento/estorno testados;
- smoke de produção saudável.

## Estado
`BLOCKED_EXTERNAL`: aguardando retorno comercial/técnico do Méliuz.
