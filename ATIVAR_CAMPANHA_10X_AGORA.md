# Guia legado de ativacao Google Ads - ARQUIVADO

**Status:** NAO USAR PARA ATIVAR CAMPANHAS

Este documento antigo continha instrucoes para ativar uma campanha imediatamente, um Customer ID fixo, promessas de desempenho e textos publicitarios nao comprovados. Esse fluxo foi substituido por uma configuracao auditavel e fail-closed.

## Fluxo atual permitido

1. Validar credenciais sem rede:

```bash
python3 scripts/google_ads_auth_preflight.py
```

2. Validar configuracao, tracking e guardrails:

```bash
python3 scripts/google_ads_real_readiness.py
```

3. Revisar o dry-run da campanha atual:

```bash
python3 scripts/google_ads_create_search_campaign.py
```

4. Somente depois de revisar conta, conversoes, budget, CPC, keywords, anuncios e landing pages, a criacao real pode ser feita **PAUSADA**:

```bash
python3 scripts/google_ads_create_search_campaign.py --create-paused
```

A ativacao da campanha nao faz parte deste guia e nao deve ser automatica.

## Fonte de verdade

- Configuracao: `scripts/google_ads_campaign_live_ready.json`
- Readiness: `scripts/google_ads_real_readiness.py`
- Auditoria read-only: `scripts/google_ads_review_campaigns.py`
- Imagens dinamicas: `scripts/google_ads_dynamic_images.py`
- Preflight OAuth: `scripts/google_ads_auth_preflight.py`

## Regras de seguranca e performance

- Nenhum Customer ID, OAuth secret, Developer Token ou Refresh Token deve ser copiado de documentos versionados.
- Nao usar promessas como "primeira venda em X dias", "ROI garantido", "melhor preco", "frete gratis" ou prazos de entrega sem evidencia atual.
- Nao aumentar budget ou CPC automaticamente apenas por poucos dias de dados.
- Conversoes devem estar comprovadamente funcionando antes de otimizar por ROAS/CPA.
- Toda criacao deve iniciar pausada e ser revisada antes de qualquer ativacao.

Este arquivo permanece apenas para preservar o historico e impedir que instrucoes antigas sejam usadas por engano.
