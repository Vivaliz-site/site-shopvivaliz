# Rotinas Shopee

Esta pasta concentra as rotinas operacionais da integracao Shopee.

## Arquivos principais

| Arquivo | Funcao | Risco | Validacao |
|---|---|---:|---|
| `production_seo_apply.py` | Aplica SEO real em produtos Shopee com backup, confirmacao explicita e leitura posterior | producao | Relatorio JSON com `updated_verified` ou `verified_unchanged` |
| `full_catalog_optimizer.py` | Gera titulo/descricao e valida invariantes de preco/estoque | medio/producao quando usado com `--apply` | Relatorio JSON e backup |

## Compatibilidade

Os caminhos antigos em `scripts/shopee_production_seo_apply.py` e `scripts/shopee_full_catalog_optimizer.py` permanecem como wrappers temporarios para nao quebrar chamadas existentes. Codigo novo deve usar esta pasta.

## Regras

- Nunca alterar preco ou estoque.
- Toda atualizacao real precisa de backup e read-back.
- Usar `SHOPEE_REFRESH_TOKEN` para renovar access token automaticamente quando disponivel.
- Registrar novas rotinas em `docs/knowledge/routines-registry.md`.
