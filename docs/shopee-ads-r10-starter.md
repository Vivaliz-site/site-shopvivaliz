# Shopee Ads - início com R$ 10/dia

Este documento registra o fluxo seguro para iniciar Shopee Ads via API com orçamento pequeno.

## Status

- Script criado: `scripts/shopee_ads_r10_start.py`
- Orçamento padrão: `R$ 10,00/dia`
- Lance inicial padrão: `R$ 0,20`
- Modo padrão: dry-run, sem criar anúncio real
- Criação real: somente com `--live` ou `SHOPEE_ENABLE_LIVE_ADS=true` e com IDs de produtos informados

## Secrets esperados

Configure no repositório, em `Settings > Secrets and variables > Actions`:

- `SHOPEE_PARTNER_ID`
- `SHOPEE_PARTNER_KEY`
- `SHOPEE_SHOP_ID`
- `SHOPEE_ACCESS_TOKEN`

Opcionais:

- `SHOPEE_HOST`
- `SHOPEE_ADS_CREATE_PATH`
- `SHOPEE_SHOP_INFO_PATH`
- `SHOPEE_ADS_RAW_PAYLOAD`

## Execução local ou via runner autorizado

Dry-run, apenas valida credenciais e payload:

```bash
python -m pip install --upgrade requests
SHOPEE_PARTNER_ID="..." \
SHOPEE_PARTNER_KEY="..." \
SHOPEE_SHOP_ID="..." \
SHOPEE_ACCESS_TOKEN="..." \
SHOPEE_ADS_ITEM_IDS="123456789" \
python scripts/shopee_ads_r10_start.py --daily-budget-brl 10 --bid-price-brl 0.20
```

Criação real:

```bash
SHOPEE_ENABLE_LIVE_ADS=true \
SHOPEE_PARTNER_ID="..." \
SHOPEE_PARTNER_KEY="..." \
SHOPEE_SHOP_ID="..." \
SHOPEE_ACCESS_TOKEN="..." \
SHOPEE_ADS_ITEM_IDS="123456789" \
python scripts/shopee_ads_r10_start.py --daily-budget-brl 10 --bid-price-brl 0.20 --live
```

## Guardrails

1. O script não imprime tokens, partner key ou assinatura.
2. Se os secrets estiverem ausentes, ele falha antes de chamar a API.
3. Se a validação de loja/permissão retornar erro, ele não cria anúncios.
4. Se não houver `SHOPEE_ADS_ITEM_IDS`, ele não cria anúncios.
5. Se `--live` não for passado, ele roda somente em modo teste.

## Observação importante

A estrutura exata do payload de Shopee Ads pode variar conforme região, conta e permissão liberada no Shopee Open Platform. Caso a Shopee retorne erro de contrato/payload, configure `SHOPEE_ADS_RAW_PAYLOAD` com o JSON exato recomendado pela documentação/conta habilitada.
