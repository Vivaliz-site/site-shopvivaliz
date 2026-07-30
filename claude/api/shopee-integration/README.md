# Integração Shopee — referência histórica sanitizada

A documentação operacional atual está em:

- `docs/operations/shopee/`
- `docs/knowledge/secrets-and-integrations-map.md`
- `docs/knowledge/routines-registry.md`
- `scripts/marketplace/shopee/README.md`

## Segurança

Este diretório não deve conter `CREDENTIALS.json`, `SECRETS.json`, senha sandbox, partner key, access token, refresh token ou código de autorização preenchido.

Use apenas referências a variáveis de ambiente:

```yaml
env:
  SHOPEE_PARTNER_ID: ${{ secrets.SHOPEE_PARTNER_ID }}
  SHOPEE_PARTNER_KEY: ${{ secrets.SHOPEE_PARTNER_KEY }}
  SHOPEE_SHOP_ID: ${{ secrets.SHOPEE_SHOP_ID }}
  SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
  SHOPEE_REFRESH_TOKEN: ${{ secrets.SHOPEE_REFRESH_TOKEN }}
```

## Fluxo

1. Obter autorização pelo fluxo oficial.
2. Armazenar tokens em secret protegido.
3. Renovar automaticamente pelo refresh token.
4. Executar canário de um produto.
5. Confirmar read-back e invariantes.
6. Somente então liberar lote maior.

Credenciais presentes em versões históricas deste arquivo devem ser rotacionadas. Não usar valores recuperados do histórico.
