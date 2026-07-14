# Auditoria E2E real do site inteiro

Data de inicio: 2026-07-14
Branch: `audit/full-site-e2e-2026-07-14`

## Regra de conclusao

Nenhum item sera marcado como aprovado apenas por existir no codigo. Cada fluxo precisa de evidencia executavel: resposta HTTP, teste automatizado, transacao sandbox/real controlada, webhook recebido e atualizacao persistida.

## Superficies obrigatorias

- Home, catalogo, busca, categorias e produto
- Carrinho, calculo de frete, CEP e Melhor Envio
- Checkout responsivo e validacao de campos
- Pagar.me: cartao, PIX, boleto, idempotencia, recusas, estornos e webhook
- Mercado Pago: cartao, PIX, boleto, idempotencia, recusas, estornos e webhook
- Criacao de pedido e sincronizacao Olist/Tiny
- Login comum, cadastro, recuperacao de senha e Google OAuth
- Area do cliente e historico de pedidos
- Emails transacionais
- Webhooks, filas, retries e logs
- Mobile Safari/Chrome e desktop
- Acessibilidade, seguranca, desempenho e SEO tecnico
- Rotas 404/500, mensagens ao cliente e ausencia de dados internos

## Evidencias exigidas por fluxo

1. Caso feliz.
2. Falha prevista.
3. Repeticao/idempotencia.
4. Persistencia no banco/arquivo oficial.
5. Integracao externa confirmada.
6. Evidencia de webhook quando aplicavel.
7. Teste automatizado para impedir regressao.

## Problemas ja confirmados

- Checkout atual registra pedido, mas nao executa cobranca online.
- Cartao nao esta disponivel no checkout.
- PIX e boleto atuais sao tratados como confirmacao manual.
- CEP depende apenas de `blur`, ignora erros e pode falhar em mobile.
- Google OAuth fica desabilitado quando as credenciais esperadas nao estao carregadas.

## Estado

Em andamento. Nao publicar como concluido ate todos os fluxos criticos possuirem evidencia real.