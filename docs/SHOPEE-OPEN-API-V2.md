# Shopee Open Platform API v2 — Referência (levantada em produção)

> Fonte oficial: https://open.shopee.com/developer-guide/20 (Authorization and Authentication)
> Base da API: `https://partner.shopeemobile.com/api/v2`
> Cliente real no repo: `scripts/utils/shopee_client.py`
> Renovação automática: `daemon-shopee-token-renewer.py` + `shopvivaliz-shopee-token-renewer.service`

## Autenticação — assinatura HMAC-SHA256

Toda chamada exige uma `sign` calculada como:

```
base_string = f"{partner_id}{api_path}{timestamp}"          # rotas publicas/auth
base_string = f"{partner_id}{api_path}{timestamp}{access_token}{shop_id}"  # rotas de shop
sign = HMAC-SHA256(base_string, key=partner_key).hexdigest()  # hex lowercase
```

`api_path` é o path SEM host (ex: `/auth/access_token/get`), mas prefixado com
`/api/v2` se ainda não tiver esse prefixo.

## Fluxo de autorização (OAuth)

1. **Gerar link de autorização**: `GET https://open.shopee.com/auth?partner_id=...&auth_type=seller&redirect_uri=...&response_type=code`
   (ou, para o fluxo legado usado neste repo via `shopee-token-tool.py auth-url`:
   `GET https://partner.shopeemobile.com/api/v2/shop/auth_partner?partner_id=...&timestamp=...&sign=...&redirect=...`)
2. Vendedor loga e autoriza → redireciona para `redirect_uri` com `?code=...&shop_id=...`.
   **O `code` só é válido uma vez e expira em 10 minutos.**
3. **Trocar code por token**: `POST /auth/token/get`
   ```json
   {"code": "...", "shop_id": 123, "partner_id": 123}
   ```
   Resposta: `{access_token, refresh_token, expire_in, error, message}`.
   `access_token` vale 4h (14400s); `refresh_token` vale 30 dias.

## Renovação — `RefreshAccessToken`

```
POST https://partner.shopeemobile.com/api/v2/auth/access_token/get?partner_id={id}&timestamp={ts}&sign={sign}
Body: {"refresh_token": "...", "shop_id": 123, "partner_id": 123}
```

⚠️ **O `refresh_token` retornado é NOVO a cada renovação e deve substituir o antigo.**
Usar um `refresh_token` já consumido falha. Isso significa que a renovação **precisa
persistir o novo par (access_token, refresh_token) imediatamente** — não dá pra guardar
só o access_token e reusar o refresh_token antigo pra sempre.

O `access_token` anterior continua válido por mais 5 minutos após a renovação (janela de
transição segura).

## Erros comuns

- Chamar com `refresh_token` expirado/já usado → erro no campo `error` da resposta (não
  necessariamente HTTP não-200 — sempre checar `data.get("error")`, não só o status HTTP).
- `code` de autorização reusado (já trocado antes) → `"error": "invalid_code", "message": "The code is expired or used or invalid"`.
- Timestamp da assinatura só é válido por 5 minutos — gerar `sign` e usar na mesma
  chamada, não cachear.

## IDs de cadastro desta conta

Ver `docs/TINY-ERP-API-V3.md` para o padrão equivalente do Tiny ERP — aqui não há
cadastros locais além de `SHOPEE_PARTNER_ID`, `SHOPEE_SHOP_ID` (fixos, ver `.env`).

## Renovação automática neste repo

`daemon-shopee-token-renewer.py` roda a cada 3h (bem dentro da janela de 4h de validade
do access_token), chama `RefreshAccessToken` e reescreve `SHOPEE_ACCESS_TOKEN`/
`SHOPEE_REFRESH_TOKEN` no `.env` atomicamente (mesmo padrão de `daemon-token-renewer.py`
usado pro Tiny/Olist). Systemd: `shopvivaliz-shopee-token-renewer.service`.

Se o `refresh_token` expirar de vez (30 dias sem renovar, ou revogação manual), é
necessário refazer o fluxo de autorização OAuth do zero (login do vendedor no browser).
