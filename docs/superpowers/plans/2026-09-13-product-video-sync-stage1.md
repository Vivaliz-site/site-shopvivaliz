# Product Video Sync — Etapa 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mapear produtos ativos do ERP Olist/Tiny API v3 para vídeos já hospedados no ShopVivaLiz e gerar `produtos_videos_mapeados.json`, sem qualquer escrita em marketplaces.

**Architecture:** Subsistema Python isolado em `scripts/product_video_sync/`, reutilizando o token OAuth rotativo já existente. O cliente Tiny é read-only; os arquivos hospedados no servidor são a fonte preferida para confirmar correspondência e Playwright permanece como fallback explícito de último recurso.

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

- [x] Escrever testes que provem prioridade do token store, fallback para env, rejeição de store inválido e ausência de vazamento de token.
- [x] Executar os testes e confirmar RED por módulos ausentes.
- [x] Implementar configuração e provider mínimos.
- [x] Executar testes e confirmar GREEN.

### Task 2: Cliente Tiny v3 read-only

**Files:**
- Create: `scripts/product_video_sync/tiny_client.py`
- Test: `tests/test_product_video_sync_tiny_client.py`

**Interfaces:**
- Consumes: `Settings`, access token.
- Produces: `TinyClient.list_active_products() -> list[dict]`
- Produces: `TinyClient.get_product(product_id) -> dict`
- Produces: `TinyClient.get_attachments(product_id) -> list[dict]`

- [x] Escrever testes para `situacao=A`, paginação por `limit/offset`, Bearer auth, timeout, 429/5xx retry limitado e 401 sem retry.
- [x] Confirmar RED.
- [x] Implementar somente métodos GET e retry finito, usando `Retry-After` e `X-RateLimit-Reset` em 429.
- [x] Usar o endpoint oficial `GET /produtos/{idProduto}/anexos`.
- [x] Confirmar GREEN.

### Task 3: Arquivos hospedados e matching determinístico

**Files:**
- Create: `scripts/product_video_sync/hosted_videos.py`
- Create: `scripts/product_video_sync/mapper.py`
- Test: `tests/test_product_video_sync_mapper.py`

**Interfaces:**
- Produces: `build_video_inventory(path: Path) -> VideoInventory`
- Produces: `map_product_video(product, detail, inventory, settings, aliases=None) -> dict`

- [x] Escrever testes para extensão de vídeo, URL explícita, SKU, ID Tiny, alias, missing e ambiguous.
- [x] Confirmar RED.
- [x] Implementar prioridade `attachment > sku > id > alias`, sem fuzzy match.
- [x] Confirmar GREEN.
- [x] Renomear o módulo para `hosted_videos.py` para não confundir a política de preço/estoque do repositório com arquivos de vídeo.

### Task 4: Playwright fallback fechado por padrão

**Files:**
- Create: `scripts/product_video_sync/playwright_fallback.py`
- Test: `tests/test_product_video_sync_playwright_fallback.py`

**Interfaces:**
- Produces: `PlaywrightFallback.lookup(product)`, que falha fechado quando não habilitado/configurado.

- [x] Testar que o fallback não executa por padrão e exige opt-in explícito.
- [x] Confirmar RED.
- [x] Implementar interface sem dependência obrigatória de Playwright no caminho normal.
- [x] Confirmar GREEN.

### Task 5: CLI, saída atômica e documentação operacional

**Files:**
- Create: `scripts/product_video_sync/cli.py`
- Create: `scripts/map-product-videos.py`
- Create: `tests/test_product_video_sync_cli.py`
- Create: `scripts/product_video_sync/product-video.env.example`
- Create: `docs/PRODUCT-VIDEO-SYNC.md`

**Interfaces:**
- Produces: CLI `python3 scripts/map-product-videos.py --output <path>`.
- Produces: JSON em lista com `id_tiny`, `sku`, `url_video_completa`, `arquivo_video`, `origem_match`, `status`.

- [x] Testar escrita atômica e schema de saída.
- [x] Confirmar RED.
- [x] Implementar CLI e documentação, adicionando somente configuração não secreta.
- [x] Usar `storage/tiny/produtos_videos_mapeados.json`, já coberto pelo `.gitignore` existente do repositório.
- [x] Confirmar GREEN da suíte focal.

### Task 6: Validação final da Etapa 1

**Files:** nenhum novo arquivo.

- [x] Executar `python3 -m pytest -q tests/test_product_video_sync_*.py` — 26 testes verdes.
- [x] Executar compilação de `scripts/product_video_sync` e o entrypoint.
- [x] Verificar estaticamente que o subsistema não contém métodos HTTP de escrita nem referências operacionais aos marketplaces.
- [x] Abrir PR e submeter aos gates de governança do repositório.
- [ ] Após merge/deploy, executar a CLI no runtime que possui o token rotativo e o diretório real de vídeos; registrar apenas contagens, nunca tokens.
- [ ] Somente se API/arquivos não fornecerem a informação necessária, conectar um resolver Playwright auditado em rodada separada.
