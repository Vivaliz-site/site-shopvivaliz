# Google Ads Optimization Intelligence Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build and live-verify a strictly read-only Google Ads intelligence pipeline that explains optimization-score/recommendation changes, audits real performance and conversion health, classifies recommendations with fail-closed guardrails, and produces a sanitized machine-readable report from VM2 without mutating the Ads account.

**Architecture:** Move Google Ads REST/OAuth/GAQL logic out of the workflow heredoc into focused Python modules under `scripts/google_ads/`. The collector gathers normalized evidence, pure modules compute metrics and policy decisions, and `audit.py` writes a sanitized JSON report. GitHub Actions only SSHes to VM2 and invokes the read-only CLI; mutation remains out of scope for this plan.

**Tech Stack:** Python 3 standard library (`urllib`, `json`, `dataclasses`, `pathlib`, `argparse`), Google Ads REST API v25, GAQL, `unittest`, GitHub Actions YAML, SSH to VM2.

**Spec:** `docs/superpowers/specs/2026-08-29-google-ads-optimization-intelligence-design.md`

## Global Constraints

- Production target is VM2 `shopvivaliz-micro-2` (`136.248.69.116`), not VM1.
- Google Ads credentials are read only from `/home/ubuntu/shopvivaliz-deploy/shared/.env` on VM2 and must never be committed, printed, logged, or persisted in artifacts.
- Phase 1 is read-only. No Google Ads mutation endpoint or mutate request may be introduced.
- Unknown recommendation types must classify as `REVIEW`.
- Any partial dataset or uncertain conversion tracking must block scaling-oriented `APPLY` decisions.
- No fabricated CPA, ROAS, conversion value, revenue, margin, or attribution data.
- Missing business thresholds keep economics-dependent actions at `REVIEW` or `TEST`.
- No automatic budget increase, broad-match expansion, bidding migration, campaign/ad/keyword removal, or Performance Max expansion in Phase 1.
- Implementation follows repository-required test, commit, PR, merge, checks, deploy, and live smoke flow.
- A blocked live validation is `INCONCLUSIVE`, never success.

---

## File Structure

- `scripts/google_ads/__init__.py` — package marker only.
- `scripts/google_ads/client.py` — environment loading, OAuth refresh, read-only Google Ads REST transport, error sanitization.
- `scripts/google_ads/collector.py` — GAQL datasets and normalized multi-window evidence.
- `scripts/google_ads/metrics.py` — pure safe arithmetic, trends, sufficiency, and tracking-health helpers.
- `scripts/google_ads/policy.py` — `APPLY` / `TEST` / `REVIEW` / `REJECT` recommendation classification and guardrails.
- `scripts/google_ads/report.py` — stable sanitized JSON schema and recursive secret redaction.
- `scripts/google_ads/audit.py` — CLI orchestration; no mutation code.
- `tests/google_ads/test_client.py` — env/error/redaction transport tests with mocks only.
- `tests/google_ads/test_metrics.py` — zero-safe metric and trend tests.
- `tests/google_ads/test_policy.py` — classification/guardrail tests.
- `tests/google_ads/test_report.py` — stable schema and secret-leak tests.
- `tests/google_ads/test_collector.py` — normalization and partial-data behavior tests.
- `.github/workflows/google-ads-rest-audit.yml` — thin VM2 launcher for `audit.py` plus report retrieval/validation.
- `ops/google-ads/config.json` — non-secret thresholds and protected-term configuration; defaults must be conservative.
- `ops/google-ads/latest-readonly-audit.json` — sanitized source-of-truth report written only after a successful live audit and inspected for secrets.
- `docs/AGENTS.md` — append only if live execution reveals a non-obvious operational fact.

---

### Task 1: Read-only REST client with sanitized failures

**Files:**
- Create: `scripts/google_ads/__init__.py`
- Create: `scripts/google_ads/client.py`
- Create: `tests/google_ads/test_client.py`

**Interfaces:**
- Produces: `load_env(path: Path) -> dict[str, str]`
- Produces: `GoogleAdsClient.from_env(env: dict[str, str], api_version: str = "v25") -> GoogleAdsClient`
- Produces: `GoogleAdsClient.query(label: str, gaql: str) -> list[dict]`
- Produces: `GoogleAdsError(label: str, status: str, message: str, reasons: tuple[str, ...])`
- Constraint: client exposes query/search only; no mutate method exists.

- [ ] **Step 1: Write failing client tests**

Create `tests/google_ads/test_client.py` with deterministic tests that never contact Google:

```python
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from scripts.google_ads.client import GoogleAdsClient, GoogleAdsError, load_env


class ClientTests(unittest.TestCase):
    def test_load_env_ignores_comments_and_quotes(self):
        with tempfile.TemporaryDirectory() as td:
            p = Path(td) / ".env"
            p.write_text("# comment\nA=1\nB='two'\nC=\"three\"\n", encoding="utf-8")
            self.assertEqual(load_env(p), {"A": "1", "B": "two", "C": "three"})

    def test_from_env_requires_expected_keys_without_values_in_error(self):
        env = {"GOOGLE_OAUTH_CLIENT_ID": "secret-client"}
        with self.assertRaises(ValueError) as ctx:
            GoogleAdsClient.from_env(env)
        text = str(ctx.exception)
        self.assertIn("missing Google Ads environment keys", text)
        self.assertNotIn("secret-client", text)

    @patch("scripts.google_ads.client.urllib.request.urlopen")
    def test_query_normalizes_stream_batches(self, urlopen):
        token_response = unittest.mock.MagicMock()
        token_response.__enter__.return_value.read.return_value = json.dumps({"access_token": "access-secret"}).encode()
        query_response = unittest.mock.MagicMock()
        query_response.__enter__.return_value.read.return_value = json.dumps([
            {"results": [{"campaign": {"id": "1"}}]},
            {"results": [{"campaign": {"id": "2"}}]},
        ]).encode()
        urlopen.side_effect = [token_response, query_response]
        client = GoogleAdsClient.from_env({
            "GOOGLE_OAUTH_CLIENT_ID": "cid",
            "GOOGLE_OAUTH_CLIENT_SECRET": "client-secret",
            "GOOGLE_ADS_REFRESH_TOKEN": "refresh-secret",
            "GOOGLE_ADS_DEVELOPER_TOKEN": "dev-secret",
            "GOOGLE_ADS_CUSTOMER_ID": "123-456-7890",
        })
        rows = client.query("campaigns", "SELECT campaign.id FROM campaign")
        self.assertEqual([r["campaign"]["id"] for r in rows], ["1", "2"])

    def test_client_has_no_mutate_surface(self):
        self.assertFalse(hasattr(GoogleAdsClient, "mutate"))


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run tests and confirm RED**

Run:

```bash
python3 -m unittest tests.google_ads.test_client -v
```

Expected: import/module failures because `scripts/google_ads/client.py` does not exist yet.

- [ ] **Step 3: Implement minimal read-only client**

Create `scripts/google_ads/client.py` with:

```python
from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from pathlib import Path

REQUIRED_KEYS = (
    "GOOGLE_OAUTH_CLIENT_ID",
    "GOOGLE_OAUTH_CLIENT_SECRET",
    "GOOGLE_ADS_REFRESH_TOKEN",
    "GOOGLE_ADS_DEVELOPER_TOKEN",
    "GOOGLE_ADS_CUSTOMER_ID",
)


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip("\"'")
    return env


@dataclass(frozen=True)
class GoogleAdsError(RuntimeError):
    label: str
    status: str
    message: str
    reasons: tuple[str, ...] = ()

    def __str__(self) -> str:
        reason_text = ", ".join(self.reasons)
        return f"{self.label}: {self.status}: {self.message}" + (f" ({reason_text})" if reason_text else "")


class GoogleAdsClient:
    def __init__(self, env: dict[str, str], api_version: str = "v25") -> None:
        self._env = dict(env)
        self.api_version = api_version
        self.customer_id = env["GOOGLE_ADS_CUSTOMER_ID"].replace("-", "").strip()
        self.login_customer_id = env.get("GOOGLE_ADS_LOGIN_CUSTOMER_ID", "").replace("-", "").strip()
        self._access_token: str | None = None

    @classmethod
    def from_env(cls, env: dict[str, str], api_version: str = "v25") -> "GoogleAdsClient":
        missing = [key for key in REQUIRED_KEYS if not env.get(key, "").strip()]
        if missing:
            raise ValueError("missing Google Ads environment keys: " + ",".join(missing))
        return cls(env, api_version)

    def _refresh_access_token(self) -> str:
        data = urllib.parse.urlencode({
            "client_id": self._env["GOOGLE_OAUTH_CLIENT_ID"],
            "client_secret": self._env["GOOGLE_OAUTH_CLIENT_SECRET"],
            "refresh_token": self._env["GOOGLE_ADS_REFRESH_TOKEN"],
            "grant_type": "refresh_token",
        }).encode()
        req = urllib.request.Request(
            "https://oauth2.googleapis.com/token",
            data=data,
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=20) as response:
            token = json.loads(response.read().decode()).get("access_token", "")
        if not token:
            raise GoogleAdsError("oauth_refresh", "INVALID_RESPONSE", "access token missing")
        self._access_token = token
        return token

    def query(self, label: str, gaql: str) -> list[dict]:
        token = self._access_token or self._refresh_access_token()
        headers = {
            "Content-Type": "application/json",
            "Authorization": "Bearer " + token,
            "developer-token": self._env["GOOGLE_ADS_DEVELOPER_TOKEN"],
        }
        if self.login_customer_id:
            headers["login-customer-id"] = self.login_customer_id
        url = f"https://googleads.googleapis.com/{self.api_version}/customers/{self.customer_id}/googleAds:searchStream"
        req = urllib.request.Request(url, data=json.dumps({"query": gaql}).encode(), headers=headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=30) as response:
                payload = json.loads(response.read().decode())
        except urllib.error.HTTPError as exc:
            body = exc.read().decode(errors="ignore")
            try:
                data = json.loads(body)
            except Exception:
                data = {}
            err = data.get("error", {}) if isinstance(data, dict) else {}
            status = str(err.get("status") or f"HTTP_{exc.code}")
            message = str(err.get("message") or "Google Ads API request failed")[:700]
            reasons: list[str] = []
            for detail in err.get("details") or []:
                for item in detail.get("errors") or []:
                    for key, value in (item.get("errorCode") or {}).items():
                        reasons.append(f"{key}={value}")
            raise GoogleAdsError(label, status, message, tuple(dict.fromkeys(reasons))) from None
        rows: list[dict] = []
        for batch in payload if isinstance(payload, list) else []:
            if isinstance(batch, dict):
                rows.extend(batch.get("results") or [])
        return rows
```

Create an empty `scripts/google_ads/__init__.py`.

- [ ] **Step 4: Run client tests and confirm GREEN**

Run:

```bash
python3 -m unittest tests.google_ads.test_client -v
```

Expected: all tests pass.

- [ ] **Step 5: Commit Task 1**

```bash
git add scripts/google_ads/__init__.py scripts/google_ads/client.py tests/google_ads/test_client.py
git commit -m "feat(ads): add read-only Google Ads REST client"
```

---

### Task 2: Safe metrics and tracking-health engine

**Files:**
- Create: `scripts/google_ads/metrics.py`
- Create: `tests/google_ads/test_metrics.py`

**Interfaces:**
- Produces: `safe_div(numerator: float, denominator: float) -> float | None`
- Produces: `derive_metrics(row: dict) -> dict`
- Produces: `trend(current: float | None, baseline: float | None) -> float | None`
- Produces: `tracking_health(conversion_actions: list[dict], windows: dict[str, list[dict]]) -> dict`

- [ ] **Step 1: Write failing metric tests**

Create tests that assert no invented values on zero cost/clicks/conversions and that tracking becomes `unknown` when purchase evidence is absent:

```python
import unittest
from scripts.google_ads.metrics import derive_metrics, safe_div, tracking_health, trend


class MetricsTests(unittest.TestCase):
    def test_safe_div_zero_returns_none(self):
        self.assertIsNone(safe_div(10, 0))

    def test_derive_metrics_does_not_invent_cpa_or_roas(self):
        out = derive_metrics({"clicks": 0, "impressions": 100, "cost": 0.0, "conversions": 0.0, "conversion_value": 0.0})
        self.assertEqual(out["ctr"], 0.0)
        self.assertIsNone(out["cpc"])
        self.assertIsNone(out["cpa"])
        self.assertIsNone(out["roas"])
        self.assertIsNone(out["conversion_rate"])

    def test_trend_requires_nonzero_baseline(self):
        self.assertIsNone(trend(10.0, 0.0))
        self.assertEqual(trend(12.0, 10.0), 0.2)

    def test_tracking_unknown_without_purchase_action(self):
        health = tracking_health([], {"7d": []})
        self.assertEqual(health["status"], "unknown")
        self.assertIn("purchase_conversion_action_missing", health["reasons"])
```

- [ ] **Step 2: Run tests and confirm RED**

```bash
python3 -m unittest tests.google_ads.test_metrics -v
```

Expected: module import failure.

- [ ] **Step 3: Implement metric functions**

Implement `scripts/google_ads/metrics.py` so field inputs are numeric-normalized, divisions are zero-safe, and `tracking_health` returns one of `healthy`, `unknown`, or `unhealthy` plus reasons. Treat a purchase action with no recent conversions as `unknown`, not `healthy`.

Required implementation contract:

```python
def safe_div(numerator, denominator):
    if denominator in (None, 0, 0.0):
        return None
    return float(numerator) / float(denominator)


def trend(current, baseline):
    if current is None or baseline in (None, 0, 0.0):
        return None
    return (float(current) - float(baseline)) / float(baseline)
```

`derive_metrics` must return keys `ctr`, `cpc`, `cpa`, `roas`, `conversion_rate`, preserving `None` where math is not valid.

- [ ] **Step 4: Run metric tests and confirm GREEN**

```bash
python3 -m unittest tests.google_ads.test_metrics -v
```

- [ ] **Step 5: Commit Task 2**

```bash
git add scripts/google_ads/metrics.py tests/google_ads/test_metrics.py
git commit -m "feat(ads): add safe metrics and tracking health"
```

---

### Task 3: Multi-window collector and optimization/recommendation evidence

**Files:**
- Create: `scripts/google_ads/collector.py`
- Create: `tests/google_ads/test_collector.py`

**Interfaces:**
- Consumes: `GoogleAdsClient.query(label, gaql)`
- Produces: `collect_account(client: GoogleAdsClient) -> dict`
- Produces top-level keys: `customer`, `recommendations`, `conversion_actions`, `windows`, `errors`, `partial`
- `windows` contains exactly `1d`, `3d`, `7d`, `30d`; each includes `campaigns`, `ad_groups`, `keywords`, `search_terms`, `ads`.

- [ ] **Step 1: Write failing collector normalization tests**

Use a fake client that records query labels and returns fixture rows. Assert all four windows are requested, unknown/missing optimization-score fields do not fail collection, and one failed dataset marks `partial=True` while preserving successful datasets.

Representative fake:

```python
class FakeClient:
    customer_id = "1234567890"
    api_version = "v25"
    def __init__(self, fail_label=None):
        self.fail_label = fail_label
        self.labels = []
    def query(self, label, gaql):
        self.labels.append(label)
        if label == self.fail_label:
            raise RuntimeError("fixture failure")
        if label == "customer":
            return [{"customer": {"id": "1234567890", "descriptiveName": "Shop Vivaliz", "optimizationScore": 0.82}}]
        if label == "recommendations":
            return [{"recommendation": {"resourceName": "customers/123/recommendations/abc", "type": "KEYWORD", "campaign": "customers/123/campaigns/1"}}]
        return []
```

- [ ] **Step 2: Run collector tests and confirm RED**

```bash
python3 -m unittest tests.google_ads.test_collector -v
```

- [ ] **Step 3: Implement explicit GAQL collectors**

Implement named GAQL queries, avoiding dynamic user input. Use these source resources:

- `customer` for `customer.id`, `customer.descriptive_name`, `customer.optimization_score` where supported;
- `recommendation` for resource name, type, campaign/ad group where available, and recommendation impact/uplift fields supported by API v25;
- `conversion_action` for enabled purchase/sale actions and counting/value configuration;
- `campaign`, `ad_group`, `keyword_view`, `search_term_view`, and `ad_group_ad` for each date window;
- campaign budget/bidding fields included with campaign data.

Use explicit date filters per window:

```python
WINDOWS = {
    "1d": "YESTERDAY",
    "3d": "LAST_3_DAYS",
    "7d": "LAST_7_DAYS",
    "30d": "LAST_30_DAYS",
}
```

If API v25 rejects a specific field or predefined range in live validation, adjust only that query to an equivalent supported date predicate and record the compatibility fact in `docs/AGENTS.md`.

Collector errors must be sanitized into objects like:

```json
{"dataset":"search_terms","window":"7d","status":"failed","reason":"QUERY_ERROR"}
```

Never persist raw headers or `.env` values.

- [ ] **Step 4: Run collector tests and full unit suite**

```bash
python3 -m unittest discover -s tests/google_ads -v
```

Expected: all Task 1-3 tests pass.

- [ ] **Step 5: Commit Task 3**

```bash
git add scripts/google_ads/collector.py tests/google_ads/test_collector.py
git commit -m "feat(ads): collect multi-window optimization evidence"
```

---

### Task 4: Policy classifier and conservative guardrails

**Files:**
- Create: `scripts/google_ads/policy.py`
- Create: `tests/google_ads/test_policy.py`
- Create: `ops/google-ads/config.json`

**Interfaces:**
- Produces: `classify_recommendation(recommendation: dict, evidence: dict, config: dict) -> dict`
- Output keys: `classification`, `reason_codes`, `blocked_by`, `recommendation_type`, `resource_name`, `evidence`
- `classification` is exactly one of `APPLY`, `TEST`, `REVIEW`, `REJECT`.

- [ ] **Step 1: Write failing policy tests for the hard gates**

Tests must include:

```python
import unittest
from scripts.google_ads.policy import classify_recommendation

CONFIG = {
    "min_recent_conversions_for_scaling": 15,
    "max_budget_increase_pct": 0.10,
    "min_search_term_clicks_for_negative_candidate": 20,
    "min_search_term_spend_brl_for_negative_candidate": 30.0,
    "protected_terms": ["vivaliz"],
}


class PolicyTests(unittest.TestCase):
    def test_unknown_type_fails_closed(self):
        out = classify_recommendation({"type": "FUTURE_UNKNOWN", "resource_name": "x"}, {"tracking_health": "healthy"}, CONFIG)
        self.assertEqual(out["classification"], "REVIEW")

    def test_budget_scaling_blocked_when_tracking_unknown(self):
        out = classify_recommendation({"type": "CAMPAIGN_BUDGET", "resource_name": "x", "proposed_increase_pct": 0.05}, {"tracking_health": "unknown", "recent_conversions": 50}, CONFIG)
        self.assertNotEqual(out["classification"], "APPLY")
        self.assertIn("tracking_not_healthy", out["blocked_by"])

    def test_budget_over_ten_percent_never_apply(self):
        out = classify_recommendation({"type": "CAMPAIGN_BUDGET", "resource_name": "x", "proposed_increase_pct": 0.20}, {"tracking_health": "healthy", "recent_conversions": 100}, CONFIG)
        self.assertNotEqual(out["classification"], "APPLY")
        self.assertIn("budget_change_exceeds_cap", out["blocked_by"])

    def test_broad_match_defaults_review_without_strong_evidence(self):
        out = classify_recommendation({"type": "USE_BROAD_MATCH_KEYWORD", "resource_name": "x"}, {"tracking_health": "healthy", "recent_conversions": 2, "negative_keyword_protection": False}, CONFIG)
        self.assertEqual(out["classification"], "REVIEW")
```

- [ ] **Step 2: Run policy tests and confirm RED**

```bash
python3 -m unittest tests.google_ads.test_policy -v
```

- [ ] **Step 3: Implement policy mapping with fail-closed default**

Implement explicit handlers for at least asset/RSA completeness, keyword suggestions, broad match, campaign budget, bidding strategy, dynamic/image assets, and Performance Max. Keep score uplift as evidence metadata only.

`ops/google-ads/config.json` must contain only non-secret conservative defaults:

```json
{
  "min_recent_conversions_for_scaling": 15,
  "max_budget_increase_pct": 0.10,
  "min_search_term_clicks_for_negative_candidate": 20,
  "min_search_term_spend_brl_for_negative_candidate": 30.0,
  "protected_terms": ["vivaliz", "shop vivaliz", "shopvivaliz"],
  "target_cpa_brl": null,
  "minimum_roas": null,
  "gross_margin_pct": null
}
```

When `target_cpa_brl`, `minimum_roas`, or `gross_margin_pct` are `null`, the engine must not make profitability-based `APPLY` decisions.

- [ ] **Step 4: Run policy and full unit suite**

```bash
python3 -m unittest discover -s tests/google_ads -v
```

- [ ] **Step 5: Commit Task 4**

```bash
git add scripts/google_ads/policy.py tests/google_ads/test_policy.py ops/google-ads/config.json
git commit -m "feat(ads): add recommendation policy guardrails"
```

---

### Task 5: Stable sanitized report and CLI orchestration

**Files:**
- Create: `scripts/google_ads/report.py`
- Create: `scripts/google_ads/audit.py`
- Create: `tests/google_ads/test_report.py`

**Interfaces:**
- Consumes: collector output, metric functions, policy classifier, `ops/google-ads/config.json`
- Produces: `build_report(collected: dict, config: dict) -> dict`
- Produces CLI: `python3 scripts/google_ads/audit.py --env PATH --config PATH --output PATH`
- Exit codes: `0` complete audit; `2` missing configuration/env; `3` authentication/API fatal failure; `4` partial audit. Exit 4 must still write a sanitized partial report when enough information exists.

- [ ] **Step 1: Write failing report sanitization tests**

Test recursive redaction against key names and exact secret fixture values. Assert report schema contains:

```json
{
  "schema_version": 1,
  "mode": "readonly",
  "api_version": "v25",
  "generated_at": "...",
  "customer": {},
  "optimization": {},
  "tracking_health": {},
  "windows": {},
  "findings": [],
  "decisions": [],
  "guardrails": [],
  "errors": [],
  "partial": false
}
```

Also assert JSON serialization contains none of fixture strings `refresh-secret`, `access-secret`, `client-secret`, or `dev-secret`.

- [ ] **Step 2: Run report tests and confirm RED**

```bash
python3 -m unittest tests.google_ads.test_report -v
```

- [ ] **Step 3: Implement report builder and CLI**

`audit.py` must:

```python
ENV_DEFAULT = "/home/ubuntu/shopvivaliz-deploy/shared/.env"
CONFIG_DEFAULT = "ops/google-ads/config.json"
OUTPUT_DEFAULT = "ops/google-ads/latest-readonly-audit.json"
```

Flow: load env -> create read-only client -> collect -> derive metrics/tracking health -> classify recommendations -> build sanitized report -> atomic write using `Path.replace()` from a temporary sibling file.

The CLI must print only concise non-secret status markers such as:

```text
GOOGLE_ADS_READONLY_AUDIT_OK
report=ops/google-ads/latest-readonly-audit.json
partial=false
recommendations=12
tracking_health=unknown
```

Do not print raw recommendation payloads or authorization material.

- [ ] **Step 4: Run unit suite and compile check**

```bash
python3 -m unittest discover -s tests/google_ads -v
python3 -m py_compile scripts/google_ads/*.py
```

Expected: all tests pass and compile succeeds.

- [ ] **Step 5: Commit Task 5**

```bash
git add scripts/google_ads/report.py scripts/google_ads/audit.py tests/google_ads/test_report.py
git commit -m "feat(ads): generate sanitized optimization audit report"
```

---

### Task 6: Replace workflow heredoc with thin read-only VM2 orchestration

**Files:**
- Modify: `.github/workflows/google-ads-rest-audit.yml`
- Test: workflow syntax plus CLI unit suite

**Interfaces:**
- Consumes repo scripts deployed/present on VM2 under `/home/ubuntu/site-shopvivaliz/` or the canonical checkout resolved by the workflow.
- Produces a live sanitized report and workflow log summary; performs no mutation.

- [ ] **Step 1: Capture current workflow behavior before editing**

Read `.github/workflows/google-ads-rest-audit.yml` and preserve its existing SSH secret contract `SHOPVIVALIZ_VM_SSH_KEY`, VM2 IP `136.248.69.116`, strict shell mode, and cleanup step.

- [ ] **Step 2: Replace inline Google API code with CLI invocation**

The core SSH step must run from the VM2 repository checkout and invoke:

```bash
cd /home/ubuntu/site-shopvivaliz
python3 scripts/google_ads/audit.py \
  --env /home/ubuntu/shopvivaliz-deploy/shared/.env \
  --config ops/google-ads/config.json \
  --output /tmp/google-ads-readonly-audit.json
```

Then run a small local-on-VM validation that parses `/tmp/google-ads-readonly-audit.json`, asserts `mode == "readonly"`, and scans serialized JSON for forbidden key names (`access_token`, `refresh_token`, `client_secret`, `developer_token`, `authorization`). Copy only the sanitized JSON text back into the workflow output/log or repository update path chosen by the execution agent after checking repository conventions; do not copy `.env` or any token material.

- [ ] **Step 3: Validate YAML and scripts**

Run:

```bash
python3 -m unittest discover -s tests/google_ads -v
python3 -m py_compile scripts/google_ads/*.py
python3 - <<'PY'
from pathlib import Path
text = Path('.github/workflows/google-ads-rest-audit.yml').read_text(encoding='utf-8')
assert 'google_ads/audit.py' in text
assert 'googleAds:mutate' not in text
assert 'mutate' not in text.lower() or 'no mutation' in text.lower()
print('WORKFLOW_STATIC_CHECK_OK')
PY
```

If PyYAML is already a repository dependency, additionally parse the workflow with it; do not add a dependency solely for this check.

- [ ] **Step 4: Commit Task 6**

```bash
git add .github/workflows/google-ads-rest-audit.yml
git commit -m "refactor(ads): run reusable readonly audit on VM2"
```

---

### Task 7: Live read-only verification on VM2 and compatibility fixes

**Files:**
- Potentially modify only the Task 1-6 files when live API compatibility requires it.
- Modify: `docs/AGENTS.md` only if a non-obvious API/runtime fact is learned.
- Create/update after inspection: `ops/google-ads/latest-readonly-audit.json` sanitized only.

**Interfaces:**
- Consumes live VM2 credentials from production `.env` in memory.
- Produces real read-only evidence and a sanitized report.

- [ ] **Step 1: Push implementation branch and run the audit workflow manually**

Use the repository-required PR flow. Trigger `google-ads-rest-audit.yml` with its supported manual/push mechanism after the implementation branch is ready. Do not bypass branch protection or force-push.

- [ ] **Step 2: Inspect workflow job logs for API compatibility**

Confirm markers:

```text
GOOGLE_ADS_READONLY_AUDIT_OK
partial=false
```

If a GAQL field/range is unsupported in v25, record the exact dataset and API reason, write a failing regression test that reproduces the normalization/compatibility branch, then make the smallest query adjustment and rerun unit tests before re-triggering the live audit.

- [ ] **Step 3: Validate the real report before committing it**

Check all of the following programmatically and by manual inspection:

```bash
python3 - <<'PY'
import json
from pathlib import Path
p = Path('ops/google-ads/latest-readonly-audit.json')
data = json.loads(p.read_text(encoding='utf-8'))
assert data['mode'] == 'readonly'
assert data['schema_version'] == 1
assert data['partial'] is False
assert data['customer']
assert 'tracking_health' in data
assert isinstance(data['decisions'], list)
blob = p.read_text(encoding='utf-8').lower()
for forbidden in ('access_token', 'refresh_token', 'client_secret', 'developer_token', 'authorization: bearer'):
    assert forbidden not in blob, forbidden
print('LIVE_REPORT_SANITIZED_OK')
PY
```

Do not require optimization score to exist; if the API does not expose it for the account/query shape, verify `optimization.score_available=false` and continue.

- [ ] **Step 4: Prove policy behavior on real evidence**

Verify at least one real recommendation has a `decisions[]` entry with a classification and reason based on observed evidence. Verify any budget/bidding/broad-match recommendation is not `APPLY` when `tracking_health != "healthy"` or business thresholds are unset.

- [ ] **Step 5: Commit sanitized report and any compatibility note**

Only after the secret scan passes:

```bash
git add ops/google-ads/latest-readonly-audit.json
[ -f docs/AGENTS.md ] && git add docs/AGENTS.md || true
git commit -m "ops(ads): record verified readonly optimization audit"
```

---

### Task 8: PR, checks, merge, deploy, and final acceptance evidence

**Files:**
- No new functional files expected.

**Interfaces:**
- Produces merged `main`, successful repository checks, production-visible read-only audit capability, and final evidence.

- [ ] **Step 1: Open PR with explicit safety statement**

PR description must state:

```text
Phase 1 only: read-only Google Ads intelligence.
No mutate endpoint, no budget changes, no bidding changes, no keyword/negative insertion, no campaign/ad removal.
Live validation target: VM2 136.248.69.116.
```

- [ ] **Step 2: Wait for and inspect required checks**

Do not merge on red checks. Fix failures with tests first. Confirm the commit SHA receiving checks matches the PR head SHA.

- [ ] **Step 3: Merge using repository-approved method**

Merge only after required checks pass; do not bypass protections.

- [ ] **Step 4: Verify deploy/current SHA on VM2**

Confirm VM2 deployed/current checkout includes the merged commit before declaring production verification complete.

- [ ] **Step 5: Re-run the read-only workflow from merged main**

Require a fresh successful run from `main`, inspect the sanitized report, and confirm no Ads mutations occurred.

- [ ] **Step 6: Final acceptance checklist**

All must be true:

```text
[PASS] Unit tests pass
[PASS] Python compile check passes
[PASS] Workflow calls reusable read-only CLI
[PASS] VM2 live API audit succeeds or score is explicitly unavailable without failing the audit
[PASS] Sanitized report contains real recommendation inventory/evidence
[PASS] Unknown recommendation types => REVIEW
[PASS] Tracking uncertainty blocks risky scaling
[PASS] Zero denominators never fabricate CPA/ROAS
[PASS] No mutation occurred in Phase 1
[PASS] PR/check/merge/deploy/smoke flow completed
```

If any live item cannot be verified, final status is `INCONCLUSIVE` with the exact blocker.

- [ ] **Step 7: Final completion commit only if documentation changed after merge preparation**

If live verification added a non-obvious operational note, put it through the normal PR/check/merge flow rather than committing directly to protected `main`.

---

## Plan Self-Review

- Spec coverage: Phase 1 acceptance criteria, read-only collection, optimization/recommendation evidence, multi-window metrics, conversion-health gate, fail-closed policy, sanitized ledger/report, workflow refactor, VM2 live verification, and repository safety flow are covered.
- Scope boundary: Phase 2 mutation and Phase 3 recursive optimization are intentionally excluded until Phase 1 live data is verified. A separate approved implementation plan must cover mutations.
- Placeholder scan: no `TBD`, `TODO`, or unspecified implementation steps remain.
- Type/interface consistency: all cross-task interfaces are named in the task headers; policy output and report schema are stable across Tasks 4-8.
- Safety: no task introduces a Google Ads mutate call, and live validation explicitly checks for mutation absence and secret leakage.
