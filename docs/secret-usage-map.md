# Secret Usage Map

Mapa objetivo dos consumos reais identificados no projeto ShopVivaliz em 2026-08-11.
Nao inclui valores reais.

## Runtime ativo

| Grupo | Secretos | Consumidores principais | Status |
|---|---|---|---|
| IA / automação | `GEMINI_API_KEY`, `OPENROUTER_API_KEY` | [api/liz-intelligent.php](../api/liz-intelligent.php), [api/liz-general.php](../api/liz-general.php), [scripts/chat-responder-real.py](../scripts/chat-responder-real.py), [.github/scripts/proactive_agent.py](../.github/scripts/proactive_agent.py) | ativo; automação restrita a Gemini/OpenRouter |
| IA / utilitarios | `OPENAI_API_KEY` e aliases | [admin/ai-image-studio](../admin/ai-image-studio/), [admin/catalog-optimization](../admin/catalog-optimization/), [scripts/ecommerce-multi-ai-builder.py](../scripts/ecommerce-multi-ai-builder.py), [scripts/llm-log-analyzer.php](../scripts/llm-log-analyzer.php) | ativo |
| Tiny / Olist | `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET` | [olist/connect.php](../olist/connect.php), [olist/callback.php](../olist/callback.php) | bootstrap OAuth canônico |
| Tiny / Olist runtime | access/refresh tokens rotativos no storage privado | [daemon-token-renewer.py](../daemon-token-renewer.py), [deploy/systemd/shopvivaliz-token-renewer.service](../deploy/systemd/shopvivaliz-token-renewer.service), [includes/marketplace/TinyV3Runtime.php](../includes/marketplace/TinyV3Runtime.php) | daemon único escritor; consumidores somente leitura |
| Amazon SP-API | `AMAZON_LWA_CLIENT_ID`, `AMAZON_LWA_CLIENT_SECRET`, `AMAZON_LWA_REFRESH_TOKEN` | [scripts/configure-marketplace-publication-runtime.py](../scripts/configure-marketplace-publication-runtime.py), [admin/catalog-optimization/diagnostico-marketplace-tokens.php](../admin/catalog-optimization/diagnostico-marketplace-tokens.php), [.github/workflows/configure-marketplace-publication-runtime.yml](../.github/workflows/configure-marketplace-publication-runtime.yml), [scripts/maintenance/marketplace_publication_readiness.php](../scripts/maintenance/marketplace_publication_readiness.php) | ativo |
| GA4 / Google Ads | `GA4_ID`, `GA4_SECRET`, `GOOGLE_ADS_CONVERSION_ID`, `GOOGLE_ADS_CONVERSION_LABEL` | [checkout.php](../checkout.php), [checkout-return.php](../checkout-return.php), [scripts/validate-tracking-config.php](../scripts/validate-tracking-config.php), [scripts/google_ads_real_readiness.py](../scripts/google_ads_real_readiness.py), [scripts/google-commerce-config-audit.php](../scripts/google-commerce-config-audit.php) | ativo, mas nao materializado em runtime-secrets.php |

## Documentacao e suporte local

| Grupo | Secretos | Consumidores principais | Status |
|---|---|---|---|
| Remote MCP | `REMOTE_MCP_ENABLED`, `REMOTE_MCP_PROVIDER`, `REMOTE_MCP_VERIFY_URL`, `REMOTE_MCP_AUTH_FLOW`, `REMOTE_MCP_DEVICE_NAME`, `REMOTE_MCP_AUTH_USER_EMAIL`, `REMOTE_MCP_DEVICE_ID`, `REMOTE_MCP_ACCESS_TOKEN` | [.env.example](../.env.example), [docs/AGENT-MCP-REMOTE.md](AGENT-MCP-REMOTE.md), [docs/agent-access.md](agent-access.md), [docs/github-actions-diagnostico.md](github-actions-diagnostico.md) | local-only; nao materializar em `shared/.env` nem em `runtime-secrets.php` |

## Observacoes

- Claude/GPT/Codex exigem gatilho humano explicito; nao sao fallback de automacao.
- `ai_collaboration.py` foi aposentado em modo fail-closed.

- `scripts/materialize-runtime-secrets.php` materializa apenas o subconjunto aprovado para runtime.
- `shared/.env` e `runtime-secrets.php` sao a referencia da VM; o checkout local pode conter chaves apenas para desenvolvimento.
- Arquivos de report/historico podem citar nomes de secrets sem serem consumidores reais.
