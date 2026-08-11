# Secret Usage Map

Mapa objetivo dos consumos reais identificados no projeto ShopVivaliz em 2026-08-11.
Nao inclui valores reais.

## Runtime ativo

| Grupo | Secretos | Consumidores principais | Status |
|---|---|---|---|
| IA / LLM | `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `GEMINI_API_KEY` | [api/liz-intelligent.php](../api/liz-intelligent.php), [claude/api/agent/squad-chat.php](../claude/api/agent/squad-chat.php), [ai_collaboration.py](../ai_collaboration.py), [scripts/chat-responder-real.py](../scripts/chat-responder-real.py), [scripts/generate-ai-images.py](../scripts/generate-ai-images.py), [scripts/ia/image_generator.py](../scripts/ia/image_generator.py) | ativo |
| IA / utilitarios | `OPENAI_API_KEY` e aliases | [admin/ai-image-studio](../admin/ai-image-studio/), [admin/catalog-optimization](../admin/catalog-optimization/), [scripts/ecommerce-multi-ai-builder.py](../scripts/ecommerce-multi-ai-builder.py), [scripts/llm-log-analyzer.php](../scripts/llm-log-analyzer.php) | ativo |
| Tiny / Olist | `TINY_CLIENT_ID`, `TINY_CLIENT_SECRET`, `TINY_ACCESS_TOKEN`, `TINY_REFRESH_TOKEN`, `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `OLIST_ACCESS_TOKEN`, `OLIST_REFRESH_TOKEN`, `TOKEN_API_OLIST` | [api/olist/login.php](../api/olist/login.php), [api/olist/refresh-token.php](../api/olist/refresh-token.php), [scripts/get-tiny-oauth-token.py](../scripts/get-tiny-oauth-token.py), [scripts/tiny_sales_report.py](../scripts/tiny_sales_report.py), [scripts/publish-to-marketplace.py](../scripts/publish-to-marketplace.py), [scripts/recover-olist-oauth-from-backups.py](../scripts/recover-olist-oauth-from-backups.py) | ativo |
| Tiny / Olist runtime | mesmos acima | [scripts/materialize-runtime-secrets.php](../scripts/materialize-runtime-secrets.php), [.github/workflows/recover-olist-runtime.yml](../.github/workflows/recover-olist-runtime.yml), [scripts/configure-erp-oauth-static-runtime.py](../scripts/configure-erp-oauth-static-runtime.py) | ativo |
| Amazon SP-API | `AMAZON_LWA_CLIENT_ID`, `AMAZON_LWA_CLIENT_SECRET`, `AMAZON_LWA_REFRESH_TOKEN` | [scripts/configure-marketplace-publication-runtime.py](../scripts/configure-marketplace-publication-runtime.py), [admin/catalog-optimization/diagnostico-marketplace-tokens.php](../admin/catalog-optimization/diagnostico-marketplace-tokens.php), [.github/workflows/configure-marketplace-publication-runtime.yml](../.github/workflows/configure-marketplace-publication-runtime.yml), [scripts/maintenance/marketplace_publication_readiness.php](../scripts/maintenance/marketplace_publication_readiness.php) | ativo |
| GA4 / Google Ads | `GA4_ID`, `GA4_SECRET`, `GOOGLE_ADS_CONVERSION_ID`, `GOOGLE_ADS_CONVERSION_LABEL` | [checkout.php](../checkout.php), [checkout-return.php](../checkout-return.php), [scripts/validate-tracking-config.php](../scripts/validate-tracking-config.php), [scripts/google_ads_real_readiness.py](../scripts/google_ads_real_readiness.py), [scripts/google-commerce-config-audit.php](../scripts/google-commerce-config-audit.php) | ativo, mas nao materializado em runtime-secrets.php |

## Documentacao e suporte local

| Grupo | Secretos | Consumidores principais | Status |
|---|---|---|---|
| Remote MCP | `REMOTE_MCP_ENABLED`, `REMOTE_MCP_PROVIDER`, `REMOTE_MCP_VERIFY_URL`, `REMOTE_MCP_AUTH_FLOW`, `REMOTE_MCP_DEVICE_NAME`, `REMOTE_MCP_AUTH_USER_EMAIL`, `REMOTE_MCP_DEVICE_ID`, `REMOTE_MCP_ACCESS_TOKEN` | [.env.example](../.env.example), [docs/AGENT-MCP-REMOTE.md](AGENT-MCP-REMOTE.md), [docs/agent-access.md](agent-access.md), [docs/github-actions-diagnostico.md](github-actions-diagnostico.md) | local-only; nao materializar em `shared/.env` nem em `runtime-secrets.php` |

## Observacoes

- `scripts/materialize-runtime-secrets.php` materializa apenas o subconjunto aprovado para runtime.
- `shared/.env` e `runtime-secrets.php` sao a referencia da VM; o checkout local pode conter chaves apenas para desenvolvimento.
- Arquivos de report/historico podem citar nomes de secrets sem serem consumidores reais.
