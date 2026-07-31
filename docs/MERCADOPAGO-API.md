# Mercado Pago API — Referência de endpoints

> Base URL real (confirmada em produção, `includes/mercadopago-gateway.php`):
> `https://api.mercadopago.com` — **não** `https://mercadopago.com`.
> Headers obrigatórios: `Authorization: Bearer <ACCESS_TOKEN>`, `Content-Type: application/json`.

## Uso real neste repo (confirmado no código)

| Endpoint | Onde é usado |
|---|---|
| `POST /checkout/preferences` | `includes/mercadopago-gateway.php::svmp_create_preference()` — cria a preferência de pagamento (Checkout Pro) e gera o `init_point`/`checkout_url` |
| `GET /v1/payments/{id}` | `api/webhook-mercadopago.php` — consulta detalhe do pagamento ao receber notificação |
| `GET /v1/orders/{id}` | `api/webhook-mercadopago.php` — mesma finalidade, quando o webhook referencia uma order agrupada em vez de um payment direto |

O checkout deste site usa **Checkout Pro** (`/checkout/preferences` + redirecionamento pro `init_point`) — não Checkout Transparente (`/v1/payments` direto com tokenização de cartão no front). Boleto é emitido via `api/mercadopago/create-boleto.php`, que também passa pela mesma preferência.

## Índice completo de endpoints (referência, nem tudo implementado aqui)

### 1. Checkout Pro (Preferências de Pagamento)
- `POST /checkout/preferences` — Criar preferência de pagamento (gera o `init_point`) — **usado**
- `GET /checkout/preferences/{id}` — Obter detalhes de uma preferência criada
- `PUT /checkout/preferences/{id}` — Atualizar itens, valores ou configurações de uma preferência
- `GET /checkout/preferences/search` — Buscar e listar preferências do histórico

### 2. Checkout Transparente (API de Pagamentos Diretos)
- `POST /v1/payments` — Criar pagamento (processa PIX, Boleto ou Token de Cartão)
- `GET /v1/payments/{id}` — Obter detalhes e status em tempo real de uma transação — **usado**
- `PUT /v1/payments/{id}` — Atualizar status do pagamento (cancelar pendente / capturar retido)
- `GET /v1/payments/search` — Buscar e filtrar histórico de transações da conta

### 3. Pós-venda (Cancelamentos e Reembolsos)
- `PUT /v1/payments/{id}` — Cancelar transação pendente (enviando `{"status": "cancelled"}`)
- `POST /v1/payments/{id}/refunds` — Criar reembolso total (corpo vazio) ou parcial (`{"amount": X}`)
- `GET /v1/payments/{id}/refunds` — Listar todos os reembolsos vinculados a um pagamento
- `GET /v1/payments/{id}/refunds/{id}` — Obter dados de um reembolso específico pelo ID do estorno

### 4. Nova API de Pedidos (Orders — Transparente Atualizado)
- `POST /v1/orders` — Criar pedido comercial (agrupa múltiplos produtos)
- `GET /v1/orders/{id}` — Obter andamento e informações do pedido agrupado — **usado**
- `PUT /v1/orders/{id}` — Atualizar dados comerciais ou itens do carrinho
- `POST /v1/orders/{id}/payments` — Adicionar uma tentativa de pagamento ao pedido criado

### 5. Clientes e cartões salvos (One-Click Buy)
- `POST /v1/customers` — Criar um perfil de comprador
- `GET /v1/customers/{id}` — Obter dados cadastrais do cliente
- `PUT /v1/customers/{id}` — Atualizar informações da ficha do comprador
- `GET /v1/customers/search` — Localizar ID de um comprador por filtros (ex: e-mail)
- `POST /v1/customers/{c_id}/cards` — Salvar cartão permanentemente na conta do cliente
- `GET /v1/customers/{c_id}/cards` — Listar cartões salvos do cliente
- `GET /v1/customers/{c_id}/cards/{id}` — Obter dados específicos de um cartão armazenado
- `DELETE /v1/customers/{c_id}/cards/{id}` — Remover cartão salvo da conta do cliente
- `POST /v1/customers/{id}/addresses` — Adicionar endereço de entrega ao perfil do cliente
- `GET /v1/customers/{id}/addresses` — Listar endereços cadastrados do comprador

### 6. Segurança no front-end (Tokenização)
- `POST /v1/card_tokens` — Criar token de cartão encriptado temporário (usado no front-end)
- `GET /v1/card_tokens/{id}` — Obter propriedades de um token ativo

### 7. Webhooks e notificações (IPN)
- `POST /v1/notifications` — Configurar URL do servidor para escutar mudanças de status
- `GET /v1/notifications/{id}` — Consultar conteúdo e validar uma notificação recebida
- `POST /v1/webhooks` — Inscrição moderna em tópicos específicos de eventos (ex: `payment`, `order`)

Este projeto recebe webhooks em `api/webhook-mercadopago.php` — ver `docs/MEMORIA-AGENTES.md` para o histórico de bugs de assinatura (`MERCADOPAGO_WEBHOOK_SECRET`).

### 8. Configurações globais
- `GET /v1/payment_methods` — Lista de meios de pagamento aceitos e limites por país
- `GET /v1/identification_types` — Tipos de documentos de identificação válidos (ex: CPF, CNPJ)

### 9. Assinaturas e recorrência (Pre-approvals)
- `POST /v1/preapproval_plan` — Criar plano de assinatura (define valor e periodicidade)
- `GET /v1/preapproval_plan/{id}` — Obter regras e termos de um plano ativo
- `PUT /v1/preapproval_plan/{id}` — Atualizar valores ou regras de cobranças futuras do plano
- `GET /v1/preapproval_plan/search` — Buscar planos criados no painel
- `POST /v1/preapprovals` — Criar assinatura (vincula um cliente a um plano)
- `GET /v1/preapprovals/{id}` — Obter status atual da assinatura do cliente
- `PUT /v1/preapprovals/{id}` — Atualizar assinatura (pausar, cancelar ou reativar)
- `GET /v1/preapprovals/search` — Buscar e filtrar assinaturas ativas ou inadimplentes

Não usado neste projeto (sem produtos recorrentes/assinatura no catálogo atual).

### 10. Marketplace e split de pagamento
- `POST /v1/marketplace/users` — Vincular conta de vendedor parceiro via OAuth 2.0
- Split do dinheiro é feito passando `application_fee` ou `producers` dentro do POST de pagamentos/preferências padrão — não usado (loja própria, sem marketplace de terceiros)

### 11. Relatórios e conciliação financeira
- `POST /v1/account/settlement_report` — Solicitar geração de relatório com dinheiro liberado para saque
- `GET /v1/account/settlement_report` — Listar relatórios financeiros gerados disponíveis
- `GET /v1/account/settlement_report/{id}` — Baixar o arquivo de relatório em formato CSV/XLSX

## Como redescobrir/testar

```bash
# Criar uma preferência de teste (usa o mesmo token de produção do .env)
curl -s -X POST https://api.mercadopago.com/checkout/preferences \
  -H "Authorization: Bearer $MERCADOPAGO_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items":[{"id":"test","title":"Teste","quantity":1,"currency_id":"BRL","unit_price":1.0}]}'

# Consultar um pagamento
curl -s https://api.mercadopago.com/v1/payments/{id} \
  -H "Authorization: Bearer $MERCADOPAGO_ACCESS_TOKEN"
```
