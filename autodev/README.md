# AutoDev — Continuous Optimization System

AutoDev is a self-improving optimization layer for the ShopVivaliz e-commerce site.
It collects real user events, computes funnel metrics, runs A/B experiments, makes
data-driven layout decisions, and validates every change through Playwright tests
before any PR is opened — all on a 30-minute automated cycle.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        GitHub Actions                           │
│  schedule / push / workflow_dispatch  (every 30 min)            │
│                                                                 │
│  ┌─────────────┐     ┌──────────────────┐   ┌───────────────┐  │
│  │  validate   │────▶│     autodev      │   │metrics-       │  │
│  │ (Playwright │     │ (decision_engine │   │snapshot       │  │
│  │  checkout + │     │  .php)           │   │(metrics_cli   │  │
│  │  regression)│     │                  │   │ .php snapshot)│  │
│  └─────────────┘     └──────────────────┘   └───────────────┘  │
│                              │                       │          │
│                        opens PR / no-op         commits to      │
│                                                  autodev/data/  │
└─────────────────────────────────────────────────────────────────┘

Site (PHP pages)
  │
  ├── autodev/integration/tracker.php  ← include on every page
  │         │
  │         └── autodev/core/event_collector.php
  │                       │
  │                       └── autodev/data/events.log   (JSONL)
  │
  ├── autodev/core/metrics_engine.php  ← funnel math
  │         └── autodev/data/metrics_history.json
  │
  ├── autodev/core/decision_engine.php ← reads metrics, proposes changes
  │         └── autodev/data/layout_config.json  ← consumed by frontend
  │
  └── autodev/validation/             ← Playwright test suite
            ├── playwright_checkout.spec.js
            └── regression.spec.js
```

---

## Integrating Event Tracking into Site Pages

Include `tracker.php` at the top of every PHP page (after session_start, before any output):

```php
<?php
// At the very top of each page file:
session_start();

// AutoDev tracking — safe to include everywhere, uses try/catch internally
require_once __DIR__ . '/autodev/integration/tracker.php';
// tracker.php auto-detects the page type from the URL and fires the right event.
// It also injects a small JS bounce-detection snippet (< 1 KB, async beacon).
?>
<!DOCTYPE html>
<html>
...
```

If you need to fire a custom event (e.g., after a dynamic add-to-cart):

```php
<?php
require_once __DIR__ . '/autodev/core/event_collector.php';

track_event('add_to_cart', [
    'product_id' => $produto['id'],
    'product_name' => $produto['nome'],
    'price' => $produto['preco'],
]);
?>
```

Valid event types: `page_view`, `product_view`, `add_to_cart`, `checkout_start`,
`order_complete`, `bounce`.

---

## How A/B Tests Work

1. `decision_engine.php` reads `metrics_history.json` and detects when a metric
   (e.g., `checkout_abandon`) is above the threshold.
2. It creates or updates an experiment entry in `autodev/data/layout_config.json`.
3. Pages that read `layout_config.json` render the variant assigned to the current
   session bucket (determined by the `autodev_session` cookie CRC mod 2).
4. After enough traffic (configurable in `decision_engine.php`), the engine
   promotes the winning variant and archives the experiment.

Example `layout_config.json` structure:

```json
{
  "cta_button_color": "green",
  "hero_banner_variant": "B",
  "experiments": {
    "checkout_flow": {
      "status": "running",
      "variants": ["control", "simplified"],
      "traffic_split": 0.5
    }
  }
}
```

To read the config in a theme/layout file:

```php
<?php
$layout_config_path = __DIR__ . '/autodev/data/layout_config.json';
$layout = file_exists($layout_config_path)
    ? json_decode(file_get_contents($layout_config_path), true)
    : [];

$cta_color = $layout['cta_button_color'] ?? 'blue';
$hero_variant = $layout['hero_banner_variant'] ?? 'A';
?>
<button style="background:<?= htmlspecialchars($cta_color) ?>">Comprar agora</button>
```

---

## How to Read Metrics

Run the CLI tool manually:

```bash
# Full funnel report (last 24 h)
php autodev/core/metrics_cli.php report

# Single snapshot (calculates and persists current state)
php autodev/core/metrics_cli.php snapshot

# Threshold check (exits 1 if any metric needs attention)
php autodev/core/metrics_cli.php check
```

Key metrics tracked:

| Metric | Description |
|--------|-------------|
| `conversion_rate` | orders / unique sessions |
| `checkout_abandon` | (checkout_start − orders) / checkout_start |
| `cart_abandon` | (add_to_cart − checkout_start) / add_to_cart |
| `avg_session_pages` | page views per session |
| `bounce_rate` | sessions with only 1 event / total sessions |

Snapshots are stored in `autodev/data/metrics_history.json` (capped at 48 entries ≈ 24 h of 30-min cycles).

---

## Manual Trigger

Run the full decision cycle locally (no side-effects to git, just outputs proposed changes):

```bash
php autodev/core/decision_engine.php
```

To trigger the GitHub Actions workflow manually:

```bash
gh workflow run autodev.yml
```

---

## Secrets Required

| Secret | Purpose |
|--------|---------|
| `GH_TOKEN` | Personal access token with `repo` scope — used by gh CLI to create PRs |
| `SITE_URL` | Production URL for Playwright tests (default: `https://shopvivaliz.com.br`) |
| `NOTIFICATION_WEBHOOK_URL` | Optional — Slack/Discord/etc. webhook for failure alerts |

`GITHUB_TOKEN` is built-in and does not need to be configured.
