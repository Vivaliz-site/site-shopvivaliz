# Product Video Sync — Etapa 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mapear produtos ativos do ERP Olist/Tiny API v3 para vídeos já hospedados no ShopVivaLiz e gerar `produtos_videos_mapeados.json`, sem qualquer escrita em marketplaces.

**Architecture:** Subsistema Python isolado em `scripts/product_video_sync/`, reutilizando o token OAuth rotativo já existente. O cliente Tiny é read-only; o inventário local de vídeos é preferido e Playwright permanece como fallback explícito de último recurso.

**Tech Stack:** Python 3, requests, python-dotenv existente no repositório, pytest.

**Spec:** `docs/superpowers/specs/2026-09-13-product-video-sync-stage1-design.md`

## Global Constraints

- ERP Olist/Tiny API v3 é a fonte autoritativa de produtos.
- Não criar novo secret nem token estático.
- Nunca logar valores de tokens.
- Nenhuma escrita em Shopee, Mercado Livre, TikTok ou Amazon na Etapa 1.
- Playwright só pode ser acionado explicitamente e apenas após falha das fontes API/servidor.
- Arquivo gerado é runtime e não deve ser commitado.

---

### Task 1: Contratos de configuração e token

**Files:**
- Create: `scripts/product_video_sync/__init__.py`
- Create: `scripts/product_video_sync/config.py`
- Create: `scripts/product_video_sync/token_provider.py`
- Test: `tests/test_product_video_sync_token_provider.py`

**Interfaces:**
- Produces: `Settings.from_env() -> Settings`
- Produces: `resolve_access_token(settings: Settings) -> str`

- [ ] Escrever testes que provem prioridade do token store, fallback para env, rejeição de store inválido e ausência de vazamento de token.
- [ ] Executar os testes e confirmar RED por módulos ausentes.
- [ ] Implementar configuração e provider mínimos.
- [ ] Executar testes e confirmar GREEN.

### Task 2: Cliente Tiny v3 read-only

**Files:**
- Create: `scripts/product_video_sync/tiny_client.py`
- Test: `tests/test_product_video_sync_tiny_client.py`

**Interfaces:**
- Consumes: `Settings`, access token.
- Produces: `TinyClient.list_active_products() -> list[dict]`
- Produces: `TinyClient.get_product(product_id) -> dict`
- Produces: `TinyClient.get_attachments(product_id) -> list[dict]`

- [ ] Escrever testes para `situacao=A`, paginação por `limit/offset`, Bearer auth, timeout, 429/5xx retry limitado e 401 sem retry.
- [ ] Confirmar RED.
- [ ] Implementar somente métodos GET e retry finito respeitando `Retry-After`.
- [ ] Confirmar GREEN.

### Task 3: Inventário e matching determinístico

**Files:**
- Create: `scripts/product_video_sync/video_inventory.py`
- Create: `scripts/product_video_sync/mapper.py`
- Test: `tests/test_product_video_sync_mapper.py`

**Interfaces:**
- Produces: `build_video_inventory(path: Path) -> VideoInventory`
- Produces: `map_product_video(product, attachments, inventory, settings, aliases=None) -> dict`

- [ ] Escrever testes para extensão de vídeo, URL explícita, SKU, ID Tiny, alias, missing e ambiguous.
- [ ] Confirmar RED.
- [ ] Implementar prioridade `attachment > sku > id > alias`, sem fuzzy match.
- [ ] Confirmar GREEN.

### Task 4: Playwright fallback fechado por padrão

**Files:**
- Create: `scripts/product_video_sync/playwright_fallback.py`
- Test: `tests/test_product_video_sync_playwright_fallback.py`

**Interfaces:**
- Produces: `PlaywrightFallback.disabled()` e `lookup(product)`, que falha fechado quando não habilitado/configurado.

- [ ] Testar que o fallback não executa por padrão e exige opt-in explícito.
- [ ] Confirmar RED.
- [ ] Implementar interface sem dependência obrigatória de Playwright no caminho normal.
- [ ] Confirmar GREEN.

### Task 5: CLI, saída atômica e documentação operacional

**Files:**
- Create: `scripts/product_video_sync/cli.py`
- Create: `scripts/map-product-videos.py`
- Create: `tests/test_product_video_sync_cli.py`
- Modify: `.gitignore`
- Modify: `.env.example`
- Create: `docs/PRODUCT-VIDEO-SYNC.md`

**Interfaces:**
- Produces: CLI `python3 scripts/map-product-videos.py --output <path>`.
- Produces: JSON em lista com `id_tiny`, `sku`, `url_video_completa`, `arquivo_video`, `origem_match`, `status`.

- [ ] Testar escrita atômica, schema de saída e que nenhum módulo de marketplace é importado/chamado.
- [ ] Confirmar RED.
- [ ] Implementar CLI e documentação, adicionando somente variáveis não secretas ao `.env.example`.
- [ ] Adicionar `produtos_videos_mapeados.json` ao `.gitignore`.
- [ ] Confirmar GREEN da suíte focal.

### Task 6: Validação final da Etapa 1

**Files:** nenhum novo arquivo.

- [ ] Executar todos os testes `tests/test_product_video_sync_*.py`.
- [ ] Executar `php tests/erp-api-source-policy-test.php` quando disponível no workspace completo.
- [ ] Verificar que a branch contém apenas leitura Tiny e nenhuma chamada de escrita para marketplaces.
- [ ] Em ambiente de produção, executar a CLI com o token store rotativo e inventário real; registrar apenas contagens, nunca tokens.
- [ ] Se a API/inventário não fornecerem o campo necessário, registrar evidência objetiva e então habilitar o fallback Playwright em uma rodada separada.
