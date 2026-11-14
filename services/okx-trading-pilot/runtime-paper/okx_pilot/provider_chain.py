"""Ordered PAPER decision providers: Gemini > Ollama > ChatGPT normal > Codex.

Unavailable or invalid providers fall through. No provider can place an order.
Each generated decision must pass the same strict 20-layer DecisionParser.
"""
from __future__ import annotations

from datetime import datetime, timedelta, timezone
from decimal import Decimal
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import stat
from threading import Lock
import time
from urllib.error import HTTPError, URLError
from urllib.parse import urlsplit
from urllib.request import Request, urlopen
from uuid import uuid4

from .decision import (
    CodexBridgeDecisionProvider, DecisionParser, DecisionValidationError,
    REQUIRED_LAYER_NAMES, _json_safe,
)

PROVIDER_ORDER = ("gemini", "ollama", "chatgpt_normal", "codex")
GEMINI_ENV = Path("/home/ubuntu/.config/shopvivaliz-gemini-24x7/gemini.env")
GEMINI_PROOF = Path("/home/ubuntu/shopvivaliz-deploy/shared/ai-free-policy/gemini-free-tier.json")


def _post_json(url: str, payload: dict, *, timeout: int, headers: dict | None = None):
    body = json.dumps(payload, separators=(",", ":"), default=str).encode("utf-8")
    req = Request(url, data=body, headers={"Content-Type": "application/json", **(headers or {})}, method="POST")
    try:
        with urlopen(req, timeout=timeout) as response:
            return json.load(response)
    except HTTPError as exc:
        # Never print bodies, which may contain key material and external data.
        raise DecisionValidationError(f"upstream_http_{exc.code}") from None
    except (URLError, TimeoutError, ValueError, OSError) as exc:
        raise DecisionValidationError(f"upstream_{type(exc).__name__}") from None


def _read_free_key(env_path: Path, proof_path: Path) -> str:
    """Never run a Gemini API request without current proof for the exact key."""
    try:
        meta = proof_path.stat()
        if not stat.S_ISREG(meta.st_mode):
            raise ValueError("proof_not_regular")
        proof_mode = stat.S_IMODE(meta.st_mode)
        if proof_mode & 0o077:
            # systemd LoadCredential uses root-owned 0440 files in its private
            # root-owned 0550 directory, and grants the service access through
            # a protected runtime mount. This is not a world-readable secret.
            credential_root = os.environ.get("CREDENTIALS_DIRECTORY", "")
            if not credential_root.startswith("/run/credentials/"):
                raise ValueError("proof_permissions")
            private_root = Path(credential_root)
            root_meta = private_root.stat()
            if not (
                proof_path == private_root / "gemini-free-tier.json"
                and not proof_path.is_symlink()
                and proof_mode == 0o440
                and meta.st_uid == 0
                and stat.S_ISDIR(root_meta.st_mode)
                and root_meta.st_uid == 0
                and stat.S_IMODE(root_meta.st_mode) == 0o550
            ):
                raise ValueError("proof_permissions")
        proof = json.loads(proof_path.read_text())
        if (proof.get("schema_version") != 1 or proof.get("billing_tier") != "free"
                or proof.get("verified_source") != "google_ai_studio"
                or not str(proof.get("project_id") or "").strip()):
            raise ValueError("proof_invalid")
        timestamp = datetime.fromisoformat(str(proof["verified_at"]).replace("Z", "+00:00"))
        if timestamp.tzinfo is None or not timedelta(0) <= datetime.now(timezone.utc) - timestamp <= timedelta(days=30):
            raise ValueError("proof_expired")
        approved = proof.get("key_sha256")
        if not isinstance(approved, list) or not approved:
            raise ValueError("proof_hashes")
        entries = {}
        for line in env_path.read_text().splitlines():
            if line.lstrip().startswith("#") or "=" not in line:
                continue
            key, value = line.replace("export ", "", 1).split("=", 1)
            key = key.strip()
            if key in {"GEMINI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_GEMINI_API_KEY"}:
                entries[key] = value.strip().strip("'\"")
        for key in ("GEMINI_API_KEY", "GOOGLE_API_KEY", "GOOGLE_GEMINI_API_KEY"):
            value = entries.get(key, "")
            if value and any(isinstance(d, str) and hmac.compare_digest(hashlib.sha256(value.encode()).hexdigest(), d)
                             for d in approved):
                return value
    except (OSError, ValueError, TypeError, KeyError):
        pass
    raise DecisionValidationError("gemini_free_tier_not_verified")


def _compact_schema():
    properties = {
        "decision": {"type": "string", "enum": ["TRADE", "HOLD", "REJECT"]},
        "direction": {"type": "string", "enum": ["LONG", "SHORT"]},
        "entry_low": {"type": "number"},
        "entry_high": {"type": "number"},
        "stop": {"type": "number"},
        "targets": {"type": "array", "items": {"type": "number"}},
        "suggested_risk": {"type": "number"},
        "suggested_leverage": {"type": "number"},
        "confidence": {"type": "number"},
        "expected_rr": {"type": "number"},
        "thesis": {"type": "string"},
        "supporting_evidence": {"type": "string"},
        "contrary_evidence": {"type": "string"},
        "invalidation": {"type": "string"},
        "assessments": {"type": "array", "items": {"type": "string"}, "minItems": 20, "maxItems": 20},
        "evidence": {"type": "array", "items": {"type": "string"}, "minItems": 20, "maxItems": 20},
    }
    return {"type": "object", "properties": properties, "required": list(properties), "additionalProperties": False}


def _compact_prompt(snapshot, context: dict):
    market = {
        "instrument": snapshot.instrument, "instrument_type": snapshot.instrument_type.value,
        "bid": str(snapshot.bid), "ask": str(snapshot.ask), "last": str(snapshot.last),
        "open_24h": str(snapshot.open_24h), "volume_24h": str(snapshot.volume_24h),
        "timestamp": snapshot.timestamp.isoformat(),
    }
    context_json = json.dumps(_json_safe(context), separators=(",", ":"))
    # Missing/truncated evidence must not be invented to authorize a trade.
    if len(context_json) > 23000:
        raise DecisionValidationError("context_too_large")
    return (
        "PAPER-only OKX decision engine. No real orders. Use only MARKET and CONTEXT; no web. "
        "Output one JSON object matching the schema. The assessments and evidence arrays each "
        "must have exactly 20 SHORT strings, one per ordered layer. Every assessment needs factual "
        "evidence or must explicitly say unavailable. Layers in order: "
        + ",".join(REQUIRED_LAYER_NAMES) + ". "
        "If data is missing, conflicting, stale or insufficient choose HOLD or REJECT; never invent evidence. "
        "TRADE requires true evidence, confidence >=70, expected_rr >=1.5, positive risk <=10, "
        "and valid entry/stop/target; spot SHORT is forbidden. "
        "For HOLD/REJECT use suggested_risk=0, expected_rr=0, entry_low=0, entry_high=0, stop=0, targets=[] "
        "and suggested_leverage=0. Include supporting and contrary evidence, explicit thesis and invalidation. "
        "Numbers must be numeric, not 'unknown'; a confidence number between 0 and 100. "
        "Keep everything concise and do not pretend evidence exists. "
        "MARKET=" + json.dumps(market, separators=(",", ":")) + " CONTEXT=" + context_json
    )


def _expand_compact(result: dict, snapshot):
    if not isinstance(result, dict):
        raise DecisionValidationError("compact_response_not_object")
    assessments, evidence = result.get("assessments"), result.get("evidence")
    if not (isinstance(assessments, list) and isinstance(evidence, list)
            and len(assessments) == 20 and len(evidence) == 20):
        raise DecisionValidationError("compact_20_layers_missing")
    now = datetime.now(timezone.utc)
    out = {k: v for k, v in result.items() if k not in ("assessments", "evidence")}
    out.update({
        "decision_id": str(uuid4()),
        "instrument": snapshot.instrument,
        "instrument_type": snapshot.instrument_type.value,
        "time_horizon": "intraday",
        "market_snapshot_ts": snapshot.timestamp.isoformat(),
        "created_at": now.isoformat(),
        "expires_at": (now + timedelta(seconds=120)).isoformat(),
        "supporting_evidence": [out["supporting_evidence"]] if isinstance(out.get("supporting_evidence"), str) else out.get("supporting_evidence"),
        "contrary_evidence": [out["contrary_evidence"]] if isinstance(out.get("contrary_evidence"), str) else out.get("contrary_evidence"),
        "layers": [
            {"id": i, "name": name, "assessment": assessments[i - 1],
             "evidence": [evidence[i - 1]], "risk_flags": []}
            for i, name in enumerate(REQUIRED_LAYER_NAMES, 1)
        ],
    })
    return out


class GeminiFreeProvider:
    name = "gemini"
    def __init__(self, *, model="gemini-3.5-flash-lite", env_path=GEMINI_ENV,
                 proof_path=GEMINI_PROOF, transport=_post_json):
        self.model = model
        # systemd LoadCredential copies root-protected source files into a
        # private per-service path readable to DynamicUser without exposing home.
        # Never fall back to home if the credential directory is provided.
        credentials_dir = os.environ.get("CREDENTIALS_DIRECTORY", "").strip()
        if credentials_dir:
            sandbox = Path(credentials_dir)
            self.env_path = sandbox / "gemini.env" if Path(env_path) == GEMINI_ENV else Path(env_path)
            self.proof_path = sandbox / "gemini-free-tier.json" if Path(proof_path) == GEMINI_PROOF else Path(proof_path)
        else:
            self.env_path = Path(env_path)
            self.proof_path = Path(proof_path)
        self.transport = transport

    def analyze(self, snapshot, context: dict):
        key = _read_free_key(self.env_path, self.proof_path)
        response = self.transport(
            f"https://generativelanguage.googleapis.com/v1beta/models/{self.model}:generateContent",
            {"contents": [{"parts": [{"text": _compact_prompt(snapshot, context)}]}],
             "generationConfig": {"responseMimeType": "application/json", "responseJsonSchema": _compact_schema()}},
            timeout=70, headers={"x-goog-api-key": key},
        )
        try:
            text = response["candidates"][0]["content"]["parts"][0]["text"]
            return _expand_compact(json.loads(text), snapshot)
        except (KeyError, IndexError, TypeError, ValueError):
            raise DecisionValidationError("gemini_invalid_response") from None


class OllamaLocalProvider:
    name = "ollama"
    def __init__(self, model="qwen2.5:3b", url="http://127.0.0.1:11434/api/generate",
                 timeout=75, transport=_post_json):
        parsed = urlsplit(url)
        if parsed.scheme != "http" or parsed.hostname not in ("localhost", "127.0.0.1", "::1") or parsed.path != "/api/generate":
            raise ValueError("ollama_loopback_required")
        self.model, self.url, self.timeout, self.transport = model, url, timeout, transport

    def analyze(self, snapshot, context: dict):
        response = self.transport(
            self.url,
            {"model": self.model, "prompt": _compact_prompt(snapshot, context),
             "format": _compact_schema(), "stream": False,
             "options": {"temperature": 0, "num_predict": 1800, "num_ctx": 8192},
             "keep_alive": "2m"},
            timeout=self.timeout,
        )
        try:
            if not response.get("done") or response.get("done_reason") == "length":
                raise DecisionValidationError("ollama_truncated")
            return _expand_compact(json.loads(response["response"]), snapshot)
        except (ValueError, KeyError, TypeError):
            raise DecisionValidationError("ollama_invalid_response") from None


class ChatGPTNormalProvider:
    """Normal ChatGPT is interactive; no official autonomous browser bridge is assumed."""
    name = "chatgpt_normal"
    def __init__(self, callback=None):
        self.callback = callback

    def analyze(self, snapshot, context: dict):
        if self.callback is None:
            raise DecisionValidationError("chatgpt_normal_interactive_only")
        return self.callback(snapshot, context)


class CodexLastResortProvider:
    name = "codex"
    def __init__(self, codex_provider=None):
        self.codex_provider = codex_provider or CodexBridgeDecisionProvider(model="gpt-6-luna", effort="medium")

    def analyze(self, snapshot, context: dict):
        return self.codex_provider.analyze(snapshot, context)


class PriorityFallbackDecisionProvider:
    async_mode = True
    max_concurrency = 1
    max_decisions_per_cycle = 1
    provider_name = "PRIORITY_FALLBACK"

    def __init__(self, providers=None, context_builder=None, clock=time.monotonic):
        self.providers = list(providers) if providers is not None else [
            GeminiFreeProvider(), OllamaLocalProvider(), ChatGPTNormalProvider(), CodexLastResortProvider()
        ]
        names = tuple(provider.name for provider in self.providers)
        if names != PROVIDER_ORDER:
            raise ValueError("priority_order_must_be_gemini_ollama_chatgpt_normal_codex")
        self.context_builder = context_builder
        self._clock = clock
        self._lock = Lock()
        self._retry_after = {}
        # PAPER market polls every 5 seconds, but unattended Free-tier inference
        # must be bounded independently of the polling cadence (<=288/day here).
        self._minimum_analysis_spacing_seconds = 300.0
        self._next_analysis_not_before = 0.0
        self.last_successful_source = None
        self.last_failures = {}
        self.last_successful_at = None

    def claim_analysis_slot(self):
        """Atomically rate-limit every unattended analysis, across all fallbacks."""
        with self._lock:
            now = self._clock()
            if now < self._next_analysis_not_before:
                return False
            self._next_analysis_not_before = now + self._minimum_analysis_spacing_seconds
            return True

    def describe(self):
        with self._lock:
            return {
                "decision_provider_next_analysis_seconds": round(
                    max(0.0, self._next_analysis_not_before - self._clock()), 1
                ),
                "decision_provider_priority": list(PROVIDER_ORDER),
                "decision_provider_selected": self.last_successful_source,
                "decision_provider_last_success_at": self.last_successful_at,
                "decision_provider_failures": dict(self.last_failures),
            }

    def analyze(self, snapshot, context: dict | None = None) -> dict:
        context = self.context_builder.build(snapshot, context) if self.context_builder else (context or {})
        failures = {}
        for provider in self.providers:
            with self._lock:
                eligible_at = self._retry_after.get(provider.name, 0)
            if self._clock() < eligible_at:
                failures[provider.name] = "cooldown"
                continue
            try:
                raw = provider.analyze(snapshot, context)
                if not isinstance(raw, dict):
                    raise DecisionValidationError("invalid_result")
                # The model cannot override the source metadata or timestamp.
                if raw.get("market_snapshot_ts") != snapshot.timestamp.isoformat():
                    raise DecisionValidationError("market_snapshot_mismatch")
                if (datetime.now(timezone.utc) - snapshot.timestamp).total_seconds() > 120:
                    raise DecisionValidationError("stale_market_context")
                DecisionParser.parse(raw, datetime.now(timezone.utc))
            except Exception as exc:
                # Labels only: never leak messages from model, upstream or credentials.
                reason = str(exc) if isinstance(exc, DecisionValidationError) else type(exc).__name__
                if not re.fullmatch(r"[a-zA-Z0-9_.:-]{1,92}", reason):
                    reason = "invalid_response"
                failures[provider.name] = reason
                with self._lock:
                    self._retry_after[provider.name] = self._clock() + (
                        900 if provider.name == "ollama" and reason in {
                            "upstream_TimeoutError", "upstream_URLError", "ollama_truncated"
                        } else 300 if reason in {
                            "gemini_free_tier_not_verified", "chatgpt_normal_interactive_only",
                            "decision_bridge:paid_provider_disabled"
                        } else 60)
                continue
            raw["_provider_source"] = provider.name
            with self._lock:
                self.last_successful_source = provider.name
                self.last_successful_at = datetime.now(timezone.utc).isoformat()
                self.last_failures = dict(failures)
                self._retry_after.pop(provider.name, None)
            return raw
        with self._lock:
            self.last_failures = dict(failures)
        raise DecisionValidationError("fallback_exhausted:" + ",".join(
            f"{name}={failures.get(name,'unknown')}" for name in PROVIDER_ORDER
        ))
