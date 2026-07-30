# Pipeline Shopee — status legado

> Migrado de `PIPELINE-SHOPEE-STATUS.txt` durante organização estrutural do repositório.

**Data original:** 2026-06-29 07:50:38  
**Status original:** concluído com sucesso, conforme documento legado.

---

## Resumo executivo legado

Pipeline autônomo criado para sincronizar 198 produtos Olist com Shopee por CSV.

Registro original:

- CSV gerado: `shopee-import-imagens.csv`
- Produtos sincronizados: 198
- Colunas Shopee: 25
- Codificação: UTF-8 com BOM
- Separador: ponto-e-vírgula

---

## Arquivos citados no status legado

1. `logs/shopee-import-imagens.csv`
   - Arquivo principal para upload manual na Shopee.

2. `api/pipeline/shopee-sync.php`
   - Endpoint HTTP para executar pipeline via API.

3. `.github/workflows/shopee-sync-diario.yml`
   - Workflow diário às 06:00 UTC.

---

## Relação com o fluxo Shopee atual

Este documento descreve o fluxo CSV legado Olist → Shopee.

O fluxo canônico novo de SEO Shopee via API fica em:

- `scripts/marketplace/shopee/production_seo_apply.py`
- `scripts/marketplace/shopee/full_catalog_optimizer.py`
- `.github/workflows/shopee-production-seo.yml`

---

## Próxima organização necessária

- Verificar se `api/pipeline/shopee-sync.php` ainda é usado.
- Verificar se `.github/workflows/shopee-sync-diario.yml` ainda existe/roda.
- Se ainda ativo, registrar rotina no `routines-registry.md`.
- Se obsoleto, arquivar em `archive/<ano>/shopee/` com justificativa.

---

## Status de migração

- Caminho antigo: `PIPELINE-SHOPEE-STATUS.txt`
- Caminho canônico: `docs/operations/shopee/pipeline-status-legacy.md`
- Compatibilidade: arquivo antigo mantido como ponte.
