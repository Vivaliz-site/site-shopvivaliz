# Melhor Envio API — Webhooks e regras gerais

> Fontes oficiais: https://docs.melhorenvio.com.br/docs/webhooks e
> https://docs.melhorenvio.com.br/reference/introducao-api-melhor-envio
> Implementação neste repo: `api/melhorenvio/webhook.php`, `includes/melhorenvio-label.php`, `includes/melhorenvio-oauth.php`

## Regras gerais da API (introdução)

- **Base URL produção:** `https://melhorenvio.com.br` (a API roda em `/api/v2/...` sobre esse domínio —
  ver `includes/melhorenvio-label.php::me_api_base()`, que já resolve produção vs sandbox).
- **Base URL sandbox:** `https://sandbox.melhorenvio.com.br` — **ambiente isolado**, credenciais e conta
  são diferentes das de produção, não dá pra usar as mesmas em ambos. Sandbox só tem Correios e Jadlog
  disponíveis pra teste.
- **Auth:** OAuth2. `access_token` expira em 30 dias, `refresh_token` em 45 dias — precisa renovar antes disso.
- **Headers obrigatórios em toda requisição:** `Accept: application/json`, `Content-Type: application/json`,
  e um `User-Agent` que identifique a aplicação **e um e-mail de contato** (ex: `"ShopVivaliz (contato@shopvivaliz.com.br)"`).
  Rotas de OAuth2 (login/token) têm requisitos de header diferentes.
- Todas as requisições devem ser HTTPS. Payloads (fora GET) vão sempre no corpo como JSON.
- Integração é gratuita, sem taxas de uso da API em si (só o custo real do frete).

⚠️ **Conformidade de `User-Agent` neste repo — parcial.** `includes/melhorenvio-label.php` já segue o
formato exigido (`'User-Agent: ShopVivaliz (contato@shopvivaliz.com.br)'`), mas `api/melhorenvio/shipping-check.php`
(`ShopVivaliz-ShippingCheck/1.0`) e `api/melhorenvio/shipping-check-v2.php` (`ShopVivaliz/Shipping-v2`) usam um
formato sem e-mail de contato, fora do padrão pedido pela doc oficial. Não corrigido nesta doc — só registrado
como achado, já que `shipping-check-v2.php` é o endpoint realmente ativo hoje (chamado por `js/cart-shipping-v7.js`
e `checkout.php`).

## Webhooks — como funciona

Os webhooks notificam atualizações do ciclo de vida de uma etiqueta gerada pelo Melhor Envio. **Só chegam eventos de etiquetas geradas pelo mesmo aplicativo (client_id) onde o webhook foi cadastrado** — etiquetas criadas manualmente pelo site do Melhor Envio ou por outro app, mesmo na mesma conta, não disparam o webhook.

### Autenticidade — header `X-ME-Signature`

Toda requisição inclui `x-me-signature`: HMAC-SHA256 do corpo bruto da requisição, usando o **client secret do aplicativo** como chave, codificado em base64.

✅ **Já implementado corretamente** em `api/melhorenvio/webhook.php::me_validate_signature()`:
```php
$expected = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
return hash_equals($expected, $signature);
```
O secret é lido de `MELHORENVIO_WEBHOOK_SECRET` (ou fallback `MELHORENVIO_CLIENT_SECRET`/`MELHORENVIO_CLIENTE_SECRET`). Aceita o header tanto como `X-ME-Signature` quanto `X-Signature` (fallback), com ou sem prefixo `sha256=`.

### Retentativas

Se o endpoint não responder com sucesso em até 6 segundos, o Melhor Envio tenta de novo após 15 minutos, até 5 tentativas totais. Depois disso a notificação é descartada — **não fica em fila para reenvio manual**.

### Cadastro do endpoint

Painel Melhor Envio → `Integrações → Área Dev` → selecionar o aplicativo → `Novo Webhook` → informar a URL pública (ex: `https://shopvivaliz.com.br/api/melhorenvio/webhook.php`). Só é possível cadastrar depois de ter um aplicativo criado.

## Eventos (todos com prefixo `order.`)

| Evento | Disparado quando |
|---|---|
| `order.created` | Etiqueta é criada |
| `order.pending` | Etiqueta é retornada para o carrinho |
| `order.released` | Etiqueta é paga |
| `order.generated` | Etiqueta é gerada |
| `order.received` | Encomenda é recebida em um ponto de distribuição Pegaki |
| `order.posted` | Encomenda é postada |
| `order.delivered` | Encomenda é entregue |
| `order.cancelled` | Etiqueta é cancelada |
| `order.undelivered` | Encomenda não pôde ser entregue |
| `order.paused` | Entrega interrompida, exige ação do destinatário |
| `order.suspended` | Encomenda é suspensa |

⚠️ No ambiente **sandbox**, a etiqueta muda de status automaticamente a cada 15 minutos até "entregue" — útil pra testar o fluxo completo sem esperar uma entrega real.

📘 Dependendo da transportadora, o campo `tracking` pode demorar até 1 dia útil após a postagem pra ser preenchido.

## Request

- **Método:** `POST`
- **Headers:**
```json
{
  "user-agent": "Melhor Envio Webhooks/1.0",
  "accept": "application/json, text/plain, */*",
  "accept-encoding": "gzip, compress, deflate, br",
  "x-me-signature": "eW/6UEmwJ7vH13kMsrhjMVzek3Yg0Oa5TDsUSeLVFoM=",
  "content-type": "application/json"
}
```
- **Corpo (mesmo formato pra todos os eventos de etiqueta):**
```json
{
    "event": "order.posted",
    "data": {
        "id": "0000aaaa-aa00-00aa-aa00-000000aaaaaa",
        "protocol": "ORD-2024XXXXXXXXXX",
        "status": "posted",
        "tracking": null,
        "self_tracking": null,
        "user_id": "0000111",
        "tags": [{ "tag": "tag1", "url": "www.url1.com" }],
        "created_at": "2024-03-29T23:49:26+00:00",
        "paid_at": null,
        "generated_at": null,
        "posted_at": "2024-03-29T23:55:00+00:00",
        "delivered_at": null,
        "canceled_at": null,
        "expired_at": null,
        "tracking_url": "https://www.melhorrastreio.com.br/rastreio/XXXXXXXXX"
    }
}
```

## ⚠️ Gap conhecido na implementação atual

`api/melhorenvio/webhook.php` **autentica e responde 200 corretamente, mas não persiste nem age sobre o evento** — só devolve os campos parseados na resposta HTTP (`event`, `status`, `tracking`, `protocol`) sem gravar no pedido local (`storage/orders/*.json`) nem atualizar a tabela `orders` do MySQL. Ou seja: o rastreio (`tracking`/`tracking_url`) que chega via este webhook nunca chega a ficar visível pro cliente em `minha-conta/pedidos.php` (que já tem campos `tracking_number`/`nf_*` prontos pra isso, ver `includes/account-schema.php`).

Melhoria natural: ao receber `order.posted` (tem `tracking`/`tracking_url` preenchidos) ou `order.delivered`, localizar o pedido local pelo `protocol` (equivalente ao `melhorenvio_shipment_id` já gravado em `includes/melhorenvio-label.php`) e atualizar `tracking_number`/`estimated_delivery`/`order_status`. Não implementado ainda — fora do escopo desta doc, que é só o levantamento do schema.
