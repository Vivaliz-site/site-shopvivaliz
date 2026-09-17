# Product Video Dual-Source Marketplaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Manter MP4 direto e YouTube por produto, exibir ambos corretamente e preparar/publicar video por canal sem sobrescrever fontes.

**Architecture:** `includes/product-video-sources.php` persiste e resolve fontes complementares; o daemon mescla `youtube_url` no cache sem substituir `video_url`. Um uploader Python publica MP4 no YouTube com OAuth resumable e persiste somente metadados seguros. A camada marketplace registra tentativas em `catalog_publications` e so usa canais com suporte explicito.

**Tech Stack:** PHP 8.3, Python 3.12, JSON atomico, YouTube Data API v3, OAuth 2.0, PHPUnit-style scripts/unittest.

**Spec:** `docs/superpowers/specs/2026-09-16-product-video-dual-source-marketplaces-design.md`

## Global Constraints
- ERP Olist/Tiny v3 permanece fonte primaria do cadastro.
- Nunca remover ou substituir `video_url` ao criar `youtube_url`.
- Nunca registrar tokens OAuth.
- Toda publicacao externa exige read-back antes de `verified=true`.

---
### Task 1: Dual-source persistence and cache merge
**Files:** Create `includes/product-video-sources.php`; modify `daemon-sync-products.py`; test `tests/product-video-sources-test.php` and `tests/unit/test_daemon_sync_products_incremental.py`.

- [ ] Write failing tests proving direct MP4 and YouTube coexist and that stale ERP sync does not erase YouTube metadata.
- [ ] Run the focused tests and confirm expected failures.
- [ ] Implement atomic JSON source registry and merge by ERP id/SKU.
- [ ] Run focused tests until green.
- [ ] Commit `feat: preserve dual product video sources`.

### Task 2: Storefront source selection
**Files:** Modify `produto.php`, `includes/product-media.php`; test `tests/product-media-gallery-regression-test.php`.

- [ ] Add a failing regression test for direct-video preference with YouTube fallback.
- [ ] Run test and confirm failure.
- [ ] Resolve media from `video_url` first and `youtube_url` second without changing existing image gallery behavior.
- [ ] Run PHP lint and media regression tests.
- [ ] Commit `fix: use dual video sources in product gallery`.
### Task 3: YouTube uploader and safe metadata persistence
**Files:** Create `scripts/youtube-product-video-upload.py`; test `tests/test_youtube_product_video_upload.py`.

- [ ] Write failing tests for OAuth scope detection, resumable upload request construction, sanitized logs, and metadata persistence.
- [ ] Run tests and confirm expected failures.
- [ ] Implement OAuth refresh, scope validation, resumable upload, `videos.list` read-back, and atomic registry update.
- [ ] Run Python tests and syntax checks.
- [ ] Commit `feat: add verified YouTube product video uploader`.

### Task 4: Marketplace capability routing and production validation
**Files:** Create `includes/marketplace/ProductVideoRouter.php`; test `tests/marketplace-product-video-router-test.php`.

- [ ] Write failing tests that route site/YouTube/direct-file separately and reject unsupported marketplace video publication without mutating sources.
- [ ] Implement explicit capability routing and publication audit records with `publication_type=video`.
- [ ] Run marketplace tests plus full quality suite.
- [ ] Push branch, merge to main using repository workflow, deploy, and force catalog reconciliation.
- [ ] Verify SKU 35039 in production: 6 images + video item, MP4 HTTP 200/video/mp4, timer waiting, and YouTube URL only when upload read-back succeeded.
