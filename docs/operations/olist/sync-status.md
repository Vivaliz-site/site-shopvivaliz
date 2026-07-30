# Status da Sincronização Olist - ShopVivaliz

> Migrado de `SINCRONIZACAO-OLIST-STATUS.md` durante organização estrutural do repositório.

**Data original:** 28 de Junho de 2026  
**Status original:** 90% concluído

---

## Concluído registrado no documento legado

1. OAuth Login
   - Login manual via browser implementado.
   - Refresh token salvo localmente em `.tokens/olist-config.json`.
   - Endpoint: `https://shopvivaliz.com.br/olist/login-form.php`.

2. Sincronização de produtos
   - 198 produtos importados da Olist.
   - Cache legado: `logs/olist-products-cache.json`.
   - Imagens sincronizadas segundo relatório original.

3. GitHub Actions
   - Workflow legado: `.github/workflows/olist-auto-sync-hourly.yml`.

---

## Pendências registradas

1. Catálogo ainda não exibia os produtos.
   - Arquivo citado: `catalogo/index.php`.
   - Hipótese original: estrutura do cache JSON incompatível.

2. Download de imagens locais.
   - Script citado: `/olist/download-images.php`.

3. Sincronização com banco de dados.
   - Script citado: `/olist/sync-images-to-site.php`.

---

## Arquivos relacionados

### PHP endpoints legados

- `olist/login-form.php`
- `olist/callback.php`
- `olist/complete-oauth-flow.php`
- `olist/process-code.php`
- `olist/sync-agora.php`
- `olist/auto-sync-hourly.php`
- `olist/download-images.php`
- `olist/sync-images-to-site.php`

### Python scripts legados

- `scripts/auto-oauth-login.py`
- `scripts/olist-headless-login.py`
- `scripts/auto-complete-olist.py`
- `scripts/olist-direct-login.py`

### GitHub Actions

- `.github/workflows/olist-auto-sync-hourly.yml`

---

## Próxima organização necessária

- Validar quais scripts Olist ainda estão ativos.
- Migrar scripts Python Olist para `scripts/marketplace/olist/`.
- Migrar endpoints PHP Olist apenas depois de confirmar dependências públicas.
- Remover aliases `OLIST_ACCESS_TOKEN`, `OLIST_CLIENT_ID`, `OLIST_CLIENT_SECRET`, `OLIST_REDIRECT_URI`, `OLIST_API_BASE_URL` fora do centralizador.

---

## Status de migração

- Caminho antigo: `SINCRONIZACAO-OLIST-STATUS.md`
- Caminho canônico: `docs/operations/olist/sync-status.md`
- Compatibilidade: arquivo antigo mantido como ponte.
