# Site Index

## Table of Contents
1. [Root Directory](#1-root-directory)
2. [Admin Area](#2-admin-area)
   - [Pages](#pages)
   - [Menus](#menus)
   - [Routines / Automation Scripts](#routines--automation-scripts)
3. [API & Webhooks](#3-api--webhooks)
   - [Endpoints](#endpoints)
   - [Webhook Files](#webhook-files)
4. [Automation & Routine Scripts](#4-automation--routine-scripts)
5. [Core & Core Components](#5-core--core-components)
6. [Includes](#6-includes)
7. [Modules & Extensions](#7-modules--extensions)
8. [Assets & Static Resources](#8-assets--static-resources)

---

## 1. Root Directory
- **.env.example** – Template for environment variables.
- **README.md** – Project overview and setup instructions.
- **composer.json** & **composer.lock** – PHP dependency management.
- **package.json** & **package-lock.json** – Node.js dependencies.
- **Dockerfile** – Container definition for deployment.
- **docker-compose.yml** – Multi‑container orchestration.
- **CHANGELOG.md** – Change log of the project.
- **DEPLOYMENT_COMPLETE.md** – Documentation of completed deployment steps.
- **DEPLOYMENT_PLAN_2026.md** – Future deployment roadmap.
- **DEPLOYMENT_STATUS.md** – Current deployment status overview.
- **DEPLOY_CHECKLIST_FINAL.md** – Final checklist for deployments.
- **DEPLOY-COMPLETE.md** – Record of completed deployment actions.
- **DEPLOY-TEST-2026-07-09.txt** – Log of a deployment test.
- **deploy-log.txt** – General deployment log file.
- **deploy-trigger.txt** – File that triggers deployment pipelines.
- **deploy-now.ps1** – PowerShell script for initiating deployment.
- **force-sync-now.sh** – Shell script to force a sync operation.
- **git-auto-sync.py** – Python script for automatic Git repository synchronization.
- **sync-cache-endpoint.php** – Endpoint for synchronizing cache layers.
- **sync-olist-para-products.php** – Script to sync Olist product data.
- **sync-all-pages-full.php** – Full page synchronization script.
- **audit-deep-2026-07-23_22-59-41.md** – Detailed audit report.
- **audit-report.txt** – Summary of audit findings.

---

## 2. Admin Area
### Pages
- **admin/index.php** – Main admin entry point.
- **admin/dashboard.php** – Dashboard overview for administrators.
- **admin/menu-completo.php** – Full menu definition file.
- **admin/menu-dashboard.php** – Dashboard‑specific menu configuration.
- **admin/admin-back.php** – Legacy admin back‑end file.
- **admin/force-git-pull.php** – Forces a Git pull to refresh code.
- **admin/sync-critical-files.php** – Synchronizes critical configuration files.
- **admin/admin-guard.php** – Middleware protecting admin routes.
- **admin/ai-image-studio/admin_dashboard.php** – AI image studio dashboard.
- **admin/ai-image-studio/admin_validate.php** – Validates uploaded images.
- **admin/catalog-optimization/admin_catalog.php** – Catalog optimization UI.
- **admin/company-profile.php** – Management of company profile data.
- **admin/connections.php** – Handles external service connections.
- **admin/diagnostico-banco.php** – Diagnostic tool for “banco” (bank) modules.
- **admin/diagnostico-produto.php** – Diagnostic tool for product modules.
- **admin/editar-produto.php** – Interface for editing product information.
- **admin/force-git-pull.php** – (duplicate entry, see above).

### Menus
- **menu-completo.php** – Comprehensive menu definition used throughout the admin UI.
- **menu-dashboard.php** – Menu configuration for the dashboard page.

### Routines / Automation Scripts
- **force-git-pull.php** – Pulls the latest Git repository state.
- **sync-critical-files.php** – Ensures critical configuration files are in sync.
- **force-sync-now.sh** – Shell script to trigger an immediate synchronization.

---

## 3. API & Webhooks
### Endpoints
- **api/health.php** – Health‑check endpoint.
- **api/agent/** – Directory containing agent‑related endpoints (e.g., `agent_status.php`).
- **api/product/** – Product‑related API routes.
- **api/user/** – User management endpoints.
- **api/order/** – Order processing APIs.
- **api/catalog/** – Catalog data retrieval and manipulation endpoints.

### Webhook Files
- **webhook-mercadopago.php** – Handles MercadoPago webhook events.
- **webhook-infinitepay.php** – Handles InfinitePay webhook events.
- **webhook-payment-gateway.php** – Generic payment gateway webhook handler.
- **webhook-notification.php** – Generic notification webhook endpoint.

---

## 4. Automation & Routine Scripts
- **automation/** – Directory containing various automation scripts:
  - **sync-all-pages-full.php** – Full site page synchronization.
  - **audit-deep-*.md** – Series of deep audit reports (e.g., `audit-deep-2026-07-23_22-59-41.md`).
  - **fix-bundle-*.bundle** – Bundles of fixes for specific issues (e.g., `fix-bundle-20260805.bundle`).
  - **menu-links.bundle**, **now()-interval**, **sync-ai-modules.bundle** – Miscellaneous utility scripts.
- **deployment/** – Deployment‑related scripts:
  - **DEPLOY-NOW.PS1** – PowerShell deployment script.
  - **force-sync-now.sh** – Bash script to force a sync operation.
  - **git-auto-sync.py** – Python utility for automatic Git syncing.
  - **sync-cache-endpoint.php** – Caches synchronization endpoint.
  - **sync-olist-para-products.php** – Syncs Olist product data.

---

## 5. Core & Core Components
- **core/** – Core framework files:
  - **config/** – Configuration files (e.g., `config.php`).
  - **constants/** – Constant definitions.
  - **functions/** – Core utility functions.
  - **logger/** – Logging utilities.
  - **router/** – Request routing logic.

---

## 6. Includes
- **includes/** – Shared include files:
  - **core_functions.php** – Core PHP functions.
  - **navbar.php** – Navigation bar template used across pages.
  - **auth.php** – Authentication handling.
  - **db_connect.php** – Database connection wrapper.

---

## 7. Modules & Extensions
- **modules/** – Currently empty but designated for extensibility.
- **extensions/** – Optional plug‑in modules (not currently populated).

---

## 8. Assets & Static Resources
- **public/** – Web‑accessible assets:
  - **css/** – Stylesheet files.
  - **js/** – JavaScript bundles.
  - **images/** – Image resources, including product images and UI graphics.
  - **fonts/** – Custom font files.

---

### Additional Notes
- **Routines**: Many automated tasks are triggered via cron‑like scripts (e.g., `force-git-pull.php`, `sync-critical-files.php`).
- **Pages**: Both front‑end and admin pages are PHP files located primarily under `admin/` and the root directory.
- **Menus**: Defined in PHP files (`menu-completo.php`, `menu-dashboard.php`) and included via `navbar.php`.
- **Webhooks**: Located in the root and `api/` directories, each handling a specific external service notification.

*This index provides a structured overview of all discovered routines, pages, menus, webhook endpoints, and related automation within the project.*
