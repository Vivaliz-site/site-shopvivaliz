# Shopee Integration — Setup seguro

Este guia substitui uma versão histórica que continha credenciais em texto puro.

## Secrets canônicos

Configure somente em GitHub Environment Secrets ou outro gerenciador aprovado:

- `SHOPEE_PARTNER_ID`
- `SHOPEE_PARTNER_KEY`
- `SHOPEE_SHOP_ID`
- `SHOPEE_ACCESS_TOKEN`
- `SHOPEE_REFRESH_TOKEN`
- `SHOPEE_TOKEN_REFRESH_INTERVAL_SECONDS`

Para sandbox, use os nomes de teste documentados em `docs/knowledge/secrets-and-integrations-map.md`.

## Execução atual

A implementação canônica está em:

- `scripts/marketplace/shopee/production_seo_apply.py`
- `scripts/marketplace/shopee/full_catalog_optimizer.py`
- `scripts/utils/shopee_client.py`
- `.github/workflows/shopee-production-seo.yml`

## Renovação

O cliente deve renovar o access token pelo refresh token antes da expiração e quando a API indicar token inválido. O intervalo preventivo padrão é configurável e não deve depender de token escrito em arquivo.

## Validação

- compilar os módulos;
- executar testes de segurança Shopee;
- iniciar com limite de um produto;
- gerar backup;
- preservar preço e estoque;
- fazer read-back após atualização;
- publicar relatório e artifact sem credenciais.

## Resposta ao incidente

Partner key, senha sandbox, access token, refresh token e códigos de autorização que apareceram na versão anterior devem ser considerados comprometidos e rotacionados. A remoção da árvore atual não limpa automaticamente o histórico Git.
